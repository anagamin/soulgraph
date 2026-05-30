<?php

namespace App\Application\Services;

use App\Domain\Shared\EntityLabelNormalizer;
use App\Models\Entity;
use App\Infrastructure\Projection\EntityEmbeddingProjector;

class EntitySemanticMatcher
{
    public function __construct(
        private EntityEmbeddingProjector $embeddings,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{entity: Entity, score: float}|null
     */
    public function findBestMatch(
        int $userId,
        string $type,
        string $layer,
        string $label,
        array $attributes = [],
    ): ?array {
        $summary = is_string($attributes['summary'] ?? null) ? $attributes['summary'] : '';
        $text = trim("{$type}: {$label}. {$summary}");

        $results = $this->embeddings->searchSimilar($userId, $text, $type, $layer, 5);

        $best = null;
        foreach ($results as $result) {
            $entityId = $result['entity_id'] ?? null;
            $score = (float) ($result['score'] ?? 0);
            if (! $entityId || $score <= 0) {
                continue;
            }

            $entity = Entity::canonical()
                ->where('user_id', $userId)
                ->where('id', $entityId)
                ->where('type', $type)
                ->where('layer', $layer)
                ->first();

            if (! $entity) {
                continue;
            }

            if ($best === null || $score > $best['score']) {
                $best = ['entity' => $entity, 'score' => $score];
            }
        }

        if ($best !== null) {
            return $best;
        }

        $candidates = Entity::canonical()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('layer', $layer)
            ->limit(100)
            ->get();

        $labelScore = 0.0;
        $labelMatch = null;
        foreach ($candidates as $candidate) {
            $score = EntityLabelNormalizer::similarity($label, $candidate->canonical_label);
            if ($score > $labelScore) {
                $labelScore = $score;
                $labelMatch = $candidate;
            }
        }

        $fallbackThreshold = (float) config('ai.deduplication.label_similarity_threshold', 0.88);
        if ($labelMatch && $labelScore >= $fallbackThreshold) {
            return ['entity' => $labelMatch, 'score' => $labelScore];
        }

        return null;
    }
}
