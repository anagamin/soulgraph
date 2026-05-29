<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarthController extends Controller
{
    public function timeline(Request $request): JsonResponse
    {
        $entities = Entity::where('user_id', $request->user()->id)
            ->where('layer', 'earth')
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->map(fn (Entity $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'label' => $e->canonical_label,
                'payload' => $e->versions->first()?->payload ?? [],
                'valid_from' => $e->versions->first()?->valid_from,
                'confidence' => $e->versions->first()?->confidence,
            ]);

        return response()->json([
            'epochs' => $entities->where('type', 'epoch')->values(),
            'events' => $entities->where('type', 'event')->values(),
            'people' => $entities->where('type', 'person')->values(),
            'places' => $entities->where('type', 'place')->values(),
            'relationships' => $entities->where('type', 'relationship')->values(),
        ]);
    }
}
