<?php

namespace App\Infrastructure\Projection;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\EmbedOptions;
use App\Infrastructure\Logging\ProjectionLogWriter;
use App\Infrastructure\Persistence\Qdrant\QdrantClient;
use App\Models\EmbeddingMetadata;
use App\Models\Entity;

class EntityEmbeddingProjector
{
    public function __construct(
        private AiProviderInterface $ai,
        private QdrantClient $qdrant,
        private ProjectionLogWriter $logger,
    ) {}

    public function embedEntity(Entity $entity): void
    {
        if (! $entity->isCanonical()) {
            return;
        }

        try {
            $version = $entity->activeVersion();
            $summary = is_array($version?->payload)
                ? ($version->payload['summary'] ?? '')
                : '';
            $text = trim("{$entity->type}: {$entity->canonical_label}. {$summary}");
            if ($text === '') {
                return;
            }

            $embed = $this->ai->embed($text, new EmbedOptions);
            $vector = $embed->vectors[0] ?? null;
            if (! $vector) {
                return;
            }

            $collection = $this->qdrant->userCollection($entity->user_id, 'entities');
            $existing = EmbeddingMetadata::query()
                ->where('user_id', $entity->user_id)
                ->where('source_type', 'entity')
                ->where('source_id', $entity->id)
                ->first();

            $pointId = $existing?->point_id ?? $this->qdrant->generatePointId();

            $this->qdrant->upsert($collection, $pointId, $vector, [
                'user_id' => $entity->user_id,
                'entity_id' => $entity->id,
                'type' => $entity->type,
                'layer' => $entity->layer,
                'label' => $entity->canonical_label,
            ]);

            if ($existing) {
                $existing->update(['model' => $embed->model]);
            } else {
                EmbeddingMetadata::create([
                    'user_id' => $entity->user_id,
                    'collection' => $collection,
                    'point_id' => $pointId,
                    'source_type' => 'entity',
                    'source_id' => $entity->id,
                    'model' => $embed->model,
                ]);
            }

            $this->logger->log([
                'user_id' => $entity->user_id,
                'target' => 'qdrant',
                'operation' => 'embed_entity',
                'entity_id' => $entity->id,
                'status' => 'success',
            ]);
        } catch (\Throwable $e) {
            $this->logger->log([
                'user_id' => $entity->user_id,
                'target' => 'qdrant',
                'operation' => 'embed_entity',
                'entity_id' => $entity->id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array{entity_id: string, score: float}>
     */
    public function searchSimilar(int $userId, string $text, string $type, string $layer, int $limit = 5): array
    {
        try {
            $embed = $this->ai->embed($text, new EmbedOptions);
            $vector = $embed->vectors[0] ?? null;
            if (! $vector) {
                return [];
            }

            $collection = $this->qdrant->userCollection($userId, 'entities');

            return $this->qdrant->searchByVector($collection, $vector, $limit, [
                'must' => [
                    ['key' => 'type', 'match' => ['value' => $type]],
                    ['key' => 'layer', 'match' => ['value' => $layer]],
                ],
            ]);
        } catch (\Throwable) {
            return [];
        }
    }
}
