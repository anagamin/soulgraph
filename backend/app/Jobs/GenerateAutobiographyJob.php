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

            $ctx = $context->assembleForAutobiography($user, $autobiography->scope);
            $prompt = "Напиши связную автобиографию в стиле «{$autobiography->style}» на русском.\n"
                ."Название: {$autobiography->title}\n"
                ."Область: {$autobiography->scope}\n\n"
                ."Требования:\n"
                ."- Освети ВСЕ темы из контекста, особенно из «Контрольный список тем»; не останавливайся на одной теме.\n"
                ."- Выстраивай повествование хронологически, где это возможно.\n"
                ."- Не выдумывай факты, которых нет в контексте.\n"
                ."- Значимые события (смерть близких, поворотные моменты) должны получить отдельное внимание.\n\n"
                ."Контекст:\n{$ctx}";

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
