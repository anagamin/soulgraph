<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\InterviewChatService;
use App\Application\Services\MessageProcessingDispatcher;
use App\Http\Controllers\Controller;
use App\Http\Resources\InterviewSessionResource;
use App\Http\Resources\MessageResource;
use App\Models\InterviewSession;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InterviewSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = $request->user()
            ->interviewSessions()
            ->latest()
            ->paginate(20);

        return response()->json(InterviewSessionResource::collection($sessions));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'session_type' => 'required|string',
            'mode' => 'nullable|string|in:ai_interview,upload',
        ]);

        $session = $request->user()->interviewSessions()->create([
            'title' => $data['title'],
            'session_type' => $data['session_type'],
            'mode' => $data['mode'] ?? 'ai_interview',
            'status' => 'active',
        ]);

        return response()->json(new InterviewSessionResource($session), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $session = $request->user()->interviewSessions()->with('messages')->findOrFail($id);

        return response()->json(new InterviewSessionResource($session));
    }

    public function storeMessage(
        Request $request,
        string $id,
        InterviewChatService $chat,
        MessageProcessingDispatcher $processing,
    ): JsonResponse {
        $data = $request->validate(['content' => 'required|string']);
        $session = $request->user()->interviewSessions()->findOrFail($id);

        $userMessage = $this->createUserMessage($session, $data['content']);

        $assistantContent = $chat->reply($session, $data['content']);
        $assistantMessage = $this->createAssistantMessage($session, $assistantContent);

        $processing->dispatch($userMessage->id);

        return response()->json([
            'user_message' => new MessageResource($userMessage),
            'assistant_message' => new MessageResource($assistantMessage),
        ]);
    }

    public function streamMessage(
        Request $request,
        string $id,
        InterviewChatService $chat,
        MessageProcessingDispatcher $processing,
    ): StreamedResponse {
        $data = $request->validate(['content' => 'required|string']);
        $session = $request->user()->interviewSessions()->findOrFail($id);

        $userMessage = $this->createUserMessage($session, $data['content']);

        return response()->stream(function () use ($chat, $session, $data, $userMessage, $processing) {
            $full = '';
            foreach ($chat->streamReply($session, $data['content']) as $chunk) {
                $full .= $chunk;
                echo 'data: '.json_encode(['content' => $chunk])."\n\n";
                ob_flush();
                flush();
            }

            $this->createAssistantMessage($session, $full);
            $processing->dispatch($userMessage->id);
            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function upload(Request $request, string $id, MessageProcessingDispatcher $processing): JsonResponse
    {
        $data = $request->validate(['content' => 'required|string']);
        $session = $request->user()->interviewSessions()->findOrFail($id);

        $message = Message::create([
            'user_id' => $request->user()->id,
            'interview_session_id' => $session->id,
            'role' => 'user',
            'content' => $data['content'],
            'processing_key' => (string) Str::uuid(),
        ]);

        $processing->dispatch($message->id);

        return response()->json(new MessageResource($message), 201);
    }

    public function extractions(Request $request, string $id): JsonResponse
    {
        $session = $request->user()->interviewSessions()->findOrFail($id);

        $entities = $request->user()->entities()
            ->whereHas('versions', fn ($q) => $q->whereIn(
                'source_message_id',
                $session->messages()->pluck('id')
            ))
            ->with(['versions' => fn ($q) => $q->where('is_active', true)])
            ->limit(50)
            ->get();

        return response()->json(['entities' => $entities]);
    }

    private function createUserMessage(InterviewSession $session, string $content): Message
    {
        return Message::create([
            'user_id' => $session->user_id,
            'interview_session_id' => $session->id,
            'role' => 'user',
            'content' => $content,
            'processing_key' => (string) Str::uuid(),
        ]);
    }

    private function createAssistantMessage(InterviewSession $session, string $content): Message
    {
        return Message::create([
            'user_id' => $session->user_id,
            'interview_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $content,
            'processing_status' => 'skipped',
        ]);
    }
}
