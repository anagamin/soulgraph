<?php

namespace App\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AutobiographyQueueCleanup
{
    public static function purgeForAutobiography(string $autobiographyId): void
    {
        $removedPending = 0;
        $removedFailed = 0;

        if (Schema::hasTable('jobs')) {
            $removedPending = DB::table('jobs')
                ->where('payload', 'like', '%'.$autobiographyId.'%')
                ->delete();
        }

        if (Schema::hasTable('failed_jobs')) {
            $removedFailed = DB::table('failed_jobs')
                ->where('payload', 'like', '%'.$autobiographyId.'%')
                ->delete();
        }

        if (Schema::hasTable('job_batches')) {
            DB::table('job_batches')
                ->where(function ($query) use ($autobiographyId) {
                    $query->where('name', 'like', '%'.$autobiographyId.'%')
                        ->orWhere('failed_job_ids', 'like', '%'.$autobiographyId.'%');
                })
                ->delete();
        }

        if ($removedPending > 0 || $removedFailed > 0) {
            Log::info('Autobiography queue cleanup', [
                'autobiography_id' => $autobiographyId,
                'removed_pending' => $removedPending,
                'removed_failed' => $removedFailed,
            ]);
        }
    }
}
