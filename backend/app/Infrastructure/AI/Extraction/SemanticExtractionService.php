<?php

namespace App\Infrastructure\AI\Extraction;

use App\Application\Services\KnownEntitiesProvider;
use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ExtractOptions;
use App\Infrastructure\AI\DTOs\ExtractionResult;
use App\Infrastructure\Logging\AiLogWriter;

class SemanticExtractionService
{
    public function __construct(
        private AiProviderInterface $ai,
        private AiLogWriter $logger,
        private KnownEntitiesProvider $knownEntities,
    ) {}

    public function extractFromMessage(string $content, int $userId): ExtractionResult
    {
        $entityTypes = 'person, place, event, epoch, emotion, interpretation, motivation, fear, identity, pattern, belief, value, goal, practice, relationship';
        $layers = 'earth (facts: people, places, events), human (inner: emotions, motivations), sky (meaning: identity, beliefs, patterns)';
        $known = $this->knownEntities->formatForPrompt($userId);

        $prompt = <<<PROMPT
Извлеки семантические сущности и связи из сообщения пользователя.
Верни хотя бы 1–3 сущности, если в тексте есть конкретика (люди, места, события, чувства, убеждения).

Типы сущностей: {$entityTypes}
Слои (layer): {$layers} — в поле layer только одно слово: earth, human или sky.

=== Уже известные сущности пользователя ===
{$known}

Если новая сущность — это уже известная (другая формулировка, синоним, тот же человек/место/паттерн),
укажи match_entity_id (UUID из списка выше) и не создавай дубликат.
Если это новая сущность — match_entity_id = null.

Для сущностей слоя earth (person, place, event, epoch, relationship) в attributes указывай:
- approx_year — примерный год (число), если упомянут или выводим из контекста
- occurred_at — ISO-дата (YYYY-MM-DD), если известна точная дата
- life_period — жизненный период («детство», «школа», «1990-е» и т.п.), если год неизвестен
- summary — 1–2 предложения: краткая сводка сущности из слов пользователя
- life_significance — число 0.0–1.0: насколько тема важна для жизненной истории
- life_significance_source — "user_stated" если пользователь явно оценил важность; иначе "ai_inferred"
- description, role, context — дополнительные детали по смыслу

Шкала life_significance при явной оценке пользователя (0–10 или слова):
0–2 / «неважно» → 0.2; 3–4 → 0.4; 5–6 / «средне» → 0.6; 7–8 / «очень важно» → 0.85; 9–10 / «переломный момент» → 0.95.
Без явной оценки: смерти, потери, эмиграция ≈ 0.9; бытовые детали ≈ 0.3.

Для сущностей human и sky (emotion, identity, pattern, belief и т.д.) — тоже указывай life_significance, если тема звучит значимо.

Для паттернов (pattern), убеждений (belief), идентичностей (identity) — обязательно summary.

Связи между людьми, местами и событиями: participated_in, located_in, involves, part_of.

Каждый элемент массивов entities, relations и т.д. — JSON-объект с именованными полями (не массив значений подряд).

Сообщение:
{$content}
PROMPT;

        $schema = [
            'entities' => [
                [
                    'temp_id' => 'e1',
                    'match_entity_id' => null,
                    'type' => 'person',
                    'layer' => 'earth',
                    'label' => 'string',
                    'attributes' => [
                        'approx_year' => 1995,
                        'life_period' => 'детство',
                        'summary' => 'string',
                        'life_significance' => 0.85,
                        'life_significance_source' => 'ai_inferred',
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
