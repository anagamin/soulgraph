<?php

namespace App\Jobs\Concerns;

use App\Application\Services\AutobiographyGenerationState;
use App\Models\Autobiography;
use Illuminate\Support\Facades\Log;

trait ValidatesAutobiographyRun
{
    protected function skipUnlessActiveRun(Autobiography $autobiography, string $step): bool
    {
        if (! AutobiographyGenerationState::isActiveRun($autobiography, $this->runId)) {
            Log::info("Autobiography {$step} skipped (stale or inactive run)", [
                'autobiography_id' => $autobiography->id,
                'job_run_id' => $this->runId,
                'current_run_id' => AutobiographyGenerationState::currentRunId($autobiography),
                'status' => $autobiography->status,
            ]);

            return true;
        }

        return false;
    }
}
