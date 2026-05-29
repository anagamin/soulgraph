<?php

namespace App\Infrastructure\Projection;

use App\Infrastructure\Logging\ProjectionLogWriter;
use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Models\Entity;
use App\Models\Relation;

class Neo4jGraphProjector
{
    public function __construct(
        private Neo4jClient $neo4j,
        private ProjectionLogWriter $logger,
    ) {}

    public function projectEntity(Entity $entity): void
    {
        try {
            $version = $entity->versions()->where('is_active', true)->latest('valid_from')->first();
            $this->neo4j->upsertEntityNode([
                'mysql_id' => $entity->id,
                'user_id' => (string) $entity->user_id,
                'type' => $entity->type,
                'layer' => $entity->layer,
                'label' => $entity->canonical_label,
                'confidence' => $version?->confidence ?? 0.5,
                'active' => true,
            ]);
            $this->logger->log([
                'user_id' => $entity->user_id,
                'target' => 'neo4j',
                'operation' => 'upsert_entity',
                'entity_id' => $entity->id,
                'status' => 'success',
            ]);
        } catch (\Throwable $e) {
            $this->logger->log([
                'user_id' => $entity->user_id,
                'target' => 'neo4j',
                'operation' => 'upsert_entity',
                'entity_id' => $entity->id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function projectRelation(Relation $relation): void
    {
        try {
            $version = $relation->versions()->where('is_active', true)->latest('valid_from')->first();
            $this->neo4j->upsertRelation([
                'mysql_id' => $relation->id,
                'source_id' => $relation->source_entity_id,
                'target_id' => $relation->target_entity_id,
                'type' => $relation->type,
                'confidence' => $version?->confidence ?? 0.5,
                'active' => true,
            ]);
            $this->logger->log([
                'user_id' => $relation->user_id,
                'target' => 'neo4j',
                'operation' => 'upsert_relation',
                'relation_id' => $relation->id,
                'status' => 'success',
            ]);
        } catch (\Throwable $e) {
            $this->logger->log([
                'user_id' => $relation->user_id,
                'target' => 'neo4j',
                'operation' => 'upsert_relation',
                'relation_id' => $relation->id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
