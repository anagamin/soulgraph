<?php

namespace App\Application\Services;

use App\Domain\Shared\EntityLabelNormalizer;
use App\Models\Entity;

class KnownEntitiesProvider
{
    /**
     * @return array<string, list<array{id: string, label: string, type: string, layer: string}>>
     */
    public function forUser(int $userId, int $limitPerType = 30): array
    {
        $entities = Entity::canonical()
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->get(['id', 'type', 'layer', 'canonical_label']);

        $grouped = [];
        foreach ($entities as $entity) {
            if (count($grouped[$entity->type] ?? []) >= $limitPerType) {
                continue;
            }
            $grouped[$entity->type][] = [
                'id' => $entity->id,
                'label' => $entity->canonical_label,
                'type' => $entity->type,
                'layer' => $entity->layer,
            ];
        }

        return $grouped;
    }

    public function formatForPrompt(int $userId): string
    {
        $grouped = $this->forUser($userId);
        if ($grouped === []) {
            return 'Известных сущностей пока нет.';
        }

        $lines = [];
        foreach ($grouped as $type => $items) {
            $entries = array_map(
                fn (array $item) => "{$item['id']} \"{$item['label']}\"",
                $items,
            );
            $lines[] = strtoupper($type).': '.implode(', ', $entries);
        }

        return implode("\n", $lines);
    }
}
