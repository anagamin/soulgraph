<?php

namespace App\Application\Services;

use App\Domain\Shared\EntityLabelNormalizer;
use App\Domain\Shared\TemporalSource;
use App\Models\Entity;
use App\Models\EntityAlias;
use App\Models\Message;
use App\Models\EntityVersion;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class EntityResolutionService
{
    public function __construct(
        private EntityMergeService $merger,
        private EntitySemanticMatcher $semanticMatcher,
        private TimelineService $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public function resolveOrCreate(Message $message, array $item): ?Entity
    {
        $type = Arr::get($item, 'type');
        $layer = Arr::get($item, 'layer');
        $label = Arr::get($item, 'label');
        if (! $type || ! $layer || ! $label) {
            return null;
        }

        $attributes = Arr::get($item, 'attributes', ['label' => $label]);
        if (! is_array($attributes)) {
            $attributes = ['label' => $label];
        }

        $confidence = (float) Arr::get($item, 'confidence', 0.5);
        $userId = $message->user_id;
        $normalizedKey = EntityLabelNormalizer::normalizedKey($type, $label, $attributes);

        $matchEntityId = Arr::get($item, 'match_entity_id');
        if ($matchEntityId) {
            $matched = $this->findCanonicalEntity($userId, (string) $matchEntityId, $type);
            if ($matched) {
                return $this->enrichEntity($matched, $message, $label, $attributes, $confidence, $normalizedKey);
            }
        }

        if (EntityLabelNormalizer::supportsKeyDedup($type, $attributes)) {
            $byKey = Entity::canonical()
                ->where('user_id', $userId)
                ->where('type', $type)
                ->where('normalized_key', $normalizedKey)
                ->first();

            if ($byKey) {
                return $this->enrichEntity($byKey, $message, $label, $attributes, $confidence, $normalizedKey);
            }
        }

        $normalizedLabel = EntityLabelNormalizer::normalize($label);
        $aliasEntityId = EntityAlias::query()
            ->whereHas('entity', fn ($q) => $q
                ->where('user_id', $userId)
                ->where('type', $type)
                ->whereNull('merged_into_id'))
            ->where('normalized_alias', $normalizedLabel)
            ->value('entity_id');

        if ($aliasEntityId) {
            $byAlias = Entity::find($aliasEntityId);
            if ($byAlias) {
                return $this->enrichEntity($byAlias, $message, $label, $attributes, $confidence, $normalizedKey);
            }
        }

        $semanticMatch = $this->semanticMatcher->findBestMatch(
            $userId,
            $type,
            $layer,
            $label,
            $attributes,
        );

        if ($semanticMatch) {
            $autoThreshold = (float) config('ai.deduplication.auto_merge_threshold', 0.92);
            $suggestThreshold = (float) config('ai.deduplication.suggest_threshold', 0.80);

            if ($semanticMatch['score'] >= $autoThreshold) {
                return $this->enrichEntity(
                    $semanticMatch['entity'],
                    $message,
                    $label,
                    $attributes,
                    $confidence,
                    $normalizedKey,
                );
            }

            if ($semanticMatch['score'] >= $suggestThreshold) {
                $newEntity = $this->createEntity($message, $type, $layer, $label, $attributes, $confidence, $normalizedKey);
                $this->merger->recordCandidate(
                    $userId,
                    $semanticMatch['entity'],
                    $newEntity,
                    $semanticMatch['score'],
                    'semantic',
                );

                return $newEntity;
            }
        }

        return $this->createEntity($message, $type, $layer, $label, $attributes, $confidence, $normalizedKey);
    }

    private function findCanonicalEntity(int $userId, string $entityId, string $type): ?Entity
    {
        $entity = Entity::canonical()
            ->where('user_id', $userId)
            ->where('id', $entityId)
            ->where('type', $type)
            ->first();

        return $entity;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrichEntity(
        Entity $entity,
        Message $message,
        string $label,
        array $attributes,
        float $confidence,
        string $normalizedKey,
    ): Entity {
        if ($entity->normalized_key === null && EntityLabelNormalizer::supportsKeyDedup($entity->type, $attributes)) {
            $entity->update(['normalized_key' => $normalizedKey]);
        }

        $this->merger->addAlias($entity, $label);

        $currentActive = $entity->versions()->where('is_active', true)->latest('valid_from')->first();
        $mergedPayload = $this->mergePayload(
            $currentActive?->payload ?? [],
            $attributes,
        );

        if ($currentActive) {
            $currentActive->update([
                'is_active' => false,
                'valid_until' => now(),
            ]);
        }

        EntityVersion::create([
            'entity_id' => $entity->id,
            'source_message_id' => $message->id,
            'valid_from' => $this->resolveValidFrom($attributes) ?? now(),
            'payload' => $mergedPayload,
            'confidence' => max($confidence, $entity->activeVersion()?->confidence ?? 0),
            'is_active' => true,
        ]);

        return $entity->fresh(['versions', 'aliases']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEntity(
        Message $message,
        string $type,
        string $layer,
        string $label,
        array $attributes,
        float $confidence,
        string $normalizedKey,
    ): Entity {
        $entity = Entity::create([
            'user_id' => $message->user_id,
            'type' => $type,
            'layer' => $layer,
            'canonical_label' => $label,
            'normalized_key' => EntityLabelNormalizer::supportsKeyDedup($type, $attributes)
                ? $normalizedKey
                : null,
        ]);

        EntityVersion::create([
            'entity_id' => $entity->id,
            'source_message_id' => $message->id,
            'valid_from' => $this->resolveValidFrom($attributes) ?? now(),
            'payload' => $attributes,
            'confidence' => $confidence,
            'is_active' => true,
        ]);

        return $entity->load('versions');
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergePayload(array $existing, array $incoming): array
    {
        $temporalKeys = ['approx_year', 'occurred_at', 'life_period', 'temporal_source'];
        $temporalIncoming = Arr::only($incoming, $temporalKeys);
        $merged = $this->timeline->mergeTemporalAttributes($existing, $temporalIncoming);

        foreach ($incoming as $key => $value) {
            if (in_array($key, $temporalKeys, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (! isset($merged[$key]) || $merged[$key] === '' || $merged[$key] === null) {
                $merged[$key] = $value;
            } elseif ($key === 'summary' && is_string($value) && is_string($merged[$key])) {
                if (! str_contains($merged[$key], $value)) {
                    $merged[$key] = trim($merged[$key].' '.$value);
                }
            } elseif ($key === 'life_significance' && is_numeric($value)) {
                $incomingScore = max(0.0, min(1.0, (float) $value));
                $existingScore = is_numeric($merged[$key] ?? null) ? (float) $merged[$key] : 0.0;
                $merged[$key] = max($existingScore, $incomingScore);
            } elseif ($key === 'life_significance_source' && is_string($value)) {
                $incomingSource = $value;
                $existingSource = $merged[$key] ?? null;
                if ($existingSource === EntitySignificanceService::SOURCE_USER_STATED && $incomingSource !== EntitySignificanceService::SOURCE_USER_STATED) {
                    continue;
                }
                if ($incomingSource === EntitySignificanceService::SOURCE_USER_STATED) {
                    $merged[$key] = EntitySignificanceService::SOURCE_USER_STATED;
                } elseif ($existingSource === null || $existingSource === '') {
                    $merged[$key] = $incomingSource;
                }
            }
        }

        if (Arr::has($merged, 'approx_year') && ! Arr::has($merged, 'temporal_source')) {
            $merged['temporal_source'] = TemporalSource::AI_INFERRED;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveValidFrom(array $attributes): ?Carbon
    {
        $approxYear = Arr::get($attributes, 'approx_year');
        if (is_string($approxYear) && is_numeric($approxYear)) {
            $approxYear = (int) $approxYear;
        }
        if (is_int($approxYear) && $approxYear > 1800 && $approxYear < 2100) {
            return Carbon::create($approxYear, 6, 1);
        }

        $occurredAt = Arr::get($attributes, 'occurred_at');
        if (is_string($occurredAt) && $occurredAt !== '') {
            try {
                return Carbon::parse($occurredAt);
            } catch (\Throwable) {
                // fall through
            }
        }

        return null;
    }
}
