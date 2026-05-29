<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\UserDataResetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function reset(Request $request, UserDataResetService $resetService): JsonResponse
    {
        $request->validate([
            'confirm' => 'required|accepted',
        ]);

        $resetService->reset($request->user());

        return response()->json(['message' => 'Все данные удалены. Можно начать заново.']);
    }
}
