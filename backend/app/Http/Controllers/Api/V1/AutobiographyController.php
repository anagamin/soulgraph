<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAutobiographyJob;
use App\Models\Autobiography;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AutobiographyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Autobiography::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'style' => 'required|string',
            'scope' => 'required|string',
            'scope_params' => 'nullable|array',
        ]);

        $autobiography = Autobiography::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'style' => $data['style'],
            'scope' => $data['scope'],
            'scope_params' => $data['scope_params'] ?? null,
            'content' => '',
            'status' => 'pending',
        ]);

        GenerateAutobiographyJob::dispatch($autobiography->id);

        return response()->json($autobiography, 202);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = Autobiography::where('user_id', $request->user()->id)->findOrFail($id);

        return response()->json($item);
    }

    public function createVersion(Request $request, string $id): JsonResponse
    {
        $parent = Autobiography::where('user_id', $request->user()->id)->findOrFail($id);

        $version = Autobiography::create([
            'user_id' => $request->user()->id,
            'title' => $parent->title,
            'style' => $request->input('style', $parent->style),
            'scope' => $parent->scope,
            'scope_params' => $parent->scope_params,
            'content' => $request->input('content', $parent->content),
            'version' => $parent->version + 1,
            'parent_id' => $parent->id,
            'status' => 'completed',
        ]);

        return response()->json($version, 201);
    }

    public function compare(Request $request, string $id, string $otherId): JsonResponse
    {
        $a = Autobiography::where('user_id', $request->user()->id)->findOrFail($id);
        $b = Autobiography::where('user_id', $request->user()->id)->findOrFail($otherId);

        return response()->json([
            'a' => $a,
            'b' => $b,
            'diff_length' => abs(strlen($a->content) - strlen($b->content)),
        ]);
    }

    public function exportMarkdown(Request $request, string $id)
    {
        $item = Autobiography::where('user_id', $request->user()->id)->findOrFail($id);

        return response($item->content, 200, [
            'Content-Type' => 'text/markdown',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($item->title).'.md"',
        ]);
    }
}
