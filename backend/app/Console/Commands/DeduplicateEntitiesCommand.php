<?php

namespace App\Console\Commands;

use App\Application\Services\EntityDeduplicationService;
use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Models\Entity;
use App\Models\Relation;
use App\Models\User;
use Illuminate\Console\Command;

class DeduplicateEntitiesCommand extends Command
{
    protected $signature = 'soulgraph:deduplicate
                            {user? : User ID to deduplicate}
                            {--all : Run for all users}
                            {--dry-run : Preview without merging}
                            {--rebuild-graph : Rebuild Neo4j graph after deduplication}';

    protected $description = 'Deduplicate existing entities by normalized key, label similarity, and semantic embeddings';

    public function handle(EntityDeduplicationService $dedup, Neo4jClient $neo4j): int
    {
        $users = $this->resolveUsers();
        if ($users->isEmpty()) {
            $this->error('No users found. Pass user ID or use --all');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run — no changes will be persisted.');
        }

        $totalMerged = 0;
        $totalCandidates = 0;
        $totalBackfilled = 0;

        foreach ($users as $user) {
            $this->info("Deduplicating user {$user->id} ({$user->email})…");

            $stats = $dedup->deduplicateUser($user, $dryRun);
            $totalMerged += $stats['merged'];
            $totalCandidates += $stats['candidates'];
            $totalBackfilled += $stats['backfilled'];

            $this->line("  backfilled keys: {$stats['backfilled']}");
            $this->line("  merged: {$stats['merged']}");
            $this->line("  new candidates: {$stats['candidates']}");

            if (! $dryRun && $this->option('rebuild-graph')) {
                $entities = Entity::canonical()->where('user_id', $user->id)->with(['versions'])->get();
                $relations = Relation::where('user_id', $user->id)->with(['versions'])->get();
                $neo4j->rebuildUserGraph((string) $user->id, $entities->all(), $relations->all());
                $this->line('  Neo4j graph rebuilt');
            }
        }

        $this->newLine();
        $this->info("Done. Backfilled: {$totalBackfilled}, merged: {$totalMerged}, candidates: {$totalCandidates}");

        return self::SUCCESS;
    }

    private function resolveUsers()
    {
        if ($this->option('all')) {
            return User::query()->orderBy('id')->get();
        }

        $userId = $this->argument('user');
        if ($userId) {
            $user = User::find($userId);

            return $user ? collect([$user]) : collect();
        }

        return User::query()->orderBy('id')->get();
    }
}
