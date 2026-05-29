<?php

namespace App\Jobs;

use App\Application\Services\ContextAssemblyService;
use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ChatOptions;
use App\Models\Autobiography;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAutobiographyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $autobiographyId) {}

    public function handle(AiProviderInterface $ai, ContextAssemblyService $context): void
    {
        $autobiography = Autobiography::with('user')->findOrFail($this->autobiographyId);
        $user = $autobiography->user;
        if (! $user) {
            $autobiography->update(['status' => 'failed']);

            return;
        }

        try {
            $autobiography->update(['status' => 'processing']);

            $ctx = $context->assembleForUser($user, $autobiography->title);
            $prompt = "Напиши автобиографию в стиле «{$autobiography->style}» на русском.\n"
                ."Область: {$autobiography->scope}\n\nКонтекст:\n{$ctx}";

            $response = $ai->chat(
                [['role' => 'user', 'content' => $prompt]],
                new ChatOptions(temperature: 0.8, maxTokens: 4096),
            );

            $autobiography->update([
                'content' => $response->content,
                'status' => 'completed',
            ]);
        } catch (\Throwable $e) {
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
