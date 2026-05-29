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
            entities: self::onlyArrays($data['entities'] ?? []),
            relations: self::onlyArrays($data['relations'] ?? []),
            patterns: self::onlyArrays($data['patterns'] ?? []),
            hypotheses: self::onlyArrays($data['hypotheses'] ?? []),
            reinterpretations: self::onlyArrays($data['reinterpretations'] ?? []),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function onlyArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }
}
