<?php

namespace App\Application\Services;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ChatOptions;
use App\Jobs\GenerateAutobiographyBatchJob;
use App\Jobs\GenerateAutobiographyOutlineJob;
use App\Jobs\MergeAutobiographyJob;
use App\Models\Autobiography;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class AutobiographyGeneratorService
{
    public function __construct(
        private AiProviderInterface $ai,
        private ContextAssemblyService $context,
        private AutobiographyPlanner $planner,
    ) {}

    public function shouldUseMultiPass(Autobiography $autobiography): bool
    {
        if (! config('ai.autobiography.multi_pass', true)) {
            return false;
        }

        $plan = $this->planner->plan($autobiography->user, $autobiography->scope);
        $threshold = (int) config('ai.autobiography.single_pass_max_entities', 12);

        return $plan['ranked']->count() > $threshold && $plan['batches'] !== [];
    }

    public function startMultiPassPipeline(Autobiography $autobiography): void
    {
        $plan = $this->planner->plan($autobiography->user, $autobiography->scope);
        $maxBatches = (int) config('ai.autobiography.max_batches', 12);
        $batches = array_slice($plan['batches'], 0, $maxBatches);

        AutobiographyGenerationState::init($autobiography, [
            'batches' => $batches,
            'labels' => $plan['labels'],
        ]);

        $jobs = [new GenerateAutobiographyOutlineJob($autobiography->id)];
        foreach (array_keys($batches) as $index) {
            $jobs[] = new GenerateAutobiographyBatchJob($autobiography->id, $index);
        }
        $jobs[] = new MergeAutobiographyJob($autobiography->id);

        $autobiographyId = $autobiography->id;

        Bus::chain($jobs)->catch(function (\Throwable $e) use ($autobiographyId) {
            $autobiography = Autobiography::find($autobiographyId);
            if ($autobiography) {
                AutobiographyGenerationState::fail($autobiography, $e->getMessage());
            }
            Log::error('Autobiography pipeline failed', [
                'autobiography_id' => $autobiographyId,
                'message' => $e->getMessage(),
            ]);
        })->dispatch();
    }

    public function generateSinglePass(Autobiography $autobiography): string
    {
        $ctx = $this->context->assembleForAutobiography($autobiography->user, $autobiography->scope);

        $response = $this->ai->chat(
            [['role' => 'user', 'content' => $this->basePrompt($autobiography)."\n\nКонтекст:\n{$ctx}"]],
            $this->chatOptions(),
        );

        return $response->content;
    }

    public function generateOutline(Autobiography $autobiography): string
    {
        $state = AutobiographyGenerationState::read($autobiography->fresh());
        if (! $state) {
            throw new \RuntimeException('Generation state missing for outline step.');
        }

        $outlineCtx = $this->context->assembleAutobiographyOutline(
            $autobiography->user,
            $this->planner->plan($autobiography->user, $autobiography->scope)['ranked'],
        );

        $response = $this->ai->chat(
            [['role' => 'user', 'content' => $this->outlinePrompt($autobiography, $state['labels'])."\n\n{$outlineCtx}"]],
            $this->chatOptions(temperature: 0.5, maxTokens: 2048),
        );

        return $response->content;
    }

    public function generateBatch(Autobiography $autobiography, int $batchIndex): string
    {
        $state = AutobiographyGenerationState::read($autobiography->fresh());
        if (! $state || ! isset($state['batches'][$batchIndex], $state['outline'])) {
            throw new \RuntimeException("Generation state incomplete for batch {$batchIndex}.");
        }

        $entityIds = $state['batches'][$batchIndex];
        $batchCtx = $this->context->assembleEntityBatch($autobiography->user, $entityIds);
        $total = count($state['batches']);

        $response = $this->ai->chat(
            [['role' => 'user', 'content' => $this->batchPrompt(
                $autobiography,
                $state['outline'],
                $batchIndex + 1,
                $total,
            )."\n\n{$batchCtx}"]],
            $this->chatOptions(temperature: 0.75, maxTokens: 2500),
        );

        return $response->content;
    }

    public function mergeFragments(Autobiography $autobiography): string
    {
        $state = AutobiographyGenerationState::read($autobiography->fresh());
        if (! $state || empty($state['outline']) || empty($state['fragments'])) {
            throw new \RuntimeException('Generation state incomplete for merge step.');
        }

        $response = $this->ai->chat(
            [['role' => 'user', 'content' => $this->mergePrompt(
                $autobiography,
                $state['outline'],
                $state['fragments'],
            )]],
            $this->chatOptions(temperature: 0.7, maxTokens: 6000),
        );

        return $response->content;
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
