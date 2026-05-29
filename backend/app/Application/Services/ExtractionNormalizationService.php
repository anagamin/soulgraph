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
            if (($item['confidence'] ?? 0) < $minConfidence) {
                continue;
            }

            $entity = Entity::create([
                'user_id' => $message->user_id,
                'type' => $item['type'],
                'layer' => $item['layer'],
                'canonical_label' => $item['label'],
            ]);

            EntityVersion::create([
                'entity_id' => $entity->id,
                'source_message_id' => $message->id,
                'valid_from' => now(),
                'payload' => $item['attributes'] ?? ['label' => $item['label']],
                'confidence' => $item['confidence'] ?? 0.5,
                'is_active' => true,
            ]);

            $tempMap[$item['temp_id']] = $entity->id;
            $createdEntities[] = $entity->load('versions');
        }

        $createdRelations = [];
        foreach ($extraction->relations as $item) {
            if (($item['confidence'] ?? 0) < $minConfidence) {
                continue;
            }
            $sourceId = $tempMap[$item['from']] ?? null;
            $targetId = $tempMap[$item['to']] ?? null;
            if (! $sourceId || ! $targetId) {
                continue;
            }

            $relation = Relation::create([
                'user_id' => $message->user_id,
                'type' => $item['type'],
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
        $entityId = $tempMap[$item['entity_ref']] ?? null;
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

        $newVersion = EntityVersion::create([
            'entity_id' => $entity->id,
            'source_message_id' => $message->id,
            'valid_from' => now(),
            'payload' => ['meaning' => $item['new_meaning']],
            'confidence' => $item['confidence'] ?? 0.5,
            'is_active' => true,
        ]);

        if (! empty($item['evolves_from_temp_id']) && isset($tempMap[$item['evolves_from_temp_id']])) {
            Relation::create([
                'user_id' => $message->user_id,
                'type' => 'evolves_into',
                'source_entity_id' => $entity->id,
                'target_entity_id' => $tempMap[$item['evolves_from_temp_id']],
            ]);
        }

        unset($newVersion);
    }
}
