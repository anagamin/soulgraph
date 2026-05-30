# Restore SoulGraph from snapshot (destructive)
# Usage: .\scripts\restore-snapshot.ps1 <snapshot-path> [-Force]

param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$SnapshotPath,

    [switch]$Force
)

$ErrorActionPreference = "Stop"
$Backend = Join-Path $PSScriptRoot "..\backend"

Push-Location $Backend
try {
    if ($Force) {
        php artisan soulgraph:restore-snapshot $SnapshotPath --force
    } else {
        php artisan soulgraph:restore-snapshot $SnapshotPath
    }
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
