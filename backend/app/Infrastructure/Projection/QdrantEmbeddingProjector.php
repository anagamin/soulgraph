<?php

namespace App\Infrastructure\Projection;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\EmbedOptions;
use App\Infrastructure\Logging\ProjectionLogWriter;
use App\Infrastructure\Persistence\Qdrant\QdrantClient;
use App\Models\EmbeddingMetadata;
use App\Models\Message;

class QdrantEmbeddingProjector
{
    public function __construct(
        private AiProviderInterface $ai,
        private QdrantClient $qdrant,
        private ProjectionLogWriter $logger,
    ) {}

    public function embedMessage(Message $message): void
    {
        try {
            $embed = $this->ai->embed($message->content, new EmbedOptions);
            $vector = $embed->vectors[0] ?? null;
            if (! $vector) {
                return;
            }

            $collection = $this->qdrant->userCollection($message->user_id, 'messages');
            $pointId = $this->qdrant->generatePointId();

            $this->qdrant->upsert($collection, $pointId, $vector, [
                'user_id' => $message->user_id,
                'message_id' => $message->id,
                'content' => mb_substr($message->content, 0, 500),
            ]);

            EmbeddingMetadata::create([
                'user_id' => $message->user_id,
                'collection' => $collection,
                'point_id' => $pointId,
                'source_type' => 'message',
                'source_id' => $message->id,
                'model' => $embed->model,
            ]);

            $this->logger->log([
                'user_id' => $message->user_id,
                'target' => 'qdrant',
                'operation' => 'embed_message',
                'status' => 'success',
                'metadata' => ['message_id' => $message->id],
            ]);
        } catch (\Throwable $e) {
            $this->logger->log([
                'user_id' => $message->user_id,
                'target' => 'qdrant',
                'operation' => 'embed_message',
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
