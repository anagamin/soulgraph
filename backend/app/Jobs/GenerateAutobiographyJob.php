<?php

namespace App\Jobs;

use App\Application\Services\AutobiographyGenerationState;
use App\Application\Services\AutobiographyGeneratorService;
use App\Models\Autobiography;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAutobiographyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 360;

    public int $tries = 1;

    public function __construct(public string $autobiographyId) {}

    public function handle(AutobiographyGeneratorService $generator): void
    {
        if (config('queue.default') === 'sync') {
            @set_time_limit(0);
        }

        $autobiography = Autobiography::with('user')->findOrFail($this->autobiographyId);
        $user = $autobiography->user;
        if (! $user) {
            $autobiography->update(['status' => 'failed']);

            return;
        }

        try {
            $autobiography->update(['status' => 'processing']);

            if ($generator->shouldUseMultiPass($autobiography)) {
                $generator->startMultiPassPipeline($autobiography->fresh());

                return;
            }

            $content = $generator->generateSinglePass($autobiography);
            AutobiographyGenerationState::complete($autobiography, $content);
        } catch (\Throwable $e) {
            AutobiographyGenerationState::fail($autobiography, $e->getMessage());
            Log::error('Autobiography generation failed', [
                'autobiography_id' => $this->autobiographyId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $autobiography = Autobiography::find($this->autobiographyId);
        if (! $autobiography) {
            return;
        }

        if ($exception) {
            AutobiographyGenerationState::fail($autobiography, $exception->getMessage());
            Log::error('Autobiography generation job failed', [
                'autobiography_id' => $this->autobiographyId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        } else {
            Autobiography::where('id', $this->autobiographyId)
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'failed']);
        }
    }
}
