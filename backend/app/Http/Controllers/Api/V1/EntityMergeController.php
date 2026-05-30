<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\EntityMergeService;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\EntityMergeCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntityMergeController extends Controller
{
    public function __construct(
        private EntityMergeService $merger,
    ) {}

    public function candidates(Request $request): JsonResponse
    {
        $candidates = EntityMergeCandidate::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->whereHas('entityA', fn ($q) => $q->whereNull('merged_into_id'))
            ->whereHas('entityB', fn ($q) => $q->whereNull('merged_into_id'))
            ->with(['entityA.versions', 'entityB.versions'])
            ->orderByDesc('similarity')
            ->get()
            ->map(fn (EntityMergeCandidate $c) => [
                'id' => $c->id,
                'similarity' => $c->similarity,
                'method' => $c->method,
                'entity_a' => $this->mapEntity($c->entityA),
                'entity_b' => $this->mapEntity($c->entityB),
            ]);

        return response()->json(['candidates' => $candidates]);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        $candidate = EntityMergeCandidate::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->with(['entityA', 'entityB'])
            ->findOrFail($id);

        $canonical = $candidate->entityA->created_at <= $candidate->entityB->created_at
            ? $candidate->entityA
            : $candidate->entityB;
        $duplicate = $canonical->id === $candidate->entity_a_id
            ? $candidate->entityB
            : $candidate->entityA;

        $merged = $this->merger->merge(
            $canonical,
            $duplicate,
            'user_confirmed',
            'User accepted merge candidate',
            $candidate->similarity,
        );

        $candidate->update(['status' => 'accepted']);

        return response()->json(['entity' => $this->mapEntity($merged)]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $candidate = EntityMergeCandidate::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->findOrFail($id);

        $candidate->update(['status' => 'rejected']);

        return response()->json(['status' => 'rejected']);
    }

    public function merge(Request $request, string $canonicalId, string $duplicateId): JsonResponse
    {
        $canonical = Entity::canonical()
            ->where('user_id', $request->user()->id)
            ->findOrFail($canonicalId);

        $duplicate = Entity::canonical()
            ->where('user_id', $request->user()->id)
            ->findOrFail($duplicateId);

        $merged = $this->merger->merge(
            $canonical,
            $duplicate,
            'manual',
            'Manual merge by user',
            1.0,
        );

        return response()->json(['entity' => $this->mapEntity($merged)]);
    }

    private function mapEntity(?Entity $entity): ?array
    {
        if (! $entity) {
            return null;
        }

        $version = $entity->versions()->where('is_active', true)->latest('valid_from')->first()
            ?? $entity->versions()->latest('valid_from')->first();

        return [
            'id' => $entity->id,
            'type' => $entity->type,
            'layer' => $entity->layer,
            'label' => $entity->canonical_label,
            'payload' => $version?->payload ?? [],
        ];
    }
}
