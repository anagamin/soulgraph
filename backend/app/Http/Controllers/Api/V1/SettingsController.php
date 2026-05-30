<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\EntityTemporalService;
use App\Application\Services\UserDataResetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function updateProfile(Request $request, EntityTemporalService $temporal): JsonResponse
    {
        $data = $request->validate([
            'birth_year' => 'nullable|integer|min:1900|max:2100',
            'birth_place' => 'nullable|string|max:255',
        ]);

        $user = $temporal->updateProfileAnchors(
            $request->user(),
            $data['birth_year'] ?? null,
            $data['birth_place'] ?? null,
        );

        return response()->json(['user' => $user]);
    }

    public function reset(Request $request, UserDataResetService $resetService): JsonResponse
    {
        $request->validate([
            'confirm' => 'required|accepted',
        ]);

        $resetService->reset($request->user());

        return response()->json(['message' => 'Все данные удалены. Можно начать заново.']);
    }
}
