<?php

namespace App\Infrastructure\AI\DTOs;

readonly class ExtractionResult
{
    public function __construct(
        public array $entities,
        public array $relations,
        public array $patterns,
        public array $hypotheses,
        public array $reinterpretations,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            entities: $data['entities'] ?? [],
            relations: $data['relations'] ?? [],
            patterns: $data['patterns'] ?? [],
            hypotheses: $data['hypotheses'] ?? [],
            reinterpretations: $data['reinterpretations'] ?? [],
        );
    }
}
