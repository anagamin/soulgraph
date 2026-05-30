<?php

namespace App\Jobs;

use App\Application\Services\AutobiographyGenerationState;
use App\Application\Services\AutobiographyGeneratorService;
use App\Models\Autobiography;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAutobiographyBatchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public string $autobiographyId,
        public int $batchIndex,
    ) {}

    public function handle(AutobiographyGeneratorService $generator): void
    {
        $autobiography = Autobiography::with('user')->findOrFail($this->autobiographyId);

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
        if ($autobiography && $exception) {
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
            'batch_index' => $this->batchIndex,
            'message' => $e->getMessage(),
        ]);
    }
}
