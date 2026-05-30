<?php

namespace App\Infrastructure\AI\Extraction;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ExtractOptions;
use App\Infrastructure\AI\DTOs\ExtractionResult;
use App\Infrastructure\Logging\AiLogWriter;

class SemanticExtractionService
{
    public function __construct(
        private AiProviderInterface $ai,
        private AiLogWriter $logger,
    ) {}

    public function extractFromMessage(string $content, int $userId): ExtractionResult
    {
        $entityTypes = 'person, place, event, epoch, emotion, interpretation, motivation, fear, identity, pattern, belief, value, goal, practice, relationship';
        $layers = 'earth (facts: people, places, events), human (inner: emotions, motivations), sky (meaning: identity, beliefs, patterns)';

        $prompt = <<<PROMPT
Извлеки семантические сущности и связи из сообщения пользователя.
Верни хотя бы 1–3 сущности, если в тексте есть конкретика (люди, места, события, чувства, убеждения).

Типы сущностей: {$entityTypes}
Слои (layer): {$layers} — в поле layer только одно слово: earth, human или sky.

Для сущностей слоя earth (person, place, event, epoch, relationship) в attributes указывай:
- approx_year — примерный год (число), если упомянут или выводим из контекста
- occurred_at — ISO-дата (YYYY-MM-DD), если известна точная дата
- life_period — жизненный период («детство», «школа», «1990-е» и т.п.), если год неизвестен
- summary — 1–2 предложения: краткая сводка сущности из слов пользователя
- description, role, context — дополнительные детали по смыслу

Связи между людьми, местами и событиями: participated_in, located_in, involves, part_of.

Каждый элемент массивов entities, relations и т.д. — JSON-объект с именованными полями (не массив значений подряд).

Сообщение:
{$content}
PROMPT;

        $schema = [
            'entities' => [
                [
                    'temp_id' => 'e1',
                    'type' => 'person',
                    'layer' => 'earth',
                    'label' => 'string',
                    'attributes' => [
                        'approx_year' => 1995,
                        'life_period' => 'детство',
                        'summary' => 'string',
                    ],
                    'confidence' => 0.9,
                ],
            ],
            'relations' => [
                [
                    'from' => 'e1',
                    'to' => 'e2',
                    'type' => 'participated_in',
                    'confidence' => 0.8,
                ],
            ],
            'patterns' => [
                ['description' => 'string', 'confidence' => 0.7],
            ],
            'hypotheses' => [
                ['text' => 'string', 'confidence' => 0.6],
            ],
            'reinterpretations' => [
                [
                    'entity_ref' => 'e1',
                    'new_meaning' => 'string',
                    'evolves_from_temp_id' => null,
                    'confidence' => 0.5,
                ],
            ],
        ];

        $started = microtime(true);
        try {
            $raw = $this->ai->extract($prompt, $schema, new ExtractOptions);
            $this->logger->log([
                'user_id' => $userId,
                'operation' => 'semantic_extraction',
                'prompt_version' => config('ai.extraction.prompt_version'),
                'prompt' => $prompt,
                'response' => json_encode($raw, JSON_UNESCAPED_UNICODE),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'status' => 'success',
            ]);

            return ExtractionResult::fromArray($raw);
        } catch (\Throwable $e) {
            $this->logger->log([
                'user_id' => $userId,
                'operation' => 'semantic_extraction',
                'prompt_version' => config('ai.extraction.prompt_version'),
                'prompt' => $prompt,
                'status' => 'error',
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }
}
