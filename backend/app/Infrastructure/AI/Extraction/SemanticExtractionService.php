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
        $prompt = "Извлеки семантические сущности и связи из сообщения пользователя:\n\n{$content}";
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
