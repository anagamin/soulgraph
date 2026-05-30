<?php

namespace App\Application\Services;

use App\Models\Entity;
use App\Models\Relation;
use App\Models\User;
use Illuminate\Support\Collection;

class AutobiographyPlanner
{
    public function __construct(
        private EntityImportanceScorer $scorer,
    ) {}

    /**
     * @return array{
     *   ranked: Collection<int, array{entity: Entity, score: float}>,
     *   batches: list<list<string>>,
     *   labels: list<string>
     * }
     */
    public function plan(User $user, string $scope = 'full'): array
    {
        $ranked = $this->scorer->rankForUser($user->id, $scope);
        $batchSize = max(3, (int) config('ai.autobiography.batch_size', 8));
        $neighborLimit = (int) config('ai.autobiography.neighbors_per_seed', 4);

        $entityIds = $ranked->pluck('entity.id')->all();
        $scoreById = $ranked->mapWithKeys(fn (array $item) => [$item['entity']->id => $item['score']]);
        $adjacency = $this->buildAdjacency($user->id, $entityIds);
        $batches = $this->buildBatches($ranked, $adjacency, $scoreById, $batchSize, $neighborLimit);

        return [
            'ranked' => $ranked,
            'batches' => $batches,
            'labels' => $ranked->pluck('entity.canonical_label')->unique()->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, array{entity: Entity, score: float}>  $ranked
     * @param  array<string, list<string>>  $adjacency
     * @return list<list<string>>
     */
    /**
     * @param  Collection<string, float>  $scoreById
     */
    private function buildBatches(
        Collection $ranked,
        array $adjacency,
        Collection $scoreById,
        int $batchSize,
        int $neighborLimit,
    ): array {
        $assigned = [];
        $batches = [];

        foreach ($ranked as $item) {
            $seedId = $item['entity']->id;
            if (isset($assigned[$seedId])) {
                continue;
            }

            $batch = [$seedId];
            $assigned[$seedId] = true;

            $neighbors = collect($adjacency[$seedId] ?? [])
                ->reject(fn (string $id) => isset($assigned[$id]))
                ->sortByDesc(fn (string $id) => $scoreById[$id] ?? 0)
                ->take($neighborLimit);

            foreach ($neighbors as $neighborId) {
                if (count($batch) >= $batchSize) {
                    break;
                }
                $batch[] = $neighborId;
                $assigned[$neighborId] = true;
            }

            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @param  list<string>  $entityIds
     * @return array<string, list<string>>
     */
    private function buildAdjacency(int $userId, array $entityIds): array
    {
        $idSet = array_flip($entityIds);
        $adjacency = array_fill_keys($entityIds, []);

        Relation::where('user_id', $userId)
            ->whereIn('source_entity_id', $entityIds)
            ->whereIn('target_entity_id', $entityIds)
            ->get(['source_entity_id', 'target_entity_id'])
            ->each(function (Relation $relation) use (&$adjacency, $idSet) {
                $a = $relation->source_entity_id;
                $b = $relation->target_entity_id;
                if (! isset($idSet[$a], $idSet[$b]) || $a === $b) {
                    return;
                }
                $adjacency[$a][] = $b;
                $adjacency[$b][] = $a;
            });

        return $adjacency;
    }
}
