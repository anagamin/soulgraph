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
    public function __construct(
        private EntityResolutionService $resolution,
        private EntitySignificanceService $significance,
    ) {}

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
            if (! $tempId) {
                continue;
            }

            $entity = $this->resolution->resolveOrCreate($message, $item);
            if (! $entity) {
                continue;
            }

            $tempMap[$tempId] = $entity->id;
            $createdEntities[] = $entity->load('versions');
        }

        foreach ($extraction->patterns as $index => $pattern) {
            if (! is_array($pattern) || $this->confidence($pattern) < $minConfidence) {
                continue;
            }

            $description = Arr::get($pattern, 'description');
            if (! is_string($description) || trim($description) === '') {
                continue;
            }

            $tempId = 'pattern_'.$index;
            $entity = $this->resolution->resolveOrCreate($message, [
                'temp_id' => $tempId,
                'type' => 'pattern',
                'layer' => 'sky',
                'label' => trim($description),
                'attributes' => ['summary' => trim($description)],
                'confidence' => $this->confidence($pattern),
            ]);

            if ($entity) {
                $tempMap[$tempId] = $entity->id;
                $createdEntities[] = $entity->load('versions');
            }
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

            $existing = Relation::where('user_id', $message->user_id)
                ->where('type', $relationType)
                ->where('source_entity_id', $sourceId)
                ->where('target_entity_id', $targetId)
                ->exists();

            if ($existing) {
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

        $this->applyUserStatedSignificance($message, $createdEntities);

        return [
            'entities' => $createdEntities,
            'relations' => $createdRelations,
        ];
    }

    /**
     * @param  list<Entity>  $entities
     */
    private function applyUserStatedSignificance(Message $message, array $entities): void
    {
        if ($message->role !== 'user') {
            return;
        }

        $rating = $this->significance->parseExplicitRating($message->content);
        if ($rating === null) {
            return;
        }

        $touched = collect($entities)->unique('id');

        EntityVersion::query()
            ->where('source_message_id', $message->id)
            ->with('entity')
            ->get()
            ->pluck('entity')
            ->filter()
            ->each(fn (Entity $entity) => $touched->push($entity));

        foreach ($touched->unique('id') as $entity) {
            $this->significance->persistSignificance(
                $entity,
                $rating,
                EntitySignificanceService::SOURCE_USER_STATED,
            );
        }
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
            Relation::firstOrCreate(
                [
                    'user_id' => $message->user_id,
                    'type' => 'evolves_into',
                    'source_entity_id' => $entity->id,
                    'target_entity_id' => $targetEntityId,
                ],
            );
        }
    }

    private function confidence(array $item): float
    {
        return (float) Arr::get($item, 'confidence', 0.5);
    }
}
