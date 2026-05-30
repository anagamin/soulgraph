<?php

namespace App\Application\Services;

use App\Domain\Shared\EntityLabelNormalizer;
use App\Models\Entity;
use App\Models\User;
use App\Infrastructure\Projection\EntityEmbeddingProjector;
use Illuminate\Support\Collection;

class EntityDeduplicationService
{
    public function __construct(
        private EntityMergeService $merger,
        private EntityEmbeddingProjector $embeddings,
    ) {}

    /**
     * @return array{merged: int, candidates: int, backfilled: int}
     */
    public function deduplicateUser(User $user, bool $dryRun = false): array
    {
        $stats = ['merged' => 0, 'candidates' => 0, 'backfilled' => 0];

        $stats['backfilled'] = $this->backfillNormalizedKeys($user->id, $dryRun);
        $stats['merged'] += $this->mergeByNormalizedKey($user->id, $dryRun);
        $stats['merged'] += $this->mergeByLabelSimilarity($user->id, $dryRun);
        $stats['merged'] += $this->mergeBySemanticSimilarity($user->id, $dryRun, $stats);

        return $stats;
    }

    private function backfillNormalizedKeys(int $userId, bool $dryRun): int
    {
        $count = 0;
        Entity::canonical()
            ->where('user_id', $userId)
            ->whereNull('normalized_key')
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->chunkById(100, function ($entities) use (&$count, $dryRun) {
                foreach ($entities as $entity) {
                    $payload = $entity->versions->first()?->payload ?? [];
                    if (! is_array($payload)) {
                        $payload = [];
                    }

                    if (! EntityLabelNormalizer::supportsKeyDedup($entity->type, $payload)) {
                        continue;
                    }

                    $key = EntityLabelNormalizer::normalizedKey(
                        $entity->type,
                        $entity->canonical_label,
                        $payload,
                    );

                    if (! $dryRun) {
                        $entity->update(['normalized_key' => $key]);
                    }
                    $count++;
                }
            });

        return $count;
    }

    private function mergeByNormalizedKey(int $userId, bool $dryRun): int
    {
        $merged = 0;

        $groups = Entity::canonical()
            ->where('user_id', $userId)
            ->whereNotNull('normalized_key')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Entity $e) => $e->type.'|'.$e->normalized_key);

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            /** @var Collection<int, Entity> $group */
            $canonical = $group->first();
            foreach ($group->skip(1) as $duplicate) {
                if (! $dryRun) {
                    $this->merger->merge(
                        $canonical,
                        $duplicate,
                        'normalized_key',
                        "Same normalized key: {$duplicate->normalized_key}",
                        1.0,
                    );
                    $this->embeddings->embedEntity($canonical->fresh());
                }
                $merged++;
            }
        }

        return $merged;
    }

    private function mergeByLabelSimilarity(int $userId, bool $dryRun): int
    {
        $merged = 0;
        $threshold = (float) config('ai.deduplication.label_similarity_threshold', 0.88);

        Entity::canonical()
            ->where('user_id', $userId)
            ->orderBy('type')
            ->orderBy('created_at')
            ->get()
            ->groupBy('type')
            ->each(function (Collection $byType) use ($userId, $dryRun, $threshold, &$merged) {
                $remaining = $byType->values();

                for ($i = 0; $i < $remaining->count(); $i++) {
                    /** @var Entity|null $canonical */
                    $canonical = $remaining->get($i);
                    if (! $canonical || $canonical->merged_into_id !== null) {
                        continue;
                    }

                    for ($j = $i + 1; $j < $remaining->count(); $j++) {
                        /** @var Entity|null $duplicate */
                        $duplicate = $remaining->get($j);
                        if (! $duplicate || $duplicate->merged_into_id !== null) {
                            continue;
                        }

                        if ($canonical->layer !== $duplicate->layer) {
                            continue;
                        }

                        $score = EntityLabelNormalizer::similarity(
                            $canonical->canonical_label,
                            $duplicate->canonical_label,
                        );

                        if ($score < $threshold) {
                            continue;
                        }

                        if (! $dryRun) {
                            $this->merger->merge(
                                $canonical,
                                $duplicate,
                                'label_similarity',
                                'Similar labels',
                                $score,
                            );
                            $this->embeddings->embedEntity($canonical->fresh());
                            $duplicate->refresh();
                        }
                        $merged++;
                    }
                }
            });

        return $merged;
    }

    private function mergeBySemanticSimilarity(int $userId, bool $dryRun, array &$stats): int
    {
        $merged = 0;
        $autoThreshold = (float) config('ai.deduplication.auto_merge_threshold', 0.92);
        $suggestThreshold = (float) config('ai.deduplication.suggest_threshold', 0.80);

        $entities = Entity::canonical()
            ->where('user_id', $userId)
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get();

        foreach ($entities as $entity) {
            if (! $dryRun) {
                $this->embeddings->embedEntity($entity);
            }

            $payload = $entity->versions->first()?->payload ?? [];
            $summary = is_array($payload) ? ($payload['summary'] ?? '') : '';
            $text = trim("{$entity->type}: {$entity->canonical_label}. {$summary}");

            $matches = $this->embeddings->searchSimilar(
                $userId,
                $text,
                $entity->type,
                $entity->layer,
                5,
            );

            foreach ($matches as $match) {
                $otherId = $match['entity_id'] ?? null;
                $score = (float) ($match['score'] ?? 0);
                if (! $otherId || $otherId === $entity->id || $score < $suggestThreshold) {
                    continue;
                }

                $other = Entity::canonical()->where('user_id', $userId)->find($otherId);
                if (! $other) {
                    continue;
                }

                $canonical = $entity->created_at <= $other->created_at ? $entity : $other;
                $duplicate = $canonical->id === $entity->id ? $other : $entity;

                if ($duplicate->merged_into_id !== null) {
                    continue;
                }

                if ($score >= $autoThreshold) {
                    if (! $dryRun) {
                        $this->merger->merge(
                            $canonical,
                            $duplicate,
                            'semantic',
                            'Semantic similarity',
                            $score,
                        );
                        $this->embeddings->embedEntity($canonical->fresh());
                    }
                    $merged++;
                } elseif (! $dryRun) {
                    $this->merger->recordCandidate($userId, $canonical, $duplicate, $score, 'semantic');
                    $stats['candidates']++;
                }
            }
        }

        return $merged;
    }
}
