<?php

namespace App\Application\Services;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ChatOptions;
use App\Models\Autobiography;
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

        $runId = AutobiographyGenerationState::init($autobiography, [
            'batches' => $batches,
            'labels' => $plan['labels'],
        ]);

        AutobiographyPipelineDispatcher::start($autobiography, $runId);
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
            throw new \RuntimeException(
                'Нет состояния генерации (кэш: '.config('cache.default')
                .', generation_meta в БД). Очистите очередь и перезапустите генерацию.',
            );
        }

        AutobiographyGenerationState::setStep($autobiography, 'outline:ai');
        AutobiographyGenerationState::logProgress($autobiography, 'Запрос плана к AI…');

        $outlineCtx = $this->context->assembleAutobiographyOutline(
            $autobiography->user,
            $this->planner->plan($autobiography->user, $autobiography->scope)['ranked'],
        );

        $messages = [
            [
                'role' => 'system',
                'content' => 'Ты составляешь оглавление автобиографии. Ответ обязательно непустой: только план глав на русском, без пустого сообщения.',
            ],
            [
                'role' => 'user',
                'content' => $this->outlinePrompt($autobiography, $state['labels'])."\n\n{$outlineCtx}",
            ],
        ];

        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $this->ai->chat(
                    $messages,
                    $this->chatOptions(temperature: $attempt === 1 ? 0.5 : 0.7, maxTokens: 4096),
                );
                $content = trim($response->content);
                if ($content !== '') {
                    return $content;
                }
                $lastError = 'Пустой content в ответе API';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('Autobiography outline attempt failed', [
                    'autobiography_id' => $autobiography->id,
                    'attempt' => $attempt,
                    'message' => $lastError,
                ]);
            }
        }

        Log::warning('Autobiography outline: using label fallback', [
            'autobiography_id' => $autobiography->id,
            'last_error' => $lastError,
        ]);
        AutobiographyGenerationState::logProgress(
            $autobiography,
            'План из AI пуст — собран резервный план по списку тем.',
        );

        return $this->fallbackOutline($state['labels']);
    }

    public function generateBatch(Autobiography $autobiography, int $batchIndex): string
    {
        $state = AutobiographyGenerationState::read($autobiography->fresh());
        if (! $state || ! isset($state['batches'][$batchIndex])) {
            $step = ($autobiography->scope_params ?? [])['generation_step'] ?? 'unknown';
            throw new \RuntimeException(
                "Нет метаданных для фрагмента {$batchIndex} (шаг: {$step}). Перезапустите генерацию.",
            );
        }

        if (empty($state['outline'])) {
            $step = ($autobiography->scope_params ?? [])['generation_step'] ?? 'unknown';
            throw new \RuntimeException(
                "План ещё не готов для фрагмента {$batchIndex} (шаг: {$step}). "
                .'Возможно, шаг «План» завершился с ошибкой — смотрите generation_error.',
            );
        }

        $entityIds = $state['batches'][$batchIndex];
        $batchCtx = $this->context->assembleEntityBatch($autobiography->user, $entityIds);
        $total = count($state['batches']);
        $outlineExcerpt = $this->excerpt($state['outline']);

        AutobiographyGenerationState::logProgress(
            $autobiography,
            "Фрагмент ".($batchIndex + 1)."/{$total}: запрос к AI…",
        );

        $response = $this->ai->chat(
            [['role' => 'user', 'content' => $this->batchPrompt(
                $autobiography,
                $outlineExcerpt,
                $batchIndex + 1,
                $total,
            )."\n\n{$batchCtx}"]],
            $this->chatOptions(temperature: 0.75, maxTokens: 2500),
        );

        return $response->content;
    }

    public function mergeFragments(Autobiography $autobiography): string
    {
        $fresh = $autobiography->fresh();
        $state = AutobiographyGenerationState::read($fresh);
        $step = ($fresh->scope_params ?? [])['generation_step'] ?? 'unknown';

        if (! $state || empty($state['outline'])) {
            throw new \RuntimeException("Generation state incomplete for merge (step: {$step}, outline missing).");
        }

        $batchCount = (int) ($state['batch_count'] ?? count($state['batches'] ?? []));
        $fragments = $state['fragments'] ?? [];

        if ($batchCount === 0 || $fragments === []) {
            throw new \RuntimeException("Generation state incomplete for merge (step: {$step}, fragments: ".count($fragments)."/{$batchCount}).");
        }

        if (count($fragments) < $batchCount) {
            throw new \RuntimeException(
                "Not all fragments saved ({$step}): ".count($fragments)." of {$batchCount}. "
                .'Проверьте CACHE_DRIVER (redis/file) и перезапустите генерацию.',
            );
        }

        try {
            return $this->mergeWithAi($autobiography, $state['outline'], $fragments);
        } catch (\Throwable $e) {
            Log::warning('Autobiography AI merge failed, using stitch fallback', [
                'autobiography_id' => $autobiography->id,
                'message' => $e->getMessage(),
            ]);

            return $this->stitchFragments($autobiography, $fragments);
        }
    }

    /**
     * @param  list<string>  $fragments
     */
    private function mergeWithAi(Autobiography $autobiography, string $outline, array $fragments): string
    {
        $response = $this->ai->chat(
            [['role' => 'user', 'content' => $this->mergePrompt(
                $autobiography,
                $this->excerpt($outline, 3000),
                $this->truncateFragments($fragments),
            )]],
            $this->chatOptions(
                temperature: 0.7,
                maxTokens: 6000,
                timeoutSeconds: (int) config('ai.autobiography.merge_timeout_seconds', 300),
            ),
        );

        return $response->content;
    }

    /**
     * @param  list<string>  $fragments
     */
    private function stitchFragments(Autobiography $autobiography, array $fragments): string
    {
        $parts = ["# {$autobiography->title}", ''];
        foreach ($fragments as $index => $fragment) {
            if ($index > 0) {
                $parts[] = '';
                $parts[] = '---';
                $parts[] = '';
            }
            $parts[] = trim($fragment);
        }

        return implode("\n", $parts);
    }

    /**
     * @param  list<string>  $fragments
     * @return list<string>
     */
    private function truncateFragments(array $fragments): array
    {
        $max = (int) config('ai.autobiography.merge_fragment_max_chars', 3500);

        return array_map(function (string $fragment) use ($max) {
            if (mb_strlen($fragment) <= $max) {
                return $fragment;
            }

            return mb_substr($fragment, 0, $max - 1).'…';
        }, $fragments);
    }

    private function excerpt(string $text, ?int $max = null): string
    {
        $max ??= (int) config('ai.autobiography.outline_excerpt_chars', 2000);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
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
    /**
     * @param  list<string>  $labels
     */
    private function fallbackOutline(array $labels): string
    {
        $lines = ['# План автобиографии', ''];
        foreach ($labels as $index => $label) {
            $lines[] = ($index + 1).'. '.$label.' — раскрыть по фактам из контекста.';
        }

        return implode("\n", $lines);
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

    private function batchPrompt(Autobiography $autobiography, string $outlineExcerpt, int $part, int $total): string
    {
        return "Напиши фрагмент {$part}/{$total} автобиографии (стиль «{$autobiography->style}») на русском.\n"
            ."Опирайся на план (возможно сокращён):\n{$outlineExcerpt}\n\n"
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

    private function chatOptions(
        float $temperature = 0.8,
        int $maxTokens = 4096,
        ?int $timeoutSeconds = null,
    ): ChatOptions {
        return new ChatOptions(
            temperature: $temperature,
            maxTokens: $maxTokens,
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
