<?php

namespace App\Infrastructure\AI\DTOs;

readonly class EmbedOptions
{
    public function __construct(
        public ?string $model = null,
    ) {}
}
