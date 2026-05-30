#!/usr/bin/env bash
# Restore SoulGraph from snapshot (destructive)
set -euo pipefail
if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <snapshot-path> [--force]" >&2
  exit 1
fi
cd "$(dirname "$0")/../backend"
path="$1"
shift
php artisan soulgraph:restore-snapshot "$path" "$@"
