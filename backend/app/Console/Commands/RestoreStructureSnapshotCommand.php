<?php

namespace App\Console\Commands;

use App\Application\Services\StructureSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RestoreStructureSnapshotCommand extends Command
{
    protected $signature = 'soulgraph:restore-snapshot
                            {path : Path to snapshot directory}
                            {--force : Skip confirmation}';

    protected $description = 'Restore SoulGraph structure from a snapshot (replaces MySQL, Neo4j, Qdrant data)';

    public function handle(StructureSnapshotService $snapshots): int
    {
        $path = $this->argument('path');

        if (! File::isFile("{$path}/manifest.json")) {
            $this->error("Not a valid snapshot (manifest.json missing): {$path}");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This will REPLACE all SoulGraph data in MySQL, Neo4j and Qdrant. Continue?',
            false,
        )) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $this->warn('Restoring snapshot...');

        try {
            $snapshots->restore($path);
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Snapshot restored successfully.');
        $this->line('Tip: re-login if sessions were cleared; run queue:work if using database queue.');

        return self::SUCCESS;
    }
}
