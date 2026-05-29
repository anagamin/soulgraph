<?php

namespace App\Infrastructure\AI\DTOs;

readonly class ChatResponse
{
    public function __construct(
        public string $content,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?string $model = null,
        public ?array $raw = null,
    ) {}
}
