<?php

namespace App\Application\Services;

use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Infrastructure\Persistence\Qdrant\QdrantClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class StructureSnapshotService
{
    public const VERSION = 1;

    /** @var list<string> */
    private const MYSQL_DUMP_TABLES = [
        'users',
        'interview_sessions',
        'psychologist_sessions',
        'messages',
        'entities',
        'entity_versions',
        'relations',
        'relation_versions',
        'autobiographies',
        'embeddings_metadata',
        'ai_logs',
        'jobs_logs',
        'graph_projection_logs',
    ];

    /** @var list<string> */
    private const MYSQL_TRUNCATE_ORDER = [
        'graph_projection_logs',
        'jobs_logs',
        'ai_logs',
        'embeddings_metadata',
        'autobiographies',
        'relation_versions',
        'relations',
        'entity_versions',
        'entities',
        'messages',
        'psychologist_sessions',
        'interview_sessions',
        'personal_access_tokens',
        'sessions',
        'failed_jobs',
        'jobs',
        'job_batches',
        'users',
    ];

    public function __construct(
        private Neo4jClient $neo4j,
        private QdrantClient $qdrant,
    ) {}

    public function dump(string $directory): string
    {
        File::ensureDirectoryExists($directory);

        $mysqlStats = [];
        foreach (self::MYSQL_DUMP_TABLES as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->values()->all();
            $path = "{$directory}/mysql/{$table}.json";
            File::ensureDirectoryExists("{$directory}/mysql");
            File::put($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $mysqlStats[$table] = count($rows);
        }

        $neo4j = $this->neo4j->exportGraph();
        File::put(
            "{$directory}/neo4j.json",
            json_encode($neo4j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        $qdrant = $this->qdrant->exportAllCollections();
        File::put(
            "{$directory}/qdrant.json",
            json_encode($qdrant, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        $manifest = [
            'version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'mysql' => $mysqlStats,
            'neo4j' => [
                'nodes' => count($neo4j['nodes'] ?? []),
                'edges' => count($neo4j['edges'] ?? []),
            ],
            'qdrant' => [
                'collections' => count($qdrant['collections'] ?? []),
                'points' => array_sum(array_map(
                    fn (array $c) => count($c['points'] ?? []),
                    $qdrant['collections'] ?? [],
                )),
            ],
        ];

        File::put(
            "{$directory}/manifest.json",
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        return $directory;
    }

    public function restore(string $directory): void
    {
        $manifestPath = "{$directory}/manifest.json";
        if (! File::isFile($manifestPath)) {
            throw new RuntimeException("Manifest not found: {$manifestPath}");
        }

        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Unsupported snapshot version: '.($manifest['version'] ?? 'unknown'));
        }

        DB::transaction(function () use ($directory) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach (self::MYSQL_TRUNCATE_ORDER as $table) {
                DB::table($table)->truncate();
            }

            foreach (self::MYSQL_DUMP_TABLES as $table) {
                $path = "{$directory}/mysql/{$table}.json";
                if (! File::isFile($path)) {
                    throw new RuntimeException("Missing MySQL dump: {$path}");
                }

                $rows = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
                if ($rows === []) {
                    continue;
                }

                foreach (array_chunk($this->normalizeRowsForInsert($rows), 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        });

        $neo4jPath = "{$directory}/neo4j.json";
        if (! File::isFile($neo4jPath)) {
            throw new RuntimeException("Missing Neo4j dump: {$neo4jPath}");
        }

        $neo4j = json_decode(File::get($neo4jPath), true, 512, JSON_THROW_ON_ERROR);
        $this->neo4j->importGraph($neo4j);

        $qdrantPath = "{$directory}/qdrant.json";
        if (! File::isFile($qdrantPath)) {
            throw new RuntimeException("Missing Qdrant dump: {$qdrantPath}");
        }

        $qdrant = json_decode(File::get($qdrantPath), true, 512, JSON_THROW_ON_ERROR);
        $this->qdrant->importCollections($qdrant);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeRowsForInsert(array $rows): array
    {
        return array_map(function (array $row) {
            foreach ($row as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $row[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }

            return $row;
        }, $rows);
    }
}
