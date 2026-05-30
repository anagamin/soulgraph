#!/usr/bin/env bash
# Full SoulGraph snapshot: MySQL + Neo4j + Qdrant
set -euo pipefail
cd "$(dirname "$0")/../backend"
if [[ $# -gt 0 ]]; then
  php artisan soulgraph:dump-snapshot "$1"
else
  php artisan soulgraph:dump-snapshot
fi
