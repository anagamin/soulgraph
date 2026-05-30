<?php

namespace App\Application\Services;

use App\Jobs\GenerateAutobiographyBatchJob;
use App\Jobs\GenerateAutobiographyOutlineJob;
use App\Jobs\MergeAutobiographyJob;
use App\Models\Autobiography;
use Illuminate\Support\Facades\Log;

/**
 * Sequential pipeline: each step dispatches the next (no Bus::chain).
 */
class AutobiographyPipelineDispatcher
{
    public static function start(Autobiography $autobiography, string $runId): void
    {
        Log::info('Autobiography pipeline: dispatch outline', [
            'autobiography_id' => $autobiography->id,
            'run_id' => $runId,
        ]);

        GenerateAutobiographyOutlineJob::dispatch($autobiography->id, $runId);
    }

    public static function afterOutline(Autobiography $autobiography, string $runId): void
    {
        $meta = ($autobiography->scope_params ?? [])['generation_meta'] ?? [];
        $batchCount = (int) ($meta['batch_count'] ?? 0);

        if ($batchCount === 0) {
            self::startMerge($autobiography, $runId);

            return;
        }

        Log::info('Autobiography pipeline: dispatch batch 0', [
            'autobiography_id' => $autobiography->id,
            'run_id' => $runId,
            'batch_count' => $batchCount,
        ]);

        GenerateAutobiographyBatchJob::dispatch($autobiography->id, $runId, 0);
    }

    public static function afterBatch(Autobiography $autobiography, string $runId, int $batchIndex): void
    {
        $meta = ($autobiography->scope_params ?? [])['generation_meta'] ?? [];
        $batchCount = (int) ($meta['batch_count'] ?? 0);
        $next = $batchIndex + 1;

        if ($next < $batchCount) {
            Log::info('Autobiography pipeline: dispatch next batch', [
                'autobiography_id' => $autobiography->id,
                'run_id' => $runId,
                'batch_index' => $next,
            ]);
            GenerateAutobiographyBatchJob::dispatch($autobiography->id, $runId, $next);

            return;
        }

        self::startMerge($autobiography, $runId);
    }

    public static function startMerge(Autobiography $autobiography, string $runId): void
    {
        Log::info('Autobiography pipeline: dispatch merge', [
            'autobiography_id' => $autobiography->id,
            'run_id' => $runId,
        ]);

        MergeAutobiographyJob::dispatch($autobiography->id, $runId);
    }
}
