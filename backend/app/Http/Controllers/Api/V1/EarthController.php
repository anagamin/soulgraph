<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\EarthCatalogService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarthController extends Controller
{
    public function __construct(
        private EarthCatalogService $catalog,
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
