<?php

namespace App\Application\Services;

use App\Models\Autobiography;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AutobiographyGenerationState
{
    private const TTL_SECONDS = 21600;

    /**
     * @param  array{batches: list<list<string>>, labels: list<string>}  $plan
     */
    public static function init(Autobiography $autobiography, array $plan): string
    {
        if (config('cache.default') === 'array') {
            throw new \RuntimeException(
                'Для multi-pass автобиографии нужен CACHE_STORE=file или redis (не array).',
            );
        }

        $runId = (string) Str::uuid();
        self::clearCache($autobiography->id);
        AutobiographyQueueCleanup::purgeForAutobiography($autobiography->id);

        $meta = [
            'run_id' => $runId,
            'batches' => $plan['batches'],
            'labels' => $plan['labels'],
            'batch_count' => count($plan['batches']),
            'started_at' => now()->toIso8601String(),
        ];

        Cache::put(self::metaKey($autobiography->id), $meta, self::TTL_SECONDS);

        $params = $autobiography->scope_params ?? [];
        $params['generation_run_id'] = $runId;
        $params['generation_meta'] = [
            'batches' => $plan['batches'],
            'labels' => $plan['labels'],
            'batch_count' => count($plan['batches']),
        ];
        self::applyStep($params, 'outline');
        unset($params['generation_error']);

        $autobiography->update(['scope_params' => $params]);

        return $runId;
    }

    public static function currentRunId(Autobiography $autobiography): ?string
    {
        $id = ($autobiography->scope_params ?? [])['generation_run_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function isActiveRun(Autobiography $autobiography, ?string $runId): bool
    {
        if ($runId === null || $runId === '') {
            return false;
        }

        if ($autobiography->status !== 'processing') {
            return false;
        }

        return self::currentRunId($autobiography) === $runId;
    }

    /**
     * @return array{
     *   run_id?: string,
     *   batches: list<list<string>>,
     *   labels: list<string>,
     *   batch_count: int,
     *   outline?: string,
     *   fragments?: list<string>
     * }|null
     */
    public static function read(Autobiography $autobiography): ?array
    {
        $meta = Cache::get(self::metaKey($autobiography->id));

        if (! is_array($meta)) {
            $stored = ($autobiography->scope_params ?? [])['generation_meta'] ?? null;
            $meta = is_array($stored) ? $stored : null;
        }

        if (! is_array($meta) || empty($meta['batches'])) {
            return null;
        }

        $outline = Cache::get(self::outlineKey($autobiography->id));
        if (is_string($outline) && trim($outline) !== '') {
            $meta['outline'] = $outline;
        }

        $batchCount = (int) ($meta['batch_count'] ?? count($meta['batches']));
        $fragments = self::readFragments($autobiography->id, $batchCount);
        if ($fragments !== []) {
            $meta['fragments'] = $fragments;
        }

        return $meta;
    }

    public static function saveOutline(Autobiography $autobiography, string $outline): void
    {
        $outline = trim($outline);
        if ($outline === '') {
            throw new \RuntimeException('План автобиографии пуст после сохранения.');
        }

        Cache::put(self::outlineKey($autobiography->id), $outline, self::TTL_SECONDS);
        self::setStep($autobiography, 'batches');
    }

    public static function appendFragment(Autobiography $autobiography, int $batchIndex, string $fragment): void
    {
        if (trim($fragment) === '') {
            throw new \RuntimeException("AI вернул пустой фрагмент {$batchIndex}.");
        }

        Cache::put(self::fragmentKey($autobiography->id, $batchIndex), $fragment, self::TTL_SECONDS);
        self::setStep($autobiography, "batch:{$batchIndex}");
    }

    /**
     * @return list<string>
     */
    public static function readFragments(string $autobiographyId, int $batchCount): array
    {
        $fragments = [];
        for ($i = 0; $i < $batchCount; $i++) {
            $fragment = Cache::get(self::fragmentKey($autobiographyId, $i));
            if (is_string($fragment) && trim($fragment) !== '') {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    public static function fail(Autobiography $autobiography, string $message): void
    {
        $fresh = $autobiography->fresh();
        $params = $fresh->scope_params ?? [];

        if ($fresh->status === 'failed' && ! empty($params['generation_error'])) {
            return;
        }

        $params['generation_error'] = mb_substr($message, 0, 2000);
        $params['generation_step'] = 'failed';

        $fresh->update([
            'status' => 'failed',
            'scope_params' => $params,
        ]);
    }

    public static function complete(Autobiography $autobiography, string $content): void
    {
        self::clearCache($autobiography->id);

        $params = $autobiography->scope_params ?? [];
        unset(
            $params['generation_step'],
            $params['generation_step_at'],
            $params['generation_error'],
            $params['generation_run_id'],
            $params['generation_meta'],
            $params['generation_log'],
        );

        $autobiography->update([
            'content' => $content,
            'status' => 'completed',
            'scope_params' => $params,
        ]);
    }

    public static function setStep(Autobiography $autobiography, string $step): void
    {
        $params = $autobiography->scope_params ?? [];
        self::applyStep($params, $step);
        $autobiography->update(['scope_params' => $params]);
    }

    public static function logProgress(Autobiography $autobiography, string $message): void
    {
        $params = $autobiography->scope_params ?? [];
        $log = $params['generation_log'] ?? [];
        $log[] = [
            'at' => now()->toIso8601String(),
            'message' => mb_substr($message, 0, 500),
        ];
        $params['generation_log'] = array_slice($log, -8);
        $autobiography->update(['scope_params' => $params]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function applyStep(array &$params, string $step): void
    {
        $params['generation_step'] = $step;
        $params['generation_step_at'] = now()->toIso8601String();
    }

    private static function clearCache(string $autobiographyId): void
    {
        $meta = Cache::get(self::metaKey($autobiographyId));
        $batchCount = is_array($meta) ? (int) ($meta['batch_count'] ?? 0) : 0;

        Cache::forget(self::metaKey($autobiographyId));
        Cache::forget(self::outlineKey($autobiographyId));

        for ($i = 0; $i < max($batchCount, 16); $i++) {
            Cache::forget(self::fragmentKey($autobiographyId, $i));
        }
    }

    private static function metaKey(string $id): string
    {
        return "autobiography-gen:{$id}:meta";
    }

    private static function outlineKey(string $id): string
    {
        return "autobiography-gen:{$id}:outline";
    }

    private static function fragmentKey(string $id, int $index): string
    {
        return "autobiography-gen:{$id}:fragment:{$index}";
    }
}
