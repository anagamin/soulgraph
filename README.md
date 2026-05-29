# SoulGraph

AI-powered autobiographical cognitive graph — monorepo.

## Stack

- **Backend:** Laravel 13, PHP 8.4+, Sanctum, Queues
- **Frontend:** Vue 3, Vite, TypeScript, Pinia, TailwindCSS
- **Data:** MySQL (canonical), Neo4j (graph), Qdrant (vectors), Redis

## Quick start

```bash
# Infrastructure
cd docker && docker compose up -d

# Backend
cd backend
cp .env.example .env   # or use root .env.example as reference
php artisan migrate
# При QUEUE_CONNECTION=database (см. .env) — в отдельном терминале:
php artisan queue:work
php artisan serve

# Frontend
cd frontend
npm install
npm run dev
```

- Landing: http://localhost:5173
- API: http://localhost:8000/api/v1

## Structure

```
backend/    Laravel API + jobs + AI orchestration
frontend/   Vue SPA
docker/     MySQL, Redis, Neo4j, Qdrant
docs/       Architecture notes
```
