# Deduplicate SoulGraph entities for one user or all users.
# Usage:
#   .\scripts\deduplicate-entities.ps1              # all users
#   .\scripts\deduplicate-entities.ps1 -UserId 1    # single user
#   .\scripts\deduplicate-entities.ps1 -DryRun      # preview only
#   .\scripts\deduplicate-entities.ps1 -RebuildGraph

param(
    [int]$UserId = 0,
    [switch]$All,
    [switch]$DryRun,
    [switch]$RebuildGraph
)

$ErrorActionPreference = "Stop"
$backend = Join-Path $PSScriptRoot ".." "backend"
Push-Location $backend

try {
    $args = @("artisan", "soulgraph:deduplicate")

    if ($All -or $UserId -eq 0) {
        $args += "--all"
    } else {
        $args += $UserId
    }

    if ($DryRun) { $args += "--dry-run" }
    if ($RebuildGraph) { $args += "--rebuild-graph" }

    Write-Host "Running: php $($args -join ' ')" -ForegroundColor Cyan
    & php @args
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
finally {
    Pop-Location
}
