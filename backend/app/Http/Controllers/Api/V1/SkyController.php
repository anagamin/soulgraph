<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Models\Entity;
use App\Models\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkyController extends Controller
{
    public function graph(Request $request, Neo4jClient $neo4j): JsonResponse
    {
        $graph = $neo4j->getSkyGraph((string) $request->user()->id);

        if (empty($graph['nodes'])) {
            $graph = $this->fallbackFromMysql($request);
        }

        return response()->json($graph);
    }

    public function patterns(Request $request): JsonResponse
    {
        $patterns = Entity::canonical()
            ->where('user_id', $request->user()->id)
            ->where('type', 'pattern')
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->map(fn (Entity $e) => [
                'id' => $e->id,
                'label' => $e->canonical_label,
                'payload' => $e->versions->first()?->payload ?? [],
                'confidence' => $e->versions->first()?->confidence ?? 0.5,
            ]);

        return response()->json(['patterns' => $patterns]);
    }

    private function fallbackFromMysql(Request $request): array
    {
        $entities = Entity::canonical()
            ->where('user_id', $request->user()->id)
            ->where('layer', 'sky')
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get();

        $nodes = $entities->map(fn (Entity $e) => [
            'id' => $e->id,
            'label' => $e->canonical_label,
            'type' => $e->type,
            'layer' => $e->layer,
            'confidence' => $e->versions->first()?->confidence ?? 0.5,
        ])->values()->all();

        $entityIds = $entities->pluck('id');

        $edges = Relation::where('user_id', $request->user()->id)
            ->whereIn('source_entity_id', $entityIds)
            ->whereIn('target_entity_id', $entityIds)
            ->get()
            ->map(fn ($r) => [
                'source' => $r->source_entity_id,
                'target' => $r->target_entity_id,
                'type' => $r->type,
                'confidence' => 0.5,
            ])->values()->all();

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
