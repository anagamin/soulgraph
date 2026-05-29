<?php

namespace App\Application\Services;

use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Infrastructure\Persistence\Qdrant\QdrantClient;
use App\Models\Entity;
use App\Models\User;

class ContextAssemblyService
{
    public function __construct(
        private QdrantClient $qdrant,
        private Neo4jClient $neo4j,
    ) {}

    public function assembleForUser(User $user, string $query, int $limit = 8): string
    {
        $parts = [];

        $vectors = $this->qdrant->search($user->id, 'messages', $query, $limit);
        if ($vectors) {
            $parts[] = "=== Семантическая память ===\n".implode("\n---\n", $vectors);
        }

        $graphContext = $this->neo4j->getContextSnippet((string) $user->id, 20);
        if ($graphContext) {
            $parts[] = "=== Граф контекста ===\n{$graphContext}";
        }

        $entities = Entity::where('user_id', $user->id)
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->limit(15)
            ->get();

        if ($entities->isNotEmpty()) {
            $entityLines = $entities->map(function (Entity $e) {
                $v = $e->versions->first();
                $payload = $v ? json_encode($v->payload, JSON_UNESCAPED_UNICODE) : '{}';

                return "- [{$e->layer}/{$e->type}] {$e->canonical_label}: {$payload}";
            })->implode("\n");
            $parts[] = "=== Активные сущности ===\n{$entityLines}";
        }

        return implode("\n\n", $parts);
    }
}
