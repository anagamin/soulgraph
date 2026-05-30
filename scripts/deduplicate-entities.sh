#!/usr/bin/env bash
# Deduplicate SoulGraph entities.
# Usage:
#   ./scripts/deduplicate-entities.sh           # all users
#   ./scripts/deduplicate-entities.sh 1         # user ID 1
#   ./scripts/deduplicate-entities.sh --dry-run
#   ./scripts/deduplicate-entities.sh 1 --rebuild-graph

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/backend"

ARGS=(artisan soulgraph:deduplicate)
REBUILD=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) ARGS+=(--dry-run) ;;
    --rebuild-graph) REBUILD=1 ;;
    --all) ARGS+=(--all) ;;
    *) ARGS+=("$arg") ;;
  esac
done

if [[ ${#ARGS[@]} -eq 2 ]]; then
  ARGS+=(--all)
fi

if [[ $REBUILD -eq 1 ]]; then
  ARGS+=(--rebuild-graph)
fi

echo "Running: php ${ARGS[*]}"
php "${ARGS[@]}"
