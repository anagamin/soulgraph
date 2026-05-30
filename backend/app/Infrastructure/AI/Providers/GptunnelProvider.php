<?php

namespace App\Infrastructure\AI\Providers;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ChatOptions;
use App\Infrastructure\AI\DTOs\ChatResponse;
use App\Infrastructure\AI\DTOs\EmbedOptions;
use App\Infrastructure\AI\DTOs\EmbedResponse;
use App\Infrastructure\AI\DTOs\ExtractOptions;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GptunnelProvider implements AiProviderInterface
{
    private PendingRequest $client;

    public function __construct()
    {
        $this->client = Http::baseUrl(rtrim(config('ai.gptunnel.base_url'), '/'))
            ->withToken(config('ai.gptunnel.api_key') ?? '')
            ->timeout(config('ai.gptunnel.timeout', 120))
            ->retry(config('ai.gptunnel.max_retries', 3), 500);
    }

    public function chat(array $messages, ChatOptions $options): ChatResponse
    {
        $payload = $this->buildChatPayload($messages, $options);
        $timeout = $options->timeoutSeconds ?? config('ai.gptunnel.timeout', 120);
        $response = $this->httpClient($timeout)->post('/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'GPTunnel chat failed ('.$response->status().'): '.mb_substr($response->body(), 0, 500),
            );
        }
        $data = $response->json();

        return new ChatResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            tokensIn: $data['usage']['prompt_tokens'] ?? null,
            tokensOut: $data['usage']['completion_tokens'] ?? null,
            model: $data['model'] ?? $payload['model'],
            raw: $data,
        );
    }

    public function chatStream(array $messages, ChatOptions $options): Generator
    {
        $payload = array_merge($this->buildChatPayload($messages, $options), ['stream' => true]);

        $response = $this->client
            ->withOptions(['stream' => true])
            ->post('/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('GPTunnel stream failed: '.$response->body());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }
                $json = trim(substr($line, 6));
                if ($json === '[DONE]') {
                    return;
                }
                $chunk = json_decode($json, true);
                $delta = $chunk['choices'][0]['delta']['content'] ?? null;
                if ($delta) {
                    yield $delta;
                }
            }
        }
    }

    public function embed(string|array $input, EmbedOptions $options): EmbedResponse
    {
        $response = $this->client->post('/embeddings', [
            'model' => $options->model ?? config('ai.gptunnel.embed_model'),
            'input' => $input,
        ])->throw();

        $data = $response->json();
        $vectors = array_map(
            fn (array $item) => $item['embedding'],
            $data['data'] ?? [],
        );

        return new EmbedResponse(
            vectors: $vectors,
            model: $data['model'] ?? null,
            tokensUsed: $data['usage']['total_tokens'] ?? null,
        );
    }

    public function extract(string $prompt, array $schema, ExtractOptions $options): array
    {
        $response = $this->chat(
            [
                ['role' => 'system', 'content' => 'Return only valid JSON matching the schema. No markdown.'],
                ['role' => 'user', 'content' => $prompt."\n\nSchema:\n".json_encode($schema, JSON_UNESCAPED_UNICODE)],
            ],
            new ChatOptions(
                model: $options->model,
                temperature: $options->temperature,
                responseFormat: 'json_object',
            ),
        );

        $decoded = json_decode($response->content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Failed to parse extraction JSON');
        }

        return $decoded;
    }

    private function httpClient(int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim(config('ai.gptunnel.base_url'), '/'))
            ->withToken(config('ai.gptunnel.api_key') ?? '')
            ->timeout($timeout)
            ->retry(config('ai.gptunnel.max_retries', 3), 500);
    }

    private function buildChatPayload(array $messages, ChatOptions $options): array
    {
        $payload = [
            'model' => $options->model ?? config('ai.gptunnel.chat_model'),
            'messages' => $messages,
            'temperature' => $options->temperature,
        ];

        if ($options->maxTokens) {
            $payload['max_tokens'] = $options->maxTokens;
        }

        if ($options->responseFormat === 'json_object') {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }
}
