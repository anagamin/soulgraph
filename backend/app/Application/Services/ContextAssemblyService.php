<?php

namespace App\Application\Services;

use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Infrastructure\Persistence\Qdrant\QdrantClient;
use App\Models\Entity;
use App\Models\Relation;
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
        private EntityImportanceScorer $importance,
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
        $ranked = $this->importance->rankForUser($user->id, $scope);
        if ($ranked->isEmpty()) {
            return '';
        }

        $summaryMax = config('ai.autobiography.summary_max_chars', 400);
        $compactMax = config('ai.autobiography.compact_summary_max_chars', 120);
        $contextLimit = config('ai.autobiography.context_limit', 28000);
        $detailThreshold = (float) config('ai.autobiography.full_detail_min_score', 0.55);

        $labels = $ranked->pluck('entity.canonical_label')->unique()->values();
        $sections = [
            [
                'priority' => 0,
                'text' => '=== Контрольный список тем ==='
                    ."\nВ тексте должны быть отражены все пункты: ".$labels->implode('; '),
            ],
            [
                'priority' => 1,
                'text' => $this->formatRankedEntities($ranked, $detailThreshold, $summaryMax, $compactMax),
            ],
            [
                'priority' => 2,
                'text' => "=== Связи между темами ===\n".$this->formatRelations(
                    $user->id,
                    $ranked->pluck('entity.id')->all(),
                ),
            ],
        ];

        return $this->fitSectionsToLimit(array_filter($sections, fn (array $s) => $s['text'] !== ''), $contextLimit);
    }

    /**
     * @param  Collection<int, array{entity: Entity, score: float}>  $ranked
     */
    public function assembleAutobiographyOutline(User $user, Collection $ranked): string
    {
        $lines = $ranked->map(function (array $item) {
            $entity = $item['entity'];
            $score = $item['score'];
            $temporal = $this->temporalLabel($entity);

            return "- [важность {$score}] {$entity->canonical_label}".($temporal ? " ({$temporal})" : '');
        });

        return "=== Все темы (по убыванию важности) ===\n".$lines->implode("\n");
    }

    /**
     * @param  list<string>  $entityIds
     */
    public function assembleEntityBatch(User $user, array $entityIds): string
    {
        if ($entityIds === []) {
            return '';
        }

        $summaryMax = config('ai.autobiography.summary_max_chars', 500);
        $entities = Entity::canonical()
            ->where('user_id', $user->id)
            ->whereIn('id', $entityIds)
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->sortBy(fn (Entity $e) => array_search($e->id, $entityIds, true))
            ->values();

        $parts = [
            '=== Материал фрагмента ===',
            $this->formatEntityLines($entities, $summaryMax),
        ];

        $relations = $this->formatRelations($user->id, $entityIds);
        if ($relations !== '') {
            $parts[] = "=== Связи в этом фрагменте ===\n{$relations}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  Collection<int, array{entity: Entity, score: float}>  $ranked
     */
    private function formatRankedEntities(
        Collection $ranked,
        float $detailThreshold,
        int $summaryMax,
        int $compactMax,
    ): string {
        $detailed = collect();
        $compact = collect();

        foreach ($ranked as $item) {
            $entity = $item['entity'];
            $line = $this->formatEntityLine(
                $entity,
                $item['score'] >= $detailThreshold ? $summaryMax : $compactMax,
                $item['score'],
            );

            if ($item['score'] >= $detailThreshold) {
                $detailed->push($line);
            } else {
                $compact->push($line);
            }
        }

        $blocks = [];
        if ($detailed->isNotEmpty()) {
            $blocks[] = "=== Ключевые темы (подробно) ===\n".$detailed->implode("\n");
        }
        if ($compact->isNotEmpty()) {
            $blocks[] = "=== Дополнительные темы (кратко) ===\n".$compact->implode("\n");
        }

        return implode("\n\n", $blocks);
    }

    private function formatEntityLine(Entity $entity, int $summaryMaxChars, float $score): string
    {
        $v = $entity->versions->first();
        $summary = $this->entitySummary($v?->payload ?? [], $summaryMaxChars);
        $layer = self::LAYER_TITLES[$entity->layer] ?? $entity->layer;

        $importance = $score > 0 ? ", важность {$score}" : '';

        return "- [{$entity->type}{$importance}, {$layer}] {$entity->canonical_label}"
            .($summary ? ": {$summary}" : '');
    }

    /**
     * @param  list<array{priority: int, text: string}>  $sections
     */
    private function fitSectionsToLimit(array $sections, int $maxChars): string
    {
        usort($sections, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        $parts = [];
        $used = 0;

        foreach ($sections as $section) {
            $text = $section['text'];
            $separator = $parts === [] ? 0 : 2;
            $needed = mb_strlen($text) + $separator;

            if ($used + $needed <= $maxChars) {
                $parts[] = $text;
                $used += $needed;

                continue;
            }

            $remaining = $maxChars - $used - $separator;
            if ($remaining > 200 && $section['priority'] > 0) {
                $parts[] = mb_substr($text, 0, $remaining).'… [обрезано]';
            }

            break;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  list<string>  $entityIds
     */
    private function formatRelations(int $userId, array $entityIds): string
    {
        if ($entityIds === []) {
            return '';
        }

        return Relation::query()
            ->where('user_id', $userId)
            ->whereIn('source_entity_id', $entityIds)
            ->whereIn('target_entity_id', $entityIds)
            ->with([
                'sourceEntity:id,canonical_label,merged_into_id',
                'targetEntity:id,canonical_label,merged_into_id',
            ])
            ->get()
            ->map(function (Relation $relation) {
                $source = $relation->sourceEntity;
                $target = $relation->targetEntity;
                if (! $source?->isCanonical() || ! $target?->isCanonical()) {
                    return null;
                }

                return "{$source->canonical_label} --{$relation->type}--> {$target->canonical_label}";
            })
            ->filter()
            ->unique()
            ->implode("\n");
    }

    /**
     * @param  Collection<int, Entity>  $entities
     */
    private function formatEntityLines(Collection $entities, int $summaryMaxChars = 400): string
    {
        return $entities
            ->map(fn (Entity $e) => $this->formatEntityLine(
                $e,
                $summaryMaxChars,
                0,
            ))
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function entitySummary(array $payload, int $maxChars = 400): ?string
    {
        if ($summary = Arr::get($payload, 'summary')) {
            $text = is_string($summary) ? $summary : null;
        } else {
            $parts = array_filter([
                Arr::get($payload, 'description'),
                Arr::get($payload, 'role'),
                Arr::get($payload, 'context'),
            ], fn ($v) => is_string($v) && $v !== '');
            $text = $parts ? implode(' ', $parts) : null;
        }

        if ($text === null || $text === '') {
            return null;
        }

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars - 1).'…';
    }

    private function temporalLabel(Entity $entity): ?string
    {
        $payload = $entity->versions->first()?->payload ?? [];
        if ($year = Arr::get($payload, 'approx_year')) {
            return is_numeric($year) ? (string) (int) $year : null;
        }
        if ($period = Arr::get($payload, 'life_period')) {
            return is_string($period) ? $period : null;
        }

        return null;
    }
}
