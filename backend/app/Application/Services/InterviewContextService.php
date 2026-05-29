<?php

namespace App\Application\Services;

use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Models\InterviewSession;
use App\Models\Message;

class InterviewContextService
{
    public function __construct(
        private Neo4jClient $neo4j,
    ) {}

    public function assemble(InterviewSession $session): string
    {
        $parts = [];
        $userId = (string) $session->user_id;

        $graphSnippet = $this->neo4j->getContextSnippet($userId, 20);
        if ($graphSnippet) {
            $parts[] = "Известные узлы и связи:\n{$graphSnippet}";
        }

        $gaps = $this->neo4j->getGraphGaps($userId);
        if ($gaps) {
            $parts[] = "Пробелы графа (приоритет для вопросов):\n{$gaps}";
        }

        $sessionContext = $this->sessionExtractions($session);
        if ($sessionContext) {
            $parts[] = "Текущая сессия:\n{$sessionContext}";
        }

        return implode("\n\n", $parts);
    }

    private function sessionExtractions(InterviewSession $session): string
    {
        $messages = $session->messages()
            ->where('role', 'user')
            ->where('processing_status', 'completed')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($messages->isEmpty()) {
            return '';
        }

        $lines = [];
        $hypotheses = [];

        foreach ($messages as $message) {
            foreach ($message->reasoning_metadata['hypotheses'] ?? [] as $hypothesis) {
                $text = is_array($hypothesis) ? ($hypothesis['text'] ?? null) : null;
                if ($text) {
                    $hypotheses[] = $text;
                }
            }
        }

        if ($hypotheses) {
            $lines[] = 'Гипотезы для проверки:';
            foreach (array_unique($hypotheses) as $hypothesis) {
                $lines[] = "- {$hypothesis}";
            }
        }

        $entityCount = $messages->sum(
            fn (Message $m) => $m->reasoning_metadata['entities_count'] ?? 0,
        );
        if ($entityCount > 0) {
            $lines[] = "Извлечено сущностей в сессии: {$entityCount}";
        }

        return implode("\n", $lines);
    }
}
