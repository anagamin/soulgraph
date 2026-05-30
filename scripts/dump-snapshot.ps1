# Full SoulGraph snapshot: MySQL + Neo4j + Qdrant
# Usage: .\scripts\dump-snapshot.ps1 [optional-path]

$ErrorActionPreference = "Stop"
$Backend = Join-Path $PSScriptRoot "..\backend"
$Path = $args[0]

Push-Location $Backend
try {
    if ($Path) {
        php artisan soulgraph:dump-snapshot $Path
    } else {
        php artisan soulgraph:dump-snapshot
    }
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
