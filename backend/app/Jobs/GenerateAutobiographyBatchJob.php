<?php

namespace App\Jobs;

use App\Application\Services\AutobiographyGenerationState;
use App\Application\Services\AutobiographyGeneratorService;
use App\Jobs\Concerns\ValidatesAutobiographyRun;
use App\Models\Autobiography;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAutobiographyBatchJob implements ShouldQueue
{
    use Queueable;
    use ValidatesAutobiographyRun;

    public int $timeout = 300;

    public function __construct(
        public string $autobiographyId,
        public string $runId,
        public int $batchIndex,
    ) {}

    public function handle(AutobiographyGeneratorService $generator): void
    {
        $autobiography = Autobiography::with('user')->findOrFail($this->autobiographyId);

        if ($this->skipUnlessActiveRun($autobiography, "batch:{$this->batchIndex}")) {
            return;
        }

        try {
            $fragment = $generator->generateBatch($autobiography, $this->batchIndex);
            AutobiographyGenerationState::appendFragment($autobiography, $this->batchIndex, $fragment);
        } catch (\Throwable $e) {
            $this->failGeneration($autobiography, $e);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $autobiography = Autobiography::find($this->autobiographyId);
        if ($autobiography && $exception && AutobiographyGenerationState::isActiveRun($autobiography, $this->runId)) {
            $this->failGeneration($autobiography, $exception);
        }
    }

    private function failGeneration(Autobiography $autobiography, \Throwable $e): void
    {
        AutobiographyGenerationState::fail(
            $autobiography,
            "Фрагмент {$this->batchIndex}: ".$e->getMessage(),
        );
        Log::error('Autobiography batch step failed', [
            'autobiography_id' => $this->autobiographyId,
            'run_id' => $this->runId,
            'batch_index' => $this->batchIndex,
            'message' => $e->getMessage(),
        ]);
    }
}
