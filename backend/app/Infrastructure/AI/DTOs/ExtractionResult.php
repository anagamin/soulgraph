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
            entities: self::normalizeRecords($data['entities'] ?? [], [
                'temp_id', 'match_entity_id', 'type', 'layer', 'label', 'attributes', 'confidence',
            ]),
            relations: self::normalizeRecords($data['relations'] ?? [], [
                'from', 'to', 'type', 'confidence',
            ]),
            patterns: self::normalizeRecords($data['patterns'] ?? [], [
                'description', 'confidence',
            ]),
            hypotheses: self::normalizeRecords($data['hypotheses'] ?? [], [
                'text', 'confidence',
            ]),
            reinterpretations: self::normalizeRecords($data['reinterpretations'] ?? [], [
                'entity_ref', 'new_meaning', 'evolves_from_temp_id', 'confidence',
            ]),
        );
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function normalizeRecords(mixed $value, array $keys): array
    {
        if (! is_array($value)) {
            return [];
        }

        $records = [];
        foreach ($value as $item) {
            $record = self::coerceRecord($item, $keys);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private static function coerceRecord(mixed $item, array $keys): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        if (array_is_list($item)) {
            $record = [];
            foreach ($keys as $index => $key) {
                if (array_key_exists($index, $item)) {
                    $record[$key] = $item[$index];
                }
            }

            return $record === [] ? null : $record;
        }

        return $item;
    }
}
