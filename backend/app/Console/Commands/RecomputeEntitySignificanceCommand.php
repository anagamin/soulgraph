<?php

namespace App\Console\Commands;

use App\Application\Services\EntityImportanceScorer;
use App\Application\Services\EntitySignificanceService;
use App\Models\Entity;
use App\Models\EntityVersion;
use App\Models\Relation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class RecomputeEntitySignificanceCommand extends Command
{
    protected $signature = 'soulgraph:recompute-significance
                            {--user= : Limit to a single user ID}
                            {--force : Overwrite existing computed/AI values (never user_stated)}';

    protected $description = 'Backfill life_significance on entity payloads from graph heuristics';

    public function handle(
        EntityImportanceScorer $scorer,
        EntitySignificanceService $significance,
    ): int {
        $userId = $this->option('user');
        $force = (bool) $this->option('force');

        $userQuery = User::query();
        if ($userId) {
            $userQuery->where('id', $userId);
        }

        $users = $userQuery->get();
        if ($users->isEmpty()) {
            $this->error('No users found.');

            return self::FAILURE;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $this->line("User {$user->id}");

            $ranked = $scorer->rankForUser($user->id);
            $entityIds = $ranked->pluck('entity.id');
            $relationCounts = $this->relationCounts($user->id, $entityIds);
            $versionCounts = EntityVersion::query()
                ->whereIn('entity_id', $entityIds)
                ->selectRaw('entity_id, count(*) as cnt')
                ->groupBy('entity_id')
                ->pluck('cnt', 'entity_id');

            foreach ($ranked as $item) {
                $entity = $item['entity'];
                $payload = $entity->versions->first()?->payload ?? [];

                if (Arr::get($payload, 'life_significance_source') === EntitySignificanceService::SOURCE_USER_STATED) {
                    $skipped++;

                    continue;
                }

                $hasValue = isset($payload['life_significance']) && is_numeric($payload['life_significance']);
                if ($hasValue && ! $force) {
                    $skipped++;

                    continue;
                }

                $heuristic = $significance->heuristicSignificance(
                    $entity,
                    (int) ($relationCounts[$entity->id] ?? 0),
                    (int) ($versionCounts[$entity->id] ?? 1),
                );

                if ($significance->persistSignificance($entity, $heuristic, EntitySignificanceService::SOURCE_COMPUTED)) {
                    $updated++;
                    $this->line("  ✓ {$entity->canonical_label} → {$heuristic}");
                } else {
                    $skipped++;
                }
            }
        }

        $this->info("Updated {$updated} entities, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, string>  $entityIds
     * @return array<string, int>
     */
    private function relationCounts(int $userId, Collection $entityIds): array
    {
        $counts = [];

        if ($entityIds->isEmpty()) {
            return $counts;
        }

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
