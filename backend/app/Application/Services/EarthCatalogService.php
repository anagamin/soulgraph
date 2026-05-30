<?php

namespace App\Application\Services;

use App\Models\Entity;
use App\Models\EntityVersion;
use App\Models\Message;
use App\Models\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class EarthCatalogService
{
    public function __construct(
        private TimelineService $timeline,
    ) {}

    public function catalog(int $userId): array
    {
        $entities = Entity::canonical()
            ->where('user_id', $userId)
            ->where('layer', 'earth')
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get();

        $entityIds = $entities->pluck('id');
        $relationCounts = $this->relationCounts($userId, $entityIds);

        $mapped = $entities->map(fn (Entity $e) => $this->mapEntity(
            $e,
            $relationCounts->get($e->id, 0),
        ));

        $edges = $this->loadEdges($userId, $entityIds);

        return [
            'events' => $mapped->where('type', 'event')->sortBy('temporal.sort_key')->values(),
            'epochs' => $mapped->where('type', 'epoch')->sortBy('temporal.sort_key')->values(),
            'people' => $mapped->where('type', 'person')->sortBy('label')->values(),
            'places' => $mapped->where('type', 'place')->sortBy('label')->values(),
            'relationships' => $mapped->where('type', 'relationship')->sortBy('label')->values(),
            'edges' => $edges,
            'timeline' => $this->buildTimelineGroups($mapped),
        ];
    }

    public function entityDetail(int $userId, string $entityId): ?array
    {
        $entity = Entity::canonical()
            ->where('user_id', $userId)
            ->where('layer', 'earth')
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->find($entityId);

        if (! $entity) {
            return null;
        }

        $familyIds = $entity->familyIds();
        $messageIds = EntityVersion::whereIn('entity_id', $familyIds)
            ->pluck('source_message_id')
            ->filter()
            ->unique();

        $phrases = Message::where('user_id', $userId)
            ->whereIn('id', $messageIds)
            ->where('role', 'user')
            ->orderBy('created_at')
            ->get(['id', 'content', 'created_at'])
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        $relations = Relation::where('user_id', $userId)
            ->where(fn ($q) => $q
                ->where('source_entity_id', $entity->id)
                ->orWhere('target_entity_id', $entity->id))
            ->with([
                'sourceEntity.versions' => fn ($q) => $q->where('is_active', true),
                'targetEntity.versions' => fn ($q) => $q->where('is_active', true),
            ])
            ->get();

        $related = $relations->map(function (Relation $r) use ($entity) {
            $isOutgoing = $r->source_entity_id === $entity->id;
            $other = $isOutgoing ? $r->targetEntity : $r->sourceEntity;
            if (! $other || $other->layer !== 'earth' || ! $other->isCanonical()) {
                return null;
            }

            return [
                'relation_id' => $r->id,
                'relation_type' => $r->type,
                'direction' => $isOutgoing ? 'outgoing' : 'incoming',
                'entity' => $this->mapEntity($other, 0),
            ];
        })->filter()->values();

        $mapped = $this->mapEntity($entity, $related->count());

        return [
            'entity' => $mapped,
            'summary' => $this->buildSummary($mapped),
            'related' => $related,
            'phrases' => $phrases,
        ];
    }

    private function mapEntity(Entity $entity, int $relatedCount): array
    {
        $version = $entity->versions->first();
        $payload = $version?->payload ?? [];
        $temporal = $this->timeline->resolveTemporal($payload, $version?->valid_from);

        return [
            'id' => $entity->id,
            'type' => $entity->type,
            'label' => $entity->canonical_label,
            'payload' => $payload,
            'confidence' => $version?->confidence,
            'valid_from' => $version?->valid_from?->toIso8601String(),
            'temporal' => $temporal,
            'related_count' => $relatedCount,
        ];
    }

    private function buildTimelineGroups(Collection $entities): array
    {
        $timelineEntities = $entities->filter(
            fn (array $e) => in_array($e['type'], ['event', 'epoch'], true),
        );

        return $timelineEntities
            ->groupBy(fn (array $e) => $e['temporal']['display'])
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'sort_key' => $group->min('temporal.sort_key'),
                'items' => $group->sortBy('temporal.sort_key')->values()->all(),
            ])
            ->sortBy('sort_key')
            ->values()
            ->all();
    }

    private function relationCounts(int $userId, Collection $entityIds): Collection
    {
        if ($entityIds->isEmpty()) {
            return collect();
        }

        $counts = collect();

        Relation::where('user_id', $userId)
            ->where(fn ($q) => $q
                ->whereIn('source_entity_id', $entityIds)
                ->orWhereIn('target_entity_id', $entityIds))
            ->get(['source_entity_id', 'target_entity_id'])
            ->each(function (Relation $r) use ($entityIds, $counts) {
                if ($entityIds->contains($r->source_entity_id)) {
                    $counts->put($r->source_entity_id, ($counts[$r->source_entity_id] ?? 0) + 1);
                }
                if ($entityIds->contains($r->target_entity_id)) {
                    $counts->put($r->target_entity_id, ($counts[$r->target_entity_id] ?? 0) + 1);
                }
            });

        return $counts;
    }

    private function loadEdges(int $userId, Collection $entityIds): array
    {
        if ($entityIds->isEmpty()) {
            return [];
        }

        return Relation::where('user_id', $userId)
            ->whereIn('source_entity_id', $entityIds)
            ->whereIn('target_entity_id', $entityIds)
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->map(fn (Relation $r) => [
                'id' => $r->id,
                'source' => $r->source_entity_id,
                'target' => $r->target_entity_id,
                'type' => $r->type,
                'confidence' => $r->versions->first()?->confidence ?? 0.5,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function buildSummary(array $entity): ?string
    {
        $payload = $entity['payload'] ?? [];

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
}
