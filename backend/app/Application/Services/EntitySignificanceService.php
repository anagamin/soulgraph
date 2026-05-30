<?php

namespace App\Application\Services;

use App\Models\Entity;
use App\Models\EntityVersion;
use Illuminate\Support\Arr;

class EntitySignificanceService
{
    public const SOURCE_USER_STATED = 'user_stated';

    public const SOURCE_AI_INFERRED = 'ai_inferred';

    public const SOURCE_COMPUTED = 'computed';

    /**
     * Heuristic 0.0–1.0 for backfill (ignores stored life_significance).
     */
    public function heuristicSignificance(Entity $entity, int $relationCount, int $versionCount): float
    {
        $version = $entity->versions->first();
        $payload = $version?->payload ?? [];
        $confidence = (float) ($version?->confidence ?? 0.5);

        $significance = $this->inferFromContent($entity, $payload);
        $relationScore = min(1.0, $relationCount / 5);
        $mentionScore = min(1.0, max(1, $versionCount) / 4);
        $typeScore = EntityImportanceScorer::typeWeight($entity->type);

        return round(
            0.30 * $significance
            + 0.25 * $confidence
            + 0.20 * $relationScore
            + 0.15 * $mentionScore
            + 0.10 * $typeScore,
            4,
        );
    }

    public function persistSignificance(Entity $entity, float $significance, string $source): bool
    {
        $version = $entity->versions->where('is_active', true)->first();
        if (! $version) {
            return false;
        }

        $payload = $version->payload ?? [];
        if (
            Arr::get($payload, 'life_significance_source') === self::SOURCE_USER_STATED
            && $source !== self::SOURCE_USER_STATED
        ) {
            return false;
        }

        $value = max(0.0, min(1.0, round($significance, 4)));
        $existing = Arr::get($payload, 'life_significance');
        $existingSource = Arr::get($payload, 'life_significance_source');

        if (
            $source !== self::SOURCE_USER_STATED
            && is_numeric($existing)
            && $existingSource === self::SOURCE_USER_STATED
        ) {
            return false;
        }

        if (
            $source !== self::SOURCE_USER_STATED
            && is_numeric($existing)
            && abs((float) $existing - $value) < 0.001
            && $existingSource === $source
        ) {
            return false;
        }

        $payload['life_significance'] = $value;
        $payload['life_significance_source'] = $source;
        $payload['life_significance_updated_at'] = now()->toIso8601String();

        $version->update(['payload' => $payload]);

        return true;
    }

    /**
     * Maps explicit user ratings (0–10 or phrases) to 0.0–1.0.
     */
    public function parseExplicitRating(string $text): ?float
    {
        $normalized = mb_strtolower(trim($text));

        if (preg_match('/\b(10|9[,.]?\d*|9)\s*(?:\/\s*10|из\s*10)?\b/u', $normalized, $m)) {
            $n = (float) str_replace(',', '.', $m[1]);

            return $this->scaleZeroToTen($n);
        }

        if (preg_match('/\b([0-9]{1,2})\s*(?:\/\s*10|из\s*10)\b/u', $normalized, $m)) {
            return $this->scaleZeroToTen((float) $m[1]);
        }

        $phrases = [
            'перелом' => 0.95,
            'изменил' => 0.9,
            'изменила' => 0.9,
            'судьбонос' => 0.95,
            'ключев' => 0.9,
            'очень важн' => 0.9,
            'чрезвычайно важн' => 0.95,
            'крайне важн' => 0.9,
            'сильно важн' => 0.85,
            'важн' => 0.75,
            'не особо' => 0.35,
            'не очень' => 0.35,
            'неважн' => 0.2,
            'пофиг' => 0.15,
            'безразлич' => 0.2,
        ];

        foreach ($phrases as $needle => $score) {
            if (str_contains($normalized, $needle)) {
                return $score;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function inferFromContent(Entity $entity, array $payload): float
    {
        $text = mb_strtolower(implode(' ', array_filter([
            $entity->canonical_label,
            Arr::get($payload, 'summary'),
            Arr::get($payload, 'description'),
        ], fn ($v) => is_string($v) && $v !== '')));

        $highImpact = [
            'смерт', 'умер', 'погиб', 'потер', 'развод', 'эмигра', 'переезд',
            'рожден', 'свадьб', 'диагноз', 'болезн', 'авар', 'войн',
        ];

        foreach ($highImpact as $needle) {
            if (str_contains($text, $needle)) {
                return 0.95;
            }
        }

        if (in_array($entity->type, ['event', 'epoch'], true)) {
            return 0.75;
        }

        if (in_array($entity->type, ['person', 'identity'], true)) {
            return 0.65;
        }

        return 0.45;
    }

    private function scaleZeroToTen(float $value): float
    {
        $clamped = max(0.0, min(10.0, $value));

        return match (true) {
            $clamped <= 2 => 0.2,
            $clamped <= 4 => 0.4,
            $clamped <= 6 => 0.6,
            $clamped <= 8 => 0.85,
            default => 0.95,
        };
    }
}
