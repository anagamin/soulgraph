<?php

namespace App\Application\Services;

use App\Infrastructure\AI\DTOs\ExtractionResult;
use App\Models\Entity;
use App\Models\EntityVersion;
use App\Models\Message;
use App\Models\Relation;
use App\Models\RelationVersion;
class ExtractionNormalizationService
{
    /**
     * @return array{entities: array, relations: array}
     */
    public function normalize(Message $message, ExtractionResult $extraction): array
    {
        $tempMap = [];
        $createdEntities = [];
        $minConfidence = config('ai.extraction.min_confidence', 0.3);

        foreach ($extraction->entities as $item) {
            if (! is_array($item) || ($item['confidence'] ?? 0) < $minConfidence) {
                continue;
            }

            $tempId = $item['temp_id'] ?? null;
            $type = $item['type'] ?? null;
            $layer = $item['layer'] ?? null;
            $label = $item['label'] ?? null;
            if (! $tempId || ! $type || ! $layer || ! $label) {
                continue;
            }

            $entity = Entity::create([
                'user_id' => $message->user_id,
                'type' => $type,
                'layer' => $layer,
                'canonical_label' => $label,
            ]);

            EntityVersion::create([
                'entity_id' => $entity->id,
                'source_message_id' => $message->id,
                'valid_from' => now(),
                'payload' => $item['attributes'] ?? ['label' => $label],
                'confidence' => $item['confidence'] ?? 0.5,
                'is_active' => true,
            ]);

            $tempMap[$tempId] = $entity->id;
            $createdEntities[] = $entity->load('versions');
        }

        $createdRelations = [];
        foreach ($extraction->relations as $item) {
            if (! is_array($item) || ($item['confidence'] ?? 0) < $minConfidence) {
                continue;
            }

            $from = $item['from'] ?? null;
            $to = $item['to'] ?? null;
            $relationType = $item['type'] ?? null;
            if (! $from || ! $to || ! $relationType) {
                continue;
            }

            $sourceId = $tempMap[$from] ?? null;
            $targetId = $tempMap[$to] ?? null;
            if (! $sourceId || ! $targetId) {
                continue;
            }

            $relation = Relation::create([
                'user_id' => $message->user_id,
                'type' => $relationType,
                'source_entity_id' => $sourceId,
                'target_entity_id' => $targetId,
            ]);

            RelationVersion::create([
                'relation_id' => $relation->id,
                'source_message_id' => $message->id,
                'valid_from' => now(),
                'confidence' => $item['confidence'] ?? 0.5,
                'is_active' => true,
            ]);

            $createdRelations[] = $relation;
        }

        foreach ($extraction->reinterpretations as $item) {
            $this->handleReinterpretation($message, $item, $tempMap);
        }

        return [
            'entities' => $createdEntities,
            'relations' => $createdRelations,
        ];
    }

    private function handleReinterpretation(Message $message, array $item, array $tempMap): void
    {
        $entityRef = $item['entity_ref'] ?? $item['entity_id'] ?? $item['temp_id'] ?? null;
        $newMeaning = $item['new_meaning'] ?? $item['meaning'] ?? null;
        if (! $entityRef || $newMeaning === null || $newMeaning === '') {
            return;
        }

        if (($item['confidence'] ?? 0) < config('ai.extraction.min_confidence', 0.3)) {
            return;
        }

        $entityId = $tempMap[$entityRef] ?? null;
        if (! $entityId) {
            return;
        }

        $entity = Entity::find($entityId);
        if (! $entity) {
            return;
        }

        $entity->versions()->where('is_active', true)->update([
            'is_active' => false,
            'valid_until' => now(),
        ]);

        EntityVersion::create([
            'entity_id' => $entity->id,
            'source_message_id' => $message->id,
            'valid_from' => now(),
            'payload' => ['meaning' => $newMeaning],
            'confidence' => $item['confidence'] ?? 0.5,
            'is_active' => true,
        ]);

        $evolvesFrom = $item['evolves_from_temp_id'] ?? null;
        if ($evolvesFrom && isset($tempMap[$evolvesFrom])) {
            Relation::create([
                'user_id' => $message->user_id,
                'type' => 'evolves_into',
                'source_entity_id' => $entity->id,
                'target_entity_id' => $tempMap[$evolvesFrom],
            ]);
        }
    }
}
