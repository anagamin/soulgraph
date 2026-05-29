<?php

namespace App\Application\Services;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ChatOptions;
use App\Infrastructure\Logging\AiLogWriter;
use App\Models\Message;
use App\Models\PsychologistSession;
use Generator;

class PsychologistChatService
{
    public function __construct(
        private AiProviderInterface $ai,
        private ContextAssemblyService $context,
        private AiLogWriter $logger,
    ) {}

    public function reply(PsychologistSession $session, string $userContent): string
    {
        $messages = $this->buildMessages($session, $userContent);
        $started = microtime(true);
        $response = $this->ai->chat($messages, new ChatOptions(temperature: 0.65));
        $this->logger->log([
            'user_id' => $session->user_id,
            'operation' => 'psychologist_chat',
            'prompt_version' => config('ai.psychologist.prompt_version'),
            'response' => $response->content,
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'status' => 'success',
        ]);

        return $response->content;
    }

    public function streamReply(PsychologistSession $session, string $userContent): Generator
    {
        yield from $this->ai->chatStream($this->buildMessages($session, $userContent), new ChatOptions(temperature: 0.65));
    }

    private function buildMessages(PsychologistSession $session, string $userContent): array
    {
        $context = $this->context->assembleForUser($session->user, $userContent);

        $history = $session->messages()
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        return array_merge(
            [[
                'role' => 'system',
                'content' => "Ты — персональный психологический ИИ SoulGraph. Используй контекст графа личности.\n\n{$context}",
            ]],
            $history,
            [['role' => 'user', 'content' => $userContent]],
        );
    }
}
