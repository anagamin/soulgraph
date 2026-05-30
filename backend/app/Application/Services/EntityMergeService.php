<?php

namespace App\Application\Services;

use App\Domain\Shared\EntityLabelNormalizer;
use App\Models\Entity;
use App\Models\EntityAlias;
use App\Models\EntityMerge;
use App\Models\EntityMergeCandidate;
use App\Models\Relation;
use Illuminate\Support\Facades\DB;

class EntityMergeService
{
    public function merge(
        Entity $canonical,
        Entity $duplicate,
        string $method,
        string $reason,
        ?float $confidence = null,
    ): Entity {
        if ($canonical->id === $duplicate->id) {
            return $canonical;
        }

        if ($canonical->user_id !== $duplicate->user_id) {
            throw new \InvalidArgumentException('Entities belong to different users');
        }

        if ($canonical->type !== $duplicate->type) {
            throw new \InvalidArgumentException('Entity types must match for merge');
        }

        if ($duplicate->merged_into_id !== null) {
            return $canonical;
        }

        return DB::transaction(function () use ($canonical, $duplicate, $method, $reason, $confidence) {
            $this->rewireRelations($canonical, $duplicate);
            $this->addAlias($canonical, $duplicate->canonical_label, 'merge');

            $duplicate->update(['merged_into_id' => $canonical->id]);

            EntityMerge::create([
                'user_id' => $canonical->user_id,
                'canonical_entity_id' => $canonical->id,
                'merged_entity_id' => $duplicate->id,
                'reason' => $reason,
                'confidence' => $confidence,
                'method' => $method,
            ]);

            EntityMergeCandidate::query()
                ->where(function ($q) use ($canonical, $duplicate) {
                    $q->where('entity_a_id', $canonical->id)->where('entity_b_id', $duplicate->id)
                        ->orWhere('entity_a_id', $duplicate->id)->where('entity_b_id', $canonical->id);
                })
                ->update(['status' => 'accepted']);

            return $canonical->fresh(['versions', 'aliases']);
        });
    }

    public function addAlias(Entity $entity, string $alias, string $source = 'extraction'): void
    {
        $normalized = EntityLabelNormalizer::normalize($alias);
        if ($normalized === '' || EntityLabelNormalizer::normalize($entity->canonical_label) === $normalized) {
            return;
        }

        EntityAlias::firstOrCreate(
            [
                'entity_id' => $entity->id,
                'normalized_alias' => $normalized,
            ],
            [
                'alias' => $alias,
                'source' => $source,
            ],
        );
    }

    public function recordCandidate(
        int $userId,
        Entity $entityA,
        Entity $entityB,
        float $similarity,
        string $method,
    ): ?EntityMergeCandidate {
        if ($entityA->id === $entityB->id) {
            return null;
        }

        [$aId, $bId] = $entityA->id < $entityB->id
            ? [$entityA->id, $entityB->id]
            : [$entityB->id, $entityA->id];

        return EntityMergeCandidate::firstOrCreate(
            [
                'entity_a_id' => $aId,
                'entity_b_id' => $bId,
            ],
            [
                'user_id' => $userId,
                'similarity' => $similarity,
                'method' => $method,
                'status' => 'pending',
            ],
        );
    }

    private function rewireRelations(Entity $canonical, Entity $duplicate): void
    {
        Relation::where('source_entity_id', $duplicate->id)->each(function (Relation $relation) use ($canonical, $duplicate) {
            if ($relation->target_entity_id === $canonical->id) {
                $relation->delete();

                return;
            }

            $existing = Relation::where('user_id', $relation->user_id)
                ->where('type', $relation->type)
                ->where('source_entity_id', $canonical->id)
                ->where('target_entity_id', $relation->target_entity_id)
                ->exists();

            if ($existing) {
                $relation->delete();
            } else {
                $relation->update(['source_entity_id' => $canonical->id]);
            }
        });

        Relation::where('target_entity_id', $duplicate->id)->each(function (Relation $relation) use ($canonical, $duplicate) {
            if ($relation->source_entity_id === $canonical->id) {
                $relation->delete();

                return;
            }

            $existing = Relation::where('user_id', $relation->user_id)
                ->where('type', $relation->type)
                ->where('source_entity_id', $relation->source_entity_id)
                ->where('target_entity_id', $canonical->id)
                ->exists();

            if ($existing) {
                $relation->delete();
            } else {
                $relation->update(['target_entity_id' => $canonical->id]);
            }
        });
    }
}
