<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\EarthCatalogService;
use App\Application\Services\EntityTemporalService;
use App\Models\Entity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarthController extends Controller
{
    public function __construct(
        private EarthCatalogService $catalog,
        private EntityTemporalService $temporal,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        return response()->json($this->catalog->catalog($request->user()->id));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $detail = $this->catalog->entityDetail($request->user()->id, $id);

        if (! $detail) {
            return response()->json(['message' => 'Entity not found'], 404);
        }

        return response()->json($detail);
    }

    public function updateTemporal(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'approx_year' => 'nullable|integer|min:1900|max:2100',
            'occurred_at' => 'nullable|date',
            'life_period' => 'nullable|string|max:100',
        ]);

        $entity = Entity::canonical()
            ->where('user_id', $request->user()->id)
            ->where('layer', 'earth')
            ->findOrFail($id);

        $this->temporal->updateTemporal($request->user(), $entity, $data);

        $detail = $this->catalog->entityDetail($request->user()->id, $id);

        return response()->json($detail);
    }

    /** @deprecated Use catalog() */
    public function timeline(Request $request): JsonResponse
    {
        $data = $this->catalog->catalog($request->user()->id);

        return response()->json([
            'epochs' => $data['epochs'],
            'events' => $data['events'],
            'people' => $data['people'],
            'places' => $data['places'],
            'relationships' => $data['relationships'],
        ]);
    }
}
