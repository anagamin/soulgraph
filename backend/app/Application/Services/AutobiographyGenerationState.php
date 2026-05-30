<?php

namespace App\Application\Services;

use App\Models\Autobiography;
use Illuminate\Support\Facades\Cache;

class AutobiographyGenerationState
{
    private const TTL_SECONDS = 21600;

    /**
     * @param  array{batches: list<list<string>>, labels: list<string>}  $plan
     */
    public static function init(Autobiography $autobiography, array $plan): void
    {
        if (config('cache.default') === 'array') {
            throw new \RuntimeException(
                'Для multi-pass автобиографии нужен CACHE_DRIVER=file или redis (не array).',
            );
        }

        self::clearCache($autobiography->id);

        Cache::put(self::metaKey($autobiography->id), [
            'batches' => $plan['batches'],
            'labels' => $plan['labels'],
            'batch_count' => count($plan['batches']),
            'started_at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);

        $params = $autobiography->scope_params ?? [];
        $params['generation_step'] = 'outline';
        unset($params['generation_error']);
        $autobiography->update(['scope_params' => $params]);
    }

    /**
     * @return array{batches: list<list<string>>, labels: list<string>, batch_count: int, outline?: string}|null
     */
    public static function read(Autobiography $autobiography): ?array
    {
        $meta = Cache::get(self::metaKey($autobiography->id));
        if (! is_array($meta)) {
            return null;
        }

        $outline = Cache::get(self::outlineKey($autobiography->id));
        if (is_string($outline) && $outline !== '') {
            $meta['outline'] = $outline;
        }

        $batchCount = (int) ($meta['batch_count'] ?? count($meta['batches'] ?? []));
        $fragments = self::readFragments($autobiography->id, $batchCount);
        if ($fragments !== []) {
            $meta['fragments'] = $fragments;
        }

        return $meta;
    }

    public static function saveOutline(Autobiography $autobiography, string $outline): void
    {
        Cache::put(self::outlineKey($autobiography->id), $outline, self::TTL_SECONDS);
        self::setStep($autobiography, 'batches');
    }

    public static function appendFragment(Autobiography $autobiography, int $batchIndex, string $fragment): void
    {
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
            if (is_string($fragment) && $fragment !== '') {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    public static function fail(Autobiography $autobiography, string $message): void
    {
        $params = $autobiography->scope_params ?? [];
        $params['generation_error'] = mb_substr($message, 0, 2000);
        $params['generation_step'] = 'failed';

        $autobiography->update([
            'status' => 'failed',
            'scope_params' => $params,
        ]);
    }

    public static function complete(Autobiography $autobiography, string $content): void
    {
        self::clearCache($autobiography->id);

        $params = $autobiography->scope_params ?? [];
        unset($params['generation_step'], $params['generation_error']);

        $autobiography->update([
            'content' => $content,
            'status' => 'completed',
            'scope_params' => $params,
        ]);
    }

    private static function setStep(Autobiography $autobiography, string $step): void
    {
        $params = $autobiography->scope_params ?? [];
        $params['generation_step'] = $step;
        $autobiography->update(['scope_params' => $params]);
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
