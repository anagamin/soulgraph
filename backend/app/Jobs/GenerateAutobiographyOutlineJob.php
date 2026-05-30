<?php

namespace App\Jobs;

use App\Application\Services\AutobiographyGenerationState;
use App\Application\Services\AutobiographyGeneratorService;
use App\Jobs\Concerns\ValidatesAutobiographyRun;
use App\Models\Autobiography;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAutobiographyOutlineJob implements ShouldQueue
{
    use Queueable;
    use ValidatesAutobiographyRun;

    public int $timeout = 300;

    public function __construct(
        public string $autobiographyId,
        public string $runId,
    ) {}

    public function handle(AutobiographyGeneratorService $generator): void
    {
        $autobiography = Autobiography::with('user')->findOrFail($this->autobiographyId);

        if ($this->skipUnlessActiveRun($autobiography, 'outline')) {
            return;
        }

        try {
            $outline = $generator->generateOutline($autobiography);
            AutobiographyGenerationState::saveOutline($autobiography, $outline);
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
        AutobiographyGenerationState::fail($autobiography, 'План: '.$e->getMessage());
        Log::error('Autobiography outline step failed', [
            'autobiography_id' => $this->autobiographyId,
            'run_id' => $this->runId,
            'message' => $e->getMessage(),
        ]);
    }
}
