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
Слои (layer): {$layers}

Для каждой сущности: temp_id (e1, e2…), type, layer, label, attributes, confidence (0–1).
Связи: from/to — temp_id, type (например participated_in, felt, believes), confidence.

Сообщение:
{$content}
PROMPT;

        $schema = [
            'entities' => [['temp_id', 'type', 'layer', 'label', 'attributes', 'confidence']],
            'relations' => [['from', 'to', 'type', 'confidence']],
            'patterns' => [['description', 'confidence']],
            'hypotheses' => [['text', 'confidence']],
            'reinterpretations' => [['entity_ref', 'new_meaning', 'evolves_from_temp_id', 'confidence']],
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
