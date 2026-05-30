<?php

namespace App\Jobs;

use App\Application\Services\AutobiographyGenerationState;
use App\Application\Services\AutobiographyGeneratorService;
use App\Jobs\Concerns\ValidatesAutobiographyRun;
use App\Models\Autobiography;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class MergeAutobiographyJob implements ShouldQueue
{
    use Queueable;
    use ValidatesAutobiographyRun;

    public int $timeout = 360;

    public function __construct(
        public string $autobiographyId,
        public string $runId,
    ) {}

    public function handle(AutobiographyGeneratorService $generator): void
    {
        $autobiography = Autobiography::with('user')->findOrFail($this->autobiographyId);

        if ($this->skipUnlessActiveRun($autobiography, 'merge')) {
            return;
        }

        try {
            $content = $generator->mergeFragments($autobiography);
            AutobiographyGenerationState::complete($autobiography, $content);
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
        AutobiographyGenerationState::fail($autobiography, 'Сборка: '.$e->getMessage());
        Log::error('Autobiography merge step failed', [
            'autobiography_id' => $this->autobiographyId,
            'run_id' => $this->runId,
            'message' => $e->getMessage(),
        ]);
    }
}
