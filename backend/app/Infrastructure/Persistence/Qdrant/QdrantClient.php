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
}
