<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\MessageProcessingDispatcher;
use App\Application\Services\PsychologistChatService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\PsychologistSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PsychologistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = PsychologistSession::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($sessions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => 'nullable|string|max:255']);

        $session = PsychologistSession::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'] ?? 'Психолог ИИ',
            'status' => 'active',
        ]);

        return response()->json($session, 201);
    }

    public function storeMessage(
        Request $request,
        string $id,
        PsychologistChatService $chat,
        MessageProcessingDispatcher $processing,
    ): JsonResponse {
        $data = $request->validate(['content' => 'required|string']);
        $session = PsychologistSession::where('user_id', $request->user()->id)->findOrFail($id);

        $userMessage = Message::create([
            'user_id' => $request->user()->id,
            'psychologist_session_id' => $session->id,
            'role' => 'user',
            'content' => $data['content'],
            'processing_key' => (string) Str::uuid(),
        ]);

        $reply = $chat->reply($session, $data['content']);

        $assistantMessage = Message::create([
            'user_id' => $request->user()->id,
            'psychologist_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $reply,
            'processing_status' => 'skipped',
        ]);

        $processing->dispatch($userMessage->id);

        return response()->json([
            'user_message' => new MessageResource($userMessage),
            'assistant_message' => new MessageResource($assistantMessage),
        ]);
    }

    public function streamMessage(
        Request $request,
        string $id,
        PsychologistChatService $chat,
        MessageProcessingDispatcher $processing,
    ): StreamedResponse {
        $data = $request->validate(['content' => 'required|string']);
        $session = PsychologistSession::where('user_id', $request->user()->id)->findOrFail($id);

        $userMessage = Message::create([
            'user_id' => $request->user()->id,
            'psychologist_session_id' => $session->id,
            'role' => 'user',
            'content' => $data['content'],
            'processing_key' => (string) Str::uuid(),
        ]);

        return response()->stream(function () use ($chat, $session, $data, $userMessage, $processing) {
            $full = '';
            foreach ($chat->streamReply($session, $data['content']) as $chunk) {
                $full .= $chunk;
                echo 'data: '.json_encode(['content' => $chunk])."\n\n";
                ob_flush();
                flush();
            }

            Message::create([
                'user_id' => $session->user_id,
                'psychologist_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $full,
                'processing_status' => 'skipped',
            ]);

            $processing->dispatch($userMessage->id);

            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
