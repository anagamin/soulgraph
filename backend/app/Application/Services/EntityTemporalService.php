<?php

namespace App\Application\Services;

use App\Domain\Shared\TemporalSource;
use App\Infrastructure\Projection\Neo4jGraphProjector;
use App\Models\Entity;
use App\Models\EntityVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class EntityTemporalService
{
    public function __construct(
        private TimelineService $timeline,
        private Neo4jGraphProjector $graphProjector,
    ) {}

    /**
     * @param  array{approx_year?: ?int, occurred_at?: ?string, life_period?: ?string}  $data
     */
    public function updateTemporal(User $user, Entity $entity, array $data, string $source = TemporalSource::USER_STATED): Entity
    {
        if ($entity->user_id !== $user->id) {
            throw new \InvalidArgumentException('Entity does not belong to user.');
        }

        $currentActive = $entity->versions()->where('is_active', true)->latest('valid_from')->first();
        $existing = $currentActive?->payload ?? [];

        $incoming = array_filter([
            'approx_year' => Arr::get($data, 'approx_year'),
            'occurred_at' => Arr::get($data, 'occurred_at'),
            'life_period' => Arr::get($data, 'life_period'),
            'temporal_source' => $source,
        ], fn ($v) => $v !== null && $v !== '');

        if ($incoming === []) {
            return $entity;
        }

        if (isset($incoming['approx_year'])) {
            unset($incoming['occurred_at'], $incoming['life_period']);
        } elseif (isset($incoming['occurred_at'])) {
            unset($incoming['approx_year'], $incoming['life_period']);
        } elseif (isset($incoming['life_period'])) {
            unset($incoming['approx_year'], $incoming['occurred_at']);
        }

        $merged = $this->timeline->mergeTemporalAttributes($existing, $incoming);

        if ($currentActive) {
            $currentActive->update([
                'is_active' => false,
                'valid_until' => now(),
            ]);
        }

        EntityVersion::create([
            'entity_id' => $entity->id,
            'valid_from' => $this->resolveValidFrom($merged) ?? now(),
            'payload' => $merged,
            'confidence' => $currentActive?->confidence ?? 0.9,
            'is_active' => true,
        ]);

        $entity = $entity->fresh(['versions']);
        $this->graphProjector->projectEntity($entity);

        return $entity;
    }

    public function updateProfileAnchors(User $user, ?int $birthYear, ?string $birthPlace): User
    {
        $user->update([
            'birth_year' => $birthYear,
            'birth_place' => $birthPlace !== '' ? $birthPlace : null,
        ]);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveValidFrom(array $payload): ?Carbon
    {
        $approxYear = Arr::get($payload, 'approx_year');
        if (is_string($approxYear) && is_numeric($approxYear)) {
            $approxYear = (int) $approxYear;
        }
        if (is_int($approxYear) && $approxYear > 1800 && $approxYear < 2100) {
            return Carbon::create($approxYear, 6, 1);
        }

        $occurredAt = Arr::get($payload, 'occurred_at');
        if (is_string($occurredAt) && $occurredAt !== '') {
            try {
                return Carbon::parse($occurredAt);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
