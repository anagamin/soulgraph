<?php

namespace App\Application\Services;

use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Infrastructure\Persistence\Qdrant\QdrantClient;
use App\Models\AiLog;
use App\Models\Autobiography;
use App\Models\EmbeddingMetadata;
use App\Models\Entity;
use App\Models\GraphProjectionLog;
use App\Models\InterviewSession;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\PsychologistSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserDataResetService
{
    public function __construct(
        private Neo4jClient $neo4j,
        private QdrantClient $qdrant,
    ) {}

    public function reset(User $user): void
    {
        $userId = $user->id;

        DB::transaction(function () use ($userId) {
            Entity::where('user_id', $userId)->delete();
            Message::where('user_id', $userId)->delete();
            InterviewSession::where('user_id', $userId)->delete();
            PsychologistSession::where('user_id', $userId)->delete();
            Autobiography::where('user_id', $userId)->delete();
            EmbeddingMetadata::where('user_id', $userId)->delete();
            AiLog::where('user_id', $userId)->delete();
            JobLog::where('user_id', $userId)->delete();
            GraphProjectionLog::where('user_id', $userId)->delete();
        });

        try {
            $this->neo4j->deleteUserGraph((string) $userId);
        } catch (\Throwable) {
            // Neo4j may be unavailable in local dev
        }

        try {
            $this->qdrant->deleteCollection($this->qdrant->userCollection($userId, 'messages'));
        } catch (\Throwable) {
            // Qdrant may be unavailable in local dev
        }
    }
}
