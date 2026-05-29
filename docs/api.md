# API v1

Base: `/api/v1`

## Auth

- `POST /register`, `POST /login`, `POST /logout`, `GET /user`
- `POST /forgot-password` (placeholder)

## Interview

- `GET|POST /interview/sessions`
- `POST /interview/sessions/{id}/messages`
- `POST /interview/sessions/{id}/messages/stream`
- `GET /interview/sessions/{id}/extractions`

## Graph

- `GET /earth/timeline`
- `GET /human/bridge`
- `GET /sky/graph`, `GET /sky/patterns`

## Autobiography

- `POST /autobiographies/generate`
- `GET /autobiographies/{id}/export.md`

## Psychologist

- `GET|POST /psychologist/sessions`
- `POST /psychologist/sessions/{id}/messages`

## Debug

- `GET /debug/ai-logs`, `/jobs-logs`, `/projections`
- `POST /debug/rebuild-graph`
