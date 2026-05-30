<?php

namespace App\Infrastructure\Persistence\Qdrant;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class QdrantClient
{
    private string $baseUrl;

    public function __construct()
    {
        $scheme = config('qdrant.https') ? 'https' : 'http';
        $this->baseUrl = "{$scheme}://".config('qdrant.host').':'.config('qdrant.port');
    }

    public function ensureCollection(string $name): void
    {
        $exists = Http::get("{$this->baseUrl}/collections/{$name}");
        if ($exists->successful()) {
            return;
        }

        Http::put("{$this->baseUrl}/collections/{$name}", [
            'vectors' => [
                'size' => config('qdrant.vector_size'),
                'distance' => 'Cosine',
            ],
        ])->throw();
    }

    public function upsert(string $collection, string $pointId, array $vector, array $payload): void
    {
        $this->ensureCollection($collection);

        Http::put("{$this->baseUrl}/collections/{$collection}/points", [
            'points' => [[
                'id' => $pointId,
                'vector' => $vector,
                'payload' => $payload,
            ]],
        ])->throw();
    }

    /**
     * @return array<int, string>
     */
    public function search(int $userId, string $collectionSuffix, string $query, int $limit = 8): array
    {
        $collection = $this->userCollection($userId, $collectionSuffix);

        try {
            $this->ensureCollection($collection);
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    public function userCollection(int $userId, string $suffix): string
    {
        return "user_{$userId}_{$suffix}";
    }

    public function generatePointId(): string
    {
        return (string) Str::uuid();
    }

    public function deleteCollection(string $name): void
    {
        Http::delete("{$this->baseUrl}/collections/{$name}");
    }

    /**
     * @return array{collections: list<array{name: string, points: list<array<string, mixed>>}>}
     */
    public function exportAllCollections(): array
    {
        $collections = [];
        $response = Http::get("{$this->baseUrl}/collections");

        if ($response->failed()) {
            return ['collections' => []];
        }

        foreach ($response->json('result.collections') ?? [] as $item) {
            $name = $item['name'] ?? null;
            if (! $name || ! str_starts_with($name, 'user_')) {
                continue;
            }

            $collections[] = [
                'name' => $name,
                'points' => $this->scrollAllPoints($name),
            ];
        }

        return ['collections' => $collections];
    }

    /**
     * @param  array{collections?: list<array{name: string, points: list<array<string, mixed>>}>}  $data
     */
    public function importCollections(array $data): void
    {
        foreach ($this->listUserCollectionNames() as $name) {
            $this->deleteCollection($name);
        }

        foreach ($data['collections'] ?? [] as $collection) {
            $name = $collection['name'] ?? null;
            if (! $name) {
                continue;
            }

            $this->ensureCollection($name);

            foreach (array_chunk($collection['points'] ?? [], 100) as $chunk) {
                if ($chunk === []) {
                    continue;
                }

                Http::put("{$this->baseUrl}/collections/{$name}/points", [
                    'points' => $chunk,
                ])->throw();
            }
        }
    }

    /**
     * @return list<string>
     */
    private function listUserCollectionNames(): array
    {
        $response = Http::get("{$this->baseUrl}/collections");
        if ($response->failed()) {
            return [];
        }

        $names = [];
        foreach ($response->json('result.collections') ?? [] as $item) {
            $name = $item['name'] ?? null;
            if ($name && str_starts_with($name, 'user_')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scrollAllPoints(string $collection): array
    {
        $points = [];
        $offset = null;

        do {
            $body = [
                'limit' => 100,
                'with_vector' => true,
                'with_payload' => true,
            ];
            if ($offset !== null) {
                $body['offset'] = $offset;
            }

            $response = Http::post("{$this->baseUrl}/collections/{$collection}/points/scroll", $body);
            if ($response->failed()) {
                break;
            }

            $result = $response->json('result') ?? [];
            foreach ($result['points'] ?? [] as $point) {
                $points[] = [
                    'id' => $point['id'],
                    'vector' => $point['vector'],
                    'payload' => $point['payload'] ?? [],
                ];
            }

            $offset = $result['next_page_offset'] ?? null;
        } while ($offset !== null);

        return $points;
    }
}
