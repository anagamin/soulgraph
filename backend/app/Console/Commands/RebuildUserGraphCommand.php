<?php

namespace App\Console\Commands;

use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Models\Entity;
use App\Models\Relation;
use App\Models\User;
use Illuminate\Console\Command;

class RebuildUserGraphCommand extends Command
{
    protected $signature = 'soulgraph:rebuild-graph {userId}';

    protected $description = 'Rebuild Neo4j projection from MySQL canonical data';

    public function handle(Neo4jClient $neo4j): int
    {
        $user = User::findOrFail($this->argument('userId'));

        $entities = Entity::where('user_id', $user->id)->with(['versions'])->get();
        $relations = Relation::where('user_id', $user->id)->with(['versions'])->get();

        $neo4j->rebuildUserGraph((string) $user->id, $entities->all(), $relations->all());

        $this->info("Graph rebuilt for user {$user->id}");

        return self::SUCCESS;
    }
}
