<?php

namespace App\Application\Services;

use App\Models\Entity;
use App\Models\EntityVersion;
use App\Models\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class EntityImportanceScorer
{
    /** @var array<string, float> */
    private const TYPE_WEIGHT = [
        'event' => 1.0,
        'epoch' => 0.95,
        'person' => 0.9,
        'relationship' => 0.75,
        'emotion' => 0.7,
        'interpretation' => 0.65,
        'identity' => 0.85,
        'pattern' => 0.8,
        'belief' => 0.75,
        'place' => 0.6,
    ];

    public function __construct(
        private EntitySignificanceService $significance,
    ) {}

    public static function typeWeight(string $type): float
    {
        return self::TYPE_WEIGHT[$type] ?? 0.5;
    }

    /**
     * @return Collection<int, array{entity: Entity, score: float}>
     */
    public function rankForUser(int $userId, ?string $scope = 'full'): Collection
    {
        $entities = Entity::canonical()
            ->where('user_id', $userId)
            ->when($scope !== 'full', fn ($q) => $q->where('layer', $scope))
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get();

        if ($entities->isEmpty()) {
            return collect();
        }

        $entityIds = $entities->pluck('id');
        $relationCounts = $this->relationCounts($userId, $entityIds);
        $versionCounts = EntityVersion::query()
            ->whereIn('entity_id', $entityIds)
            ->selectRaw('entity_id, count(*) as cnt')
            ->groupBy('entity_id')
            ->pluck('cnt', 'entity_id');

        return $entities
            ->map(fn (Entity $entity) => [
                'entity' => $entity,
                'score' => $this->score(
                    $entity,
                    (int) ($relationCounts[$entity->id] ?? 0),
                    (int) ($versionCounts[$entity->id] ?? 1),
                ),
            ])
            ->sortByDesc('score')
            ->values();
    }

    public function score(Entity $entity, int $relationCount, int $versionCount): float
    {
        $version = $entity->versions->first();
        $payload = $version?->payload ?? [];
        $confidence = (float) ($version?->confidence ?? 0.5);

        $lifeSignificance = Arr::get($payload, 'life_significance');
        $significance = is_numeric($lifeSignificance)
            ? max(0.0, min(1.0, (float) $lifeSignificance))
            : $this->significance->inferFromContent($entity, $payload);

        $relationScore = min(1.0, $relationCount / 5);
        $mentionScore = min(1.0, max(1, $versionCount) / 4);
        $typeScore = self::typeWeight($entity->type);

        return round(
            0.25 * $confidence
            + 0.25 * $significance
            + 0.20 * $relationScore
            + 0.15 * $mentionScore
            + 0.15 * $typeScore,
            4,
        );
    }

    /**
     * @param  Collection<int, string>  $entityIds
     * @return array<string, int>
     */
    private function relationCounts(int $userId, Collection $entityIds): array
    {
        $counts = [];

        Relation::where('user_id', $userId)
            ->where(fn ($q) => $q
                ->whereIn('source_entity_id', $entityIds)
                ->orWhereIn('target_entity_id', $entityIds))
            ->get(['source_entity_id', 'target_entity_id'])
            ->each(function (Relation $relation) use ($entityIds, &$counts) {
                if ($entityIds->contains($relation->source_entity_id)) {
                    $counts[$relation->source_entity_id] = ($counts[$relation->source_entity_id] ?? 0) + 1;
                }
                if ($entityIds->contains($relation->target_entity_id)) {
                    $counts[$relation->target_entity_id] = ($counts[$relation->target_entity_id] ?? 0) + 1;
                }
            });

        return $counts;
    }
}
