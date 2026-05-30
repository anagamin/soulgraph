<?php

namespace App\Jobs;

use App\Application\Services\AutobiographyGeneratorService;
use App\Models\Autobiography;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAutobiographyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public string $autobiographyId) {}

    public function handle(AutobiographyGeneratorService $generator): void
    {
        $autobiography = Autobiography::with('user')->findOrFail($this->autobiographyId);
        $user = $autobiography->user;
        if (! $user) {
            $autobiography->update(['status' => 'failed']);

            return;
        }

        try {
            $autobiography->update(['status' => 'processing']);

            $autobiography->update([
                'content' => $generator->generate($autobiography),
                'status' => 'completed',
            ]);
        } catch (\Throwable $e) {
            Log::error('Autobiography generation failed', [
                'autobiography_id' => $this->autobiographyId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            $autobiography->update(['status' => 'failed']);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Autobiography::where('id', $this->autobiographyId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed']);
    }
}
