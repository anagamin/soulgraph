<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTOs\ChatOptions;
use App\Infrastructure\AI\DTOs\ChatResponse;
use App\Infrastructure\AI\DTOs\EmbedOptions;
use App\Infrastructure\AI\DTOs\EmbedResponse;
use App\Infrastructure\AI\DTOs\ExtractOptions;
use Generator;

interface AiProviderInterface
{
    public function chat(array $messages, ChatOptions $options): ChatResponse;

    /**
     * @return Generator<string>
     */
    public function chatStream(array $messages, ChatOptions $options): Generator;

    public function embed(string|array $input, EmbedOptions $options): EmbedResponse;

    public function extract(string $prompt, array $schema, ExtractOptions $options): array;
}
