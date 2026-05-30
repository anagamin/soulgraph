<?php

namespace App\Application\Services;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ChatOptions;
use App\Models\Autobiography;
use App\Models\User;

class AutobiographyGeneratorService
{
    public function __construct(
        private AiProviderInterface $ai,
        private ContextAssemblyService $context,
        private AutobiographyPlanner $planner,
    ) {}

    public function generate(Autobiography $autobiography): string
    {
        $user = $autobiography->user;
        $plan = $this->planner->plan($user, $autobiography->scope);
        $entityCount = $plan['ranked']->count();

        $useMultiPass = config('ai.autobiography.multi_pass', true)
            && $entityCount > (int) config('ai.autobiography.single_pass_max_entities', 12);

        if (! $useMultiPass || $plan['batches'] === []) {
            return $this->generateSinglePass($autobiography, $user);
        }

        return $this->generateMultiPass($autobiography, $user, $plan);
    }

    private function generateSinglePass(Autobiography $autobiography, User $user): string
    {
        $ctx = $this->context->assembleForAutobiography($user, $autobiography->scope);

        $response = $this->ai->chat(
            [['role' => 'user', 'content' => $this->basePrompt($autobiography)."\n\nКонтекст:\n{$ctx}"]],
            $this->chatOptions(),
        );

        return $response->content;
    }

    /**
     * @param  array{
     *   ranked: \Illuminate\Support\Collection,
     *   batches: list<list<string>>,
     *   labels: list<string>
     * }  $plan
     */
    private function generateMultiPass(Autobiography $autobiography, User $user, array $plan): string
    {
        $outlineCtx = $this->context->assembleAutobiographyOutline($user, $plan['ranked']);
        $outlineResponse = $this->ai->chat(
            [['role' => 'user', 'content' => $this->outlinePrompt($autobiography, $plan['labels'])."\n\n{$outlineCtx}"]],
            $this->chatOptions(temperature: 0.5, maxTokens: 2048),
        );
        $outline = $outlineResponse->content;

        $fragments = [];
        foreach ($plan['batches'] as $index => $entityIds) {
            $batchCtx = $this->context->assembleEntityBatch($user, $entityIds);
            $batchResponse = $this->ai->chat(
                [['role' => 'user', 'content' => $this->batchPrompt($autobiography, $outline, $index + 1, count($plan['batches']))."\n\n{$batchCtx}"]],
                $this->chatOptions(temperature: 0.75, maxTokens: 2500),
            );
            $fragments[] = $batchResponse->content;
        }

        $mergeResponse = $this->ai->chat(
            [['role' => 'user', 'content' => $this->mergePrompt($autobiography, $outline, $fragments)]],
            $this->chatOptions(temperature: 0.7, maxTokens: 6000),
        );

        return $mergeResponse->content;
    }

    private function basePrompt(Autobiography $autobiography): string
    {
        return "Напиши связную автобиографию в стиле «{$autobiography->style}» на русском.\n"
            ."Название: {$autobiography->title}\n"
            ."Область: {$autobiography->scope}\n\n"
            ."Требования:\n"
            ."- Освети ВСЕ темы из контекста, особенно из «Контрольный список тем».\n"
            ."- Сначала важные поворотные события, затем связанные детали.\n"
            ."- Выстраивай повествование хронологически, где это возможно.\n"
            ."- Не выдумывай факты, которых нет в контексте.";
    }

    /**
     * @param  list<string>  $labels
     */
    private function outlinePrompt(Autobiography $autobiography, array $labels): string
    {
        $labelList = implode('; ', $labels);

        return "Составь подробный план (оглавление) автобиографии в стиле «{$autobiography->style}» на русском.\n"
            ."Название: {$autobiography->title}\n\n"
            ."В плане должна быть отдельная глава или раздел для КАЖДОЙ темы: {$labelList}\n"
            ."Упорядочи главы хронологически, где возможно.\n"
            ."Для каждой главы — 1–2 предложения: что раскрыть.\n"
            ."Не пиши саму автобиографию, только план.";
    }

    private function batchPrompt(Autobiography $autobiography, string $outline, int $part, int $total): string
    {
        return "Напиши фрагмент {$part}/{$total} автобиографии (стиль «{$autobiography->style}») на русском.\n"
            ."Опирайся на план:\n{$outline}\n\n"
            ."Используй только факты из контекста ниже.\n"
            ."Раскрой все темы этого фрагмента подробно.\n"
            ."Фрагмент должен быть самодостаточным, но без повторного введения всей жизни целиком.";
    }

    /**
     * @param  list<string>  $fragments
     */
    private function mergePrompt(Autobiography $autobiography, string $outline, array $fragments): string
    {
        $joined = implode("\n\n---\n\n", $fragments);

        return "Объедини фрагменты в одну связную автобиографию (стиль «{$autobiography->style}») на русском.\n"
            ."Название: {$autobiography->title}\n\n"
            ."План (все темы обязательны):\n{$outline}\n\n"
            ."Требования:\n"
            ."- Сохрани все темы из плана; ничего не выбрасывай.\n"
            ."- Убери дословные повторы между фрагментами.\n"
            ."- Хронология и плавные переходы между главами.\n"
            ."- Не добавляй фактов вне фрагментов.\n\n"
            ."Фрагменты:\n{$joined}";
    }

    private function chatOptions(float $temperature = 0.8, int $maxTokens = 4096): ChatOptions
    {
        return new ChatOptions(
            temperature: $temperature,
            maxTokens: $maxTokens,
        );
    }
}
