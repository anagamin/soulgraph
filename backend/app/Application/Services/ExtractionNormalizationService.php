<?php

namespace App\Application\Services;

use App\Infrastructure\AI\DTOs\ExtractionResult;
use App\Models\Entity;
use App\Models\EntityVersion;
use App\Models\Message;
use App\Models\Relation;
use App\Models\RelationVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ExtractionNormalizationService
{
    /**
     * @return array{entities: array, relations: array}
     */
    public function normalize(Message $message, ExtractionResult $extraction): array
    {
        return DB::transaction(function () use ($message, $extraction) {
            return $this->persistExtraction($message, $extraction);
        });
    }

    /**
     * @return array{entities: array, relations: array}
     */
    private function persistExtraction(Message $message, ExtractionResult $extraction): array
    {
        $tempMap = [];
        $createdEntities = [];
        $minConfidence = config('ai.extraction.min_confidence', 0.3);

        foreach ($extraction->entities as $item) {
            if (! is_array($item) || $this->confidence($item) < $minConfidence) {
                continue;
            }

            $tempId = Arr::get($item, 'temp_id');
            $type = Arr::get($item, 'type');
            $layer = Arr::get($item, 'layer');
            $label = Arr::get($item, 'label');
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
                'payload' => Arr::get($item, 'attributes', ['label' => $label]),
                'confidence' => $this->confidence($item),
                'is_active' => true,
            ]);

            $tempMap[$tempId] = $entity->id;
            $createdEntities[] = $entity->load('versions');
        }

        $createdRelations = [];
        foreach ($extraction->relations as $item) {
            if (! is_array($item) || $this->confidence($item) < $minConfidence) {
                continue;
            }

            $from = Arr::get($item, 'from');
            $to = Arr::get($item, 'to');
            $relationType = Arr::get($item, 'type');
            if (! $from || ! $to || ! $relationType) {
                continue;
            }

            $sourceId = Arr::get($tempMap, $from);
            $targetId = Arr::get($tempMap, $to);
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
                'confidence' => $this->confidence($item),
                'is_active' => true,
            ]);

            $createdRelations[] = $relation;
        }

        foreach ($extraction->reinterpretations as $item) {
            if (! is_array($item)) {
                continue;
            }
            $this->handleReinterpretation($message, $item, $tempMap, $minConfidence);
        }

        return [
            'entities' => $createdEntities,
            'relations' => $createdRelations,
        ];
    }

    private function handleReinterpretation(
        Message $message,
        array $item,
        array $tempMap,
        float $minConfidence,
    ): void {
        $entityRef = Arr::get($item, 'entity_ref')
            ?: Arr::get($item, 'entity_id')
            ?: Arr::get($item, 'temp_id');
        $newMeaning = Arr::get($item, 'new_meaning') ?: Arr::get($item, 'meaning');

        if (! $entityRef || $newMeaning === null || $newMeaning === '') {
            return;
        }

        if ($this->confidence($item) < $minConfidence) {
            return;
        }

        $entityId = Arr::get($tempMap, $entityRef);
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
            'confidence' => $this->confidence($item),
            'is_active' => true,
        ]);

        $evolvesFrom = Arr::get($item, 'evolves_from_temp_id');
        $targetEntityId = $evolvesFrom ? Arr::get($tempMap, $evolvesFrom) : null;
        if ($targetEntityId) {
            Relation::create([
                'user_id' => $message->user_id,
                'type' => 'evolves_into',
                'source_entity_id' => $entity->id,
                'target_entity_id' => $targetEntityId,
            ]);
        }
    }

    private function confidence(array $item): float
    {
        return (float) Arr::get($item, 'confidence', 0.5);
    }
}
