<?php

namespace App\Infrastructure\AI\DTOs;

readonly class ChatOptions
{
    public function __construct(
        public ?string $model = null,
        public float $temperature = 0.7,
        public ?int $maxTokens = null,
        public ?string $responseFormat = null,
    ) {}
}
