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
