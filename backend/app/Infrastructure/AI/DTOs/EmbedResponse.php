<?php

namespace App\Infrastructure\AI\DTOs;

readonly class EmbedResponse
{
    /**
     * @param  array<int, array<float>>  $vectors
     */
    public function __construct(
        public array $vectors,
        public ?string $model = null,
        public ?int $tokensUsed = null,
    ) {}
}
