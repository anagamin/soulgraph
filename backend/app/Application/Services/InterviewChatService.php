<?php

namespace App\Application\Services;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ChatOptions;
use App\Infrastructure\AI\Prompts\InterviewPromptBuilder;
use App\Infrastructure\Logging\AiLogWriter;
use App\Models\InterviewSession;
use App\Models\Message;
use Generator;

class InterviewChatService
{
    public function __construct(
        private AiProviderInterface $ai,
        private InterviewPromptBuilder $prompts,
        private InterviewContextService $context,
        private AiLogWriter $logger,
    ) {}

    public function reply(InterviewSession $session, string $userContent): string
    {
        $messages = $this->buildMessages($session, $userContent);
        $started = microtime(true);
        $response = $this->ai->chat($messages, new ChatOptions(temperature: 0.75));
        $this->logger->log([
            'user_id' => $session->user_id,
            'operation' => 'interview_chat',
            'prompt_version' => config('ai.interview.prompt_version'),
            'model' => $response->model,
            'prompt' => json_encode($messages, JSON_UNESCAPED_UNICODE),
            'response' => $response->content,
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'status' => 'success',
        ]);

        return $response->content;
    }

    public function streamReply(InterviewSession $session, string $userContent): Generator
    {
        $messages = $this->buildMessages($session, $userContent);

        yield from $this->ai->chatStream($messages, new ChatOptions(temperature: 0.75));
    }

    public function generateOpening(InterviewSession $session): string
    {
        if (! $this->prompts->shouldGenerateOpening($session->session_type)) {
            return '';
        }

        $graphContext = $this->context->assemble($session);
        $messages = [
            [
                'role' => 'system',
                'content' => $this->prompts->systemPrompt(
                    $session->session_type,
                    $session->summary,
                    $graphContext ?: null,
                ),
            ],
            [
                'role' => 'user',
                'content' => $this->prompts->openingUserTrigger($session->session_type),
            ],
        ];

        $started = microtime(true);
        $response = $this->ai->chat($messages, new ChatOptions(temperature: 0.75));
        $this->logger->log([
            'user_id' => $session->user_id,
            'operation' => 'interview_opening',
            'prompt_version' => config('ai.interview.prompt_version'),
            'model' => $response->model,
            'response' => $response->content,
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'status' => 'success',
        ]);

        return $response->content;
    }

    private function buildMessages(InterviewSession $session, string $userContent): array
    {
        $history = $session->messages()
            ->orderBy('created_at')
            ->limit(30)
            ->get()
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $graphContext = $this->context->assemble($session);

        return array_merge(
            [[
                'role' => 'system',
                'content' => $this->prompts->systemPrompt(
                    $session->session_type,
                    $session->summary,
                    $graphContext ?: null,
                ),
            ]],
            $history,
            [['role' => 'user', 'content' => $userContent]],
        );
    }
}
