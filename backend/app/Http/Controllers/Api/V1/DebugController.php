<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\GraphProjectionLog;
use App\Models\JobLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class DebugController extends Controller
{
    public function aiLogs(Request $request): JsonResponse
    {
        return response()->json(
            AiLog::where('user_id', $request->user()->id)
                ->latest()
                ->paginate(50)
        );
    }

    public function jobsLogs(Request $request): JsonResponse
    {
        return response()->json(
            JobLog::where('user_id', $request->user()->id)
                ->latest()
                ->paginate(50)
        );
    }

    public function projections(Request $request): JsonResponse
    {
        return response()->json(
            GraphProjectionLog::where('user_id', $request->user()->id)
                ->latest()
                ->paginate(50)
        );
    }

    public function rebuildGraph(Request $request): JsonResponse
    {
        Artisan::call('soulgraph:rebuild-graph', ['userId' => $request->user()->id]);

        return response()->json(['message' => 'Пересборка графа запущена']);
    }
}
