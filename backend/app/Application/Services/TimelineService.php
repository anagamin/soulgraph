<?php

namespace App\Application\Services;

use App\Domain\Shared\TemporalSource;
use App\Models\Entity;
use App\Models\Relation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class TimelineService
{
    /** @var array<string, int> */
    private const LIFE_PERIOD_SORT = [
        'раннее детство' => 1,
        'детство' => 2,
        'дошкольный возраст' => 2,
        'школа' => 3,
        'школьные годы' => 3,
        'подростковый возраст' => 4,
        'подростковый' => 4,
        'подростковые годы' => 4,
        'юность' => 5,
        'студенчество' => 6,
        'университет' => 6,
        'молодость' => 7,
        'армия' => 7,
        'служба' => 7,
        'первый брак' => 8,
        'зрелость' => 9,
        'средний возраст' => 10,
        'развод' => 10,
        'пожилой возраст' => 11,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     approx_year: ?int,
     *     occurred_at: ?string,
     *     life_period: ?string,
     *     sort_key: float,
     *     has_date: bool,
     *     display: string,
     *     temporal_source: ?string,
     *     date_suspicious: bool
     * }
     */
    public function resolveTemporal(array $payload, ?Carbon $validFrom): array
    {
        $approxYear = Arr::get($payload, 'approx_year');
        if (is_string($approxYear) && is_numeric($approxYear)) {
            $approxYear = (int) $approxYear;
        }
        $approxYear = is_int($approxYear) ? $approxYear : null;

        $occurredAt = Arr::get($payload, 'occurred_at');
        $lifePeriod = Arr::get($payload, 'life_period');
        $temporalSource = Arr::get($payload, 'temporal_source');
        $dateSuspicious = false;

        $sortKey = null;
        $display = 'Без даты';

        if ($approxYear) {
            $sortKey = (float) $approxYear;
            $display = (string) $approxYear;
        } elseif (is_string($occurredAt) && $occurredAt !== '') {
            try {
                $date = Carbon::parse($occurredAt);
                $sortKey = (float) $date->year + ($date->month / 12);
                $display = $date->format('Y-m-d');
            } catch (\Throwable) {
                $occurredAt = null;
            }
        } elseif ($lifePeriod && is_string($lifePeriod)) {
            $sortKey = $this->lifePeriodSortKey($lifePeriod);
            $display = $lifePeriod;
        } elseif ($validFrom && $validFrom->year > 1970) {
            $sortKey = (float) $validFrom->year;
            $display = (string) $validFrom->year;
            $dateSuspicious = true;
        } else {
            $sortKey = PHP_FLOAT_MAX;
        }

        return [
            'approx_year' => $approxYear,
            'occurred_at' => is_string($occurredAt) ? $occurredAt : null,
            'life_period' => is_string($lifePeriod) ? $lifePeriod : null,
            'sort_key' => $sortKey,
            'has_date' => $sortKey < PHP_FLOAT_MAX,
            'display' => $display,
            'temporal_source' => is_string($temporalSource) ? $temporalSource : null,
            'date_suspicious' => $dateSuspicious,
        ];
    }

    public function chronologyContextForUser(int $userId): string
    {
        $user = User::find($userId);
        $lines = [];

        if ($user?->birth_year) {
            $place = $user->birth_place ? ", {$user->birth_place}" : '';
            $lines[] = "Якорь: год рождения {$user->birth_year}{$place}";
        }

        $entities = $this->loadTimelineEntities($userId);
        if ($entities->isEmpty()) {
            return $lines ? implode("\n", $lines)."\n\nСобытий на таймлайне пока нет." : 'Событий на таймлайне пока нет.';
        }

        $lines[] = '=== Хронологический таймлайн (от раннего к позднему) ===';

        $grouped = $entities->groupBy(fn (array $e) => $e['temporal']['display']);
        $groups = $grouped->map(function (Collection $group, string $label) {
            return [
                'label' => $label,
                'sort_key' => $group->min('temporal.sort_key'),
                'items' => $group->sortBy('temporal.sort_key')->values(),
            ];
        })->sortBy('sort_key');

        foreach ($groups as $group) {
            $flag = '';
            if ($group['items']->contains(fn (array $e) => $e['temporal']['date_suspicious'])) {
                $flag = ' [⚠ дата из времени разговора, не подтверждена]';
            }
            $lines[] = "\n## {$group['label']}{$flag}";
            foreach ($group['items'] as $item) {
                $suspicious = $item['temporal']['date_suspicious'] ? ' ⚠' : '';
                $source = $item['temporal']['temporal_source'] ?? 'unknown';
                $lines[] = "- {$item['label']} [{$item['type']}, id={$item['id']}, source={$source}]{$suspicious}";
            }
        }

        $epochs = $entities->where('type', 'epoch');
        if ($epochs->isNotEmpty()) {
            $lines[] = "\n=== Эпохи (главы жизни) ===";
            foreach ($epochs as $epoch) {
                $lines[] = "- {$epoch['label']} ({$epoch['temporal']['display']})";
            }
        }

        $conflicts = $this->detectConflicts($userId, $entities);
        if ($conflicts !== []) {
            $lines[] = "\n=== Возможные хронологические конфликты ===";
            foreach ($conflicts as $conflict) {
                $lines[] = "- {$conflict}";
            }
        }

        $undated = $entities->filter(fn (array $e) => ! $e['temporal']['has_date']);
        if ($undated->isNotEmpty()) {
            $lines[] = "\n=== Без даты (нужно привязать) ===";
            foreach ($undated->take(15) as $item) {
                $lines[] = "- {$item['label']} [id={$item['id']}]";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public function detectConflicts(int $userId, ?Collection $entities = null): array
    {
        $entities ??= $this->loadTimelineEntities($userId);
        $conflicts = [];

        $dated = $entities->filter(fn (array $e) => $e['temporal']['has_date'] && ! $e['temporal']['date_suspicious']);
        $sorted = $dated->sortBy('temporal.sort_key')->values();

        for ($i = 0; $i < $sorted->count() - 1; $i++) {
            $a = $sorted[$i];
            $b = $sorted[$i + 1];
            if ($a['temporal']['sort_key'] > $b['temporal']['sort_key']) {
                $conflicts[] = "«{$a['label']}» ({$a['temporal']['display']}) стоит после «{$b['label']}» ({$b['temporal']['display']}) — проверьте порядок";
            }
        }

        $precedesRelations = Relation::where('user_id', $userId)
            ->whereIn('type', ['precedes', 'follows'])
            ->with([
                'sourceEntity.versions' => fn ($q) => $q->where('is_active', true),
                'targetEntity.versions' => fn ($q) => $q->where('is_active', true),
            ])
            ->get();

        foreach ($precedesRelations as $relation) {
            $source = $relation->sourceEntity;
            $target = $relation->targetEntity;
            if (! $source || ! $target) {
                continue;
            }

            $sourceTemporal = $this->resolveTemporal(
                $source->versions->first()?->payload ?? [],
                $source->versions->first()?->valid_from,
            );
            $targetTemporal = $this->resolveTemporal(
                $target->versions->first()?->payload ?? [],
                $target->versions->first()?->valid_from,
            );

            if (! $sourceTemporal['has_date'] || ! $targetTemporal['has_date']) {
                continue;
            }

            $sourceShouldBeFirst = $relation->type === 'precedes';
            $sourceKey = $sourceTemporal['sort_key'];
            $targetKey = $targetTemporal['sort_key'];

            if ($sourceShouldBeFirst && $sourceKey > $targetKey) {
                $conflicts[] = "Связь «{$source->canonical_label}» precedes «{$target->canonical_label}», но даты говорят обратное";
            } elseif (! $sourceShouldBeFirst && $sourceKey < $targetKey) {
                $conflicts[] = "Связь «{$source->canonical_label}» follows «{$target->canonical_label}», но даты говорят обратное";
            }
        }

        return array_unique($conflicts);
    }

    /**
     * @return Collection<int, array{id: string, type: string, label: string, temporal: array<string, mixed>}>
     */
    public function loadTimelineEntities(int $userId): Collection
    {
        return Entity::canonical()
            ->where('user_id', $userId)
            ->where('layer', 'earth')
            ->whereIn('type', ['event', 'epoch'])
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->map(function (Entity $entity) {
                $version = $entity->versions->first();
                $payload = $version?->payload ?? [];

                return [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'label' => $entity->canonical_label,
                    'temporal' => $this->resolveTemporal($payload, $version?->valid_from),
                ];
            })
            ->sortBy('temporal.sort_key')
            ->values();
    }

    public function lifePeriodSortKey(string $lifePeriod): float
    {
        $periodKey = mb_strtolower(trim($lifePeriod));

        if (isset(self::LIFE_PERIOD_SORT[$periodKey])) {
            return (float) self::LIFE_PERIOD_SORT[$periodKey];
        }

        if (preg_match('/^(\d{4})-е/u', $periodKey, $matches)) {
            return (float) ((int) $matches[1] + 5);
        }

        if (preg_match('/^(\d{4})s$/u', $periodKey, $matches)) {
            return (float) ((int) $matches[1] + 5);
        }

        if (preg_match('/^(\d{4})\s*[-–—]\s*(\d{4})/u', $periodKey, $matches)) {
            return (float) (((int) $matches[1] + (int) $matches[2]) / 2);
        }

        return 900 + (crc32($periodKey) % 100);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function mergeTemporalAttributes(array $existing, array $incoming): array
    {
        $merged = $existing;
        $temporalKeys = ['approx_year', 'occurred_at', 'life_period', 'temporal_source'];

        foreach ($temporalKeys as $key) {
            $value = Arr::get($incoming, $key);
            if ($value === null || $value === '') {
                continue;
            }

            $existingSource = Arr::get($merged, 'temporal_source', TemporalSource::AI_INFERRED);
            $incomingSource = Arr::get($incoming, 'temporal_source', TemporalSource::AI_INFERRED);

            if (! isset($merged[$key]) || $merged[$key] === '' || $merged[$key] === null) {
                $merged[$key] = $value;
                if ($key !== 'temporal_source' && ! isset($incoming['temporal_source'])) {
                    $merged['temporal_source'] = TemporalSource::AI_INFERRED;
                }
                continue;
            }

            if ($key === 'temporal_source') {
                if ($this->sourcePriority($incomingSource) >= $this->sourcePriority($existingSource)) {
                    $merged['temporal_source'] = $incomingSource;
                }
                continue;
            }

            if ($this->sourcePriority($incomingSource) > $this->sourcePriority($existingSource)) {
                $merged[$key] = $value;
                $merged['temporal_source'] = $incomingSource;
            } elseif ($this->sourcePriority($incomingSource) === $this->sourcePriority($existingSource)
                && $incomingSource === TemporalSource::RECONCILIATION) {
                $merged[$key] = $value;
            }
        }

        if (isset($merged['approx_year']) && ! isset($merged['temporal_source'])) {
            $merged['temporal_source'] = TemporalSource::AI_INFERRED;
        }

        return $merged;
    }

    private function sourcePriority(string $source): int
    {
        return match ($source) {
            TemporalSource::USER_STATED => 3,
            TemporalSource::RECONCILIATION => 2,
            default => 1,
        };
    }
}
