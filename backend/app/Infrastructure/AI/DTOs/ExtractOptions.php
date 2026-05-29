<?php

namespace App\Infrastructure\AI\DTOs;

readonly class ExtractOptions
{
    public function __construct(
        public ?string $model = null,
        public float $temperature = 0.2,
    ) {}
}
