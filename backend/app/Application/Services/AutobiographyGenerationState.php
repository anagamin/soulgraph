<?php

namespace App\Application\Services;

use App\Models\Autobiography;

class AutobiographyGenerationState
{
    /**
     * @param  array{batches: list<list<string>>, labels: list<string>}  $plan
     */
    public static function init(Autobiography $autobiography, array $plan): void
    {
        $params = $autobiography->scope_params ?? [];
        $params['generation'] = [
            'batches' => $plan['batches'],
            'labels' => $plan['labels'],
            'outline' => null,
            'fragments' => [],
            'started_at' => now()->toIso8601String(),
        ];
        unset($params['generation_error']);

        $autobiography->update(['scope_params' => $params]);
    }

    /**
     * @return array{batches: list<list<string>>, labels: list<string>, outline: ?string, fragments: list<string>}|null
     */
    public static function read(Autobiography $autobiography): ?array
    {
        $generation = ($autobiography->scope_params ?? [])['generation'] ?? null;

        return is_array($generation) ? $generation : null;
    }

    public static function saveOutline(Autobiography $autobiography, string $outline): void
    {
        $params = $autobiography->scope_params ?? [];
        $generation = $params['generation'] ?? [];
        $generation['outline'] = $outline;
        $params['generation'] = $generation;

        $autobiography->update(['scope_params' => $params]);
    }

    public static function appendFragment(Autobiography $autobiography, int $batchIndex, string $fragment): void
    {
        $params = $autobiography->scope_params ?? [];
        $generation = $params['generation'] ?? [];
        $fragments = $generation['fragments'] ?? [];
        $fragments[$batchIndex] = $fragment;
        ksort($fragments);
        $generation['fragments'] = array_values($fragments);
        $params['generation'] = $generation;

        $autobiography->update(['scope_params' => $params]);
    }

    public static function fail(Autobiography $autobiography, string $message): void
    {
        $params = $autobiography->scope_params ?? [];
        $params['generation_error'] = mb_substr($message, 0, 2000);

        $autobiography->update([
            'status' => 'failed',
            'scope_params' => $params,
        ]);
    }

    public static function complete(Autobiography $autobiography, string $content): void
    {
        $params = $autobiography->scope_params ?? [];
        unset($params['generation'], $params['generation_error']);

        $autobiography->update([
            'content' => $content,
            'status' => 'completed',
            'scope_params' => $params,
        ]);
    }
}
