<?php

namespace App\Console\Commands;

use App\Application\Services\StructureSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DumpStructureSnapshotCommand extends Command
{
    protected $signature = 'soulgraph:dump-snapshot
                            {path? : Directory for the snapshot (default: storage/snapshots/<timestamp>)}
                            {--force : Allow writing into a non-empty directory}';

    protected $description = 'Dump SoulGraph structure (MySQL, Neo4j, Qdrant) to a snapshot directory';

    public function handle(StructureSnapshotService $snapshots): int
    {
        $path = $this->argument('path')
            ?? storage_path('snapshots/'.now()->format('Y-m-d_His'));

        if (File::isDirectory($path) && ! $this->option('force') && count(File::allFiles($path)) > 0) {
            $this->error("Directory already exists and is not empty: {$path}");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($path);

        $this->info("Creating snapshot in {$path}...");

        try {
            $snapshots->dump($path);
        } catch (\Throwable $e) {
            $this->error('Snapshot failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $manifest = json_decode(File::get("{$path}/manifest.json"), true);
        $this->newLine();
        $this->info('Snapshot created.');
        $this->table(
            ['Store', 'Metric', 'Count'],
            [
                ['MySQL', 'tables', count($manifest['mysql'] ?? [])],
                ['Neo4j', 'nodes', $manifest['neo4j']['nodes'] ?? 0],
                ['Neo4j', 'edges', $manifest['neo4j']['edges'] ?? 0],
                ['Qdrant', 'collections', $manifest['qdrant']['collections'] ?? 0],
                ['Qdrant', 'points', $manifest['qdrant']['points'] ?? 0],
            ],
        );
        $this->line("Path: {$path}");

        return self::SUCCESS;
    }
}
