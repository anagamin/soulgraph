<?php

namespace App\Jobs;

use App\Application\Services\ExtractionNormalizationService;
use App\Infrastructure\AI\Extraction\SemanticExtractionService;
use App\Infrastructure\Logging\JobLogWriter;
use App\Infrastructure\Projection\EntityEmbeddingProjector;
use App\Infrastructure\Projection\Neo4jGraphProjector;
use App\Infrastructure\Projection\QdrantEmbeddingProjector;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class ProcessMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $messageId) {}

    public function handle(
        SemanticExtractionService $extraction,
        ExtractionNormalizationService $normalizer,
        Neo4jGraphProjector $graphProjector,
        QdrantEmbeddingProjector $embeddingProjector,
        EntityEmbeddingProjector $entityEmbeddingProjector,
        JobLogWriter $jobLog,
    ): void {
        $started = microtime(true);
        $message = Message::findOrFail($this->messageId);

        if ($message->processing_status === 'completed') {
            return;
        }

        try {
            $message->update(['processing_status' => 'processing']);

            $result = $extraction->extractFromMessage($message->content, $message->user_id);

            if (! Message::whereKey($message->id)->exists()) {
                $jobLog->log([
                    'user_id' => $message->user_id,
                    'job_class' => self::class,
                    'payload_summary' => ['message_id' => $message->id],
                    'status' => 'skipped',
                    'exception' => 'Source message was removed before extraction could be persisted.',
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                ]);

                return;
            }

            $normalized = $normalizer->normalize($message, $result);

            foreach ($normalized['entities'] as $entity) {
                $graphProjector->projectEntity($entity);
                $entityEmbeddingProjector->embedEntity($entity);
            }
            foreach ($normalized['relations'] as $relation) {
                $graphProjector->projectRelation($relation);
            }

            $embeddingProjector->embedMessage($message);

            $message->update([
                'processing_status' => 'completed',
                'reasoning_metadata' => [
                    'patterns' => $result->patterns,
                    'hypotheses' => $result->hypotheses,
                    'entities_count' => count($normalized['entities']),
                ],
            ]);

            $jobLog->log([
                'user_id' => $message->user_id,
                'job_class' => self::class,
                'payload_summary' => ['message_id' => $message->id],
                'status' => 'success',
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            $message->update(['processing_status' => 'failed']);
            $jobLog->log([
                'user_id' => $message->user_id,
                'job_class' => self::class,
                'status' => 'error',
                'exception' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }
}
