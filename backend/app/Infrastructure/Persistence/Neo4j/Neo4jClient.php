<?php

namespace App\Infrastructure\Persistence\Neo4j;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class Neo4jClient
{
    public function run(string $cypher, array $params = []): array
    {
        $uri = config('neo4j.uri');
        $database = config('neo4j.database');

        $response = Http::withBasicAuth(config('neo4j.username'), config('neo4j.password'))
            ->post($this->httpEndpoint($uri).'/db/'.$database.'/tx/commit', [
                'statements' => [['statement' => $cypher, 'parameters' => $params]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Neo4j query failed: '.$response->body());
        }

        return $response->json();
    }

    public function upsertEntityNode(array $data): void
    {
        $this->run(
            'MERGE (e:Entity {mysql_id: $mysql_id})
             SET e.user_id = $user_id, e.type = $type, e.layer = $layer,
                 e.label = $label, e.confidence = $confidence, e.active = $active,
                 e.updated_at = datetime()',
            $data,
        );
    }

    public function upsertRelation(array $data): void
    {
        $this->run(
            'MATCH (a:Entity {mysql_id: $source_id}), (b:Entity {mysql_id: $target_id})
             MERGE (a)-[r:REL {mysql_id: $mysql_id}]->(b)
             SET r.type = $type, r.confidence = $confidence, r.active = $active',
            $data,
        );
    }

    public function getContextSnippet(string $userId, int $limit = 20): string
    {
        try {
            $result = $this->run(
                'MATCH (e:Entity {user_id: $user_id, active: true})
                 OPTIONAL MATCH (e)-[r:REL {active: true}]->(t:Entity)
                 RETURN e.label AS label, e.type AS type, e.layer AS layer,
                        collect(DISTINCT r.type + " -> " + t.label)[0..5] AS rels
                 LIMIT $limit',
                ['user_id' => $userId, 'limit' => $limit],
            );

            $lines = [];
            foreach ($result['results'][0]['data'] ?? [] as $row) {
                $row = $row['row'] ?? [];
                $lines[] = ($row[0] ?? '').' ['.($row[2] ?? '').'/'.($row[1] ?? '').']';
            }

            return implode("\n", $lines);
        } catch (\Throwable) {
            return '';
        }
    }

    public function rebuildUserGraph(string $userId, array $entities, array $relations): void
    {
        $this->run('MATCH (e:Entity {user_id: $user_id}) DETACH DELETE e', ['user_id' => $userId]);

        foreach ($entities as $entity) {
            $version = $entity->versions->where('is_active', true)->first();
            $this->upsertEntityNode([
                'mysql_id' => $entity->id,
                'user_id' => (string) $userId,
                'type' => $entity->type,
                'layer' => $entity->layer,
                'label' => $entity->canonical_label,
                'confidence' => $version?->confidence ?? 0.5,
                'active' => true,
            ]);
        }

        foreach ($relations as $relation) {
            $version = $relation->versions->where('is_active', true)->first();
            $this->upsertRelation([
                'mysql_id' => $relation->id,
                'source_id' => $relation->source_entity_id,
                'target_id' => $relation->target_entity_id,
                'type' => $relation->type,
                'confidence' => $version?->confidence ?? 0.5,
                'active' => true,
            ]);
        }
    }

    public function getSkyGraph(string $userId): array
    {
        try {
            $result = $this->run(
                'MATCH (e:Entity {user_id: $user_id})
                 OPTIONAL MATCH (e)-[r:REL]->(t:Entity {user_id: $user_id})
                 RETURN e.mysql_id AS id, e.label AS label, e.type AS type, e.layer AS layer,
                        e.confidence AS confidence,
                        collect({target: t.mysql_id, type: r.type, confidence: r.confidence}) AS edges',
                ['user_id' => $userId],
            );

            $nodes = [];
            $edges = [];
            foreach ($result['results'][0]['data'] ?? [] as $row) {
                $row = $row['row'] ?? [];
                if (! $row[0]) {
                    continue;
                }
                $nodes[] = [
                    'id' => $row[0],
                    'label' => $row[1],
                    'type' => $row[2],
                    'layer' => $row[3],
                    'confidence' => $row[4],
                ];
                foreach ($row[5] ?? [] as $edge) {
                    if (! empty($edge['target'])) {
                        $edges[] = [
                            'source' => $row[0],
                            'target' => $edge['target'],
                            'type' => $edge['type'],
                            'confidence' => $edge['confidence'],
                        ];
                    }
                }
            }

            return ['nodes' => $nodes, 'edges' => $edges];
        } catch (\Throwable) {
            return ['nodes' => [], 'edges' => []];
        }
    }

    private function httpEndpoint(string $boltUri): string
    {
        $host = parse_url(str_replace('bolt://', 'http://', $boltUri), PHP_URL_HOST) ?: 'localhost';

        return 'http://'.$host.':7474';
    }
}
