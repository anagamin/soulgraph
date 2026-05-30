# SoulGraph Architecture

## Principle

MySQL is canonical. Neo4j and Qdrant are projections, rebuildable via `php artisan soulgraph:rebuild-graph {userId}`.

## Layers

- **Earth** — events, epochs, places, people
- **Human** — emotions, interpretations, motivations
- **Sky** — identity, fears, patterns, beliefs

## Message pipeline

1. Message stored in MySQL
2. `ProcessMessageJob` → extraction → normalization (versioned entities)
3. Neo4j projection + Qdrant embedding
4. Logs in `ai_logs`, `jobs_logs`, `graph_projection_logs`

## AI

`AiProviderInterface` → `GptunnelProvider` (OpenAI-compatible).

## Snapshots (rollback)

Before risky experiments, save a full slice (MySQL canonical data + Neo4j + Qdrant projections):

```bash
cd backend
php artisan soulgraph:dump-snapshot
# or: ../scripts/dump-snapshot.ps1
```

Restore (replaces all SoulGraph data in the three stores):

```bash
php artisan soulgraph:restore-snapshot storage/snapshots/2026-05-30_143022 --force
```

Snapshots live under `backend/storage/snapshots/` (gitignored).
