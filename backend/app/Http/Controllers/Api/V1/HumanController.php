<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HumanController extends Controller
{
    public function bridge(Request $request): JsonResponse
    {
        $entities = Entity::canonical()
            ->where('user_id', $request->user()->id)
            ->whereIn('layer', ['human', 'earth'])
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get();

        $entityIds = $entities->pluck('id');

        $nodes = $entities->map(fn (Entity $e) => [
                'id' => $e->id,
                'layer' => $e->layer,
                'type' => $e->type,
                'label' => $e->canonical_label,
                'intensity' => $e->versions->first()?->payload['intensity'] ?? 0.5,
                'payload' => $e->versions->first()?->payload ?? [],
            ])
            ->values();

        $edges = Relation::where('user_id', $request->user()->id)
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
            ]);

        return response()->json(['nodes' => $nodes, 'edges' => $edges]);
    }
}
