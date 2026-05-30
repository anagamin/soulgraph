<?php

namespace App\Application\Services;

use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Infrastructure\Persistence\Qdrant\QdrantClient;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ContextAssemblyService
{
    private const LAYER_ORDER = ['earth' => 1, 'human' => 2, 'sky' => 3];

    private const LAYER_TITLES = [
        'earth' => 'Земля (события, люди, места)',
        'human' => 'Человек (переживания, эмоции)',
        'sky' => 'Небо (смыслы, паттерны, убеждения)',
    ];

    public function __construct(
        private QdrantClient $qdrant,
        private Neo4jClient $neo4j,
    ) {}

    public function assembleForUser(User $user, string $query, int $limit = 8): string
    {
        $parts = [];

        $vectors = $this->qdrant->search($user->id, 'messages', $query, $limit);
        if ($vectors) {
            $parts[] = "=== Семантическая память ===\n".implode("\n---\n", $vectors);
        }

        $graphContext = $this->neo4j->getContextSnippet((string) $user->id, 20);
        if ($graphContext) {
            $parts[] = "=== Граф контекста ===\n{$graphContext}";
        }

        $entities = Entity::canonical()
            ->where('user_id', $user->id)
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->limit(15)
            ->get();

        if ($entities->isNotEmpty()) {
            $parts[] = "=== Активные сущности ===\n".$this->formatEntityLines($entities);
        }

        return implode("\n\n", $parts);
    }

    public function assembleForAutobiography(User $user, string $scope = 'full'): string
    {
        $parts = [];

        $entities = Entity::canonical()
            ->where('user_id', $user->id)
            ->when($scope !== 'full', fn ($q) => $q->where('layer', $scope))
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->sortBy([
                fn (Entity $e) => self::LAYER_ORDER[$e->layer] ?? 99,
                fn (Entity $e) => $this->temporalSortKey($e),
                fn (Entity $e) => $e->canonical_label,
            ])
            ->values();

        if ($entities->isNotEmpty()) {
            foreach (self::LAYER_ORDER as $layer => $_) {
                if ($scope !== 'full' && $scope !== $layer) {
                    continue;
                }

                $layerEntities = $entities->where('layer', $layer);
                if ($layerEntities->isEmpty()) {
                    continue;
                }

                $title = self::LAYER_TITLES[$layer] ?? $layer;
                $parts[] = "=== {$title} ===\n".$this->formatEntityLines($layerEntities);
            }

            $labels = $entities->pluck('canonical_label')->unique()->values();
            $parts[] = '=== Контрольный список тем ==='
                ."\nВ тексте должны быть отражены все пункты: ".$labels->implode('; ');
        }

        $graphContext = $this->neo4j->getContextSnippet((string) $user->id, limit: null);
        if ($graphContext) {
            $parts[] = "=== Связи между темами ===\n{$graphContext}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  Collection<int, Entity>  $entities
     */
    private function formatEntityLines(Collection $entities): string
    {
        return $entities
            ->map(function (Entity $e) {
                $v = $e->versions->first();
                $summary = $this->entitySummary($v?->payload ?? []);

                return "- [{$e->type}] {$e->canonical_label}".($summary ? ": {$summary}" : '');
            })
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function entitySummary(array $payload): ?string
    {
        if ($summary = Arr::get($payload, 'summary')) {
            return is_string($summary) ? $summary : null;
        }

        $parts = array_filter([
            Arr::get($payload, 'description'),
            Arr::get($payload, 'role'),
            Arr::get($payload, 'context'),
        ], fn ($v) => is_string($v) && $v !== '');

        return $parts ? implode(' ', $parts) : null;
    }

    private function temporalSortKey(Entity $entity): float
    {
        $payload = $entity->versions->first()?->payload ?? [];
        $approxYear = Arr::get($payload, 'approx_year');
        if (is_numeric($approxYear)) {
            return (float) $approxYear;
        }

        $lifePeriod = Arr::get($payload, 'life_period');
        if (is_string($lifePeriod) && $lifePeriod !== '') {
            return 1000 + crc32(mb_strtolower($lifePeriod)) % 100;
        }

        return PHP_FLOAT_MAX;
    }
}
