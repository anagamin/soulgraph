<?php

namespace App\Application\Services;

use App\Domain\Interview\Enums\InterviewSessionType;
use App\Infrastructure\Persistence\Neo4j\Neo4jClient;
use App\Models\InterviewSession;
use App\Models\Message;
use App\Models\User;

class InterviewContextService
{
    public function __construct(
        private Neo4jClient $neo4j,
        private TimelineService $timeline,
    ) {}

    public function assemble(InterviewSession $session): string
    {
        $parts = [];
        $userId = (string) $session->user_id;

        if ($session->session_type === InterviewSessionType::GeneralStory->value) {
            $user = User::find($session->user_id);
            if ($user?->birth_year) {
                $place = $user->birth_place ? ", {$user->birth_place}" : '';
                $parts[] = "=== Якоря профиля ===\nГод рождения: {$user->birth_year}{$place}";
            } else {
                $parts[] = '=== Якоря профиля ===\nГод рождения не указан — приоритет: узнать год рождения и место, где выросли.';
            }

            $chronology = $this->timeline->chronologyContextForUser($session->user_id);
            if ($chronology) {
                $parts[] = $chronology;
            }
        } else {
            $graphSnippet = $this->neo4j->getContextSnippet($userId, 20);
            if ($graphSnippet) {
                $parts[] = "Известные узлы и связи:\n{$graphSnippet}";
            }
        }

        $gaps = $this->neo4j->getGraphGaps($userId);
        if ($gaps) {
            $parts[] = "Пробелы графа (приоритет для вопросов):\n{$gaps}";
        }

        $sessionContext = $this->sessionExtractions($session);
        if ($sessionContext) {
            $parts[] = "Текущая сессия:\n{$sessionContext}";
        }

        if ($session->session_type === InterviewSessionType::GeneralStory->value) {
            $parts[] = $this->generalStoryTurnHint($session);
        }

        return implode("\n\n", $parts);
    }

    private function generalStoryTurnHint(InterviewSession $session): string
    {
        $userTurns = $session->messages()->where('role', 'user')->count();

        if ($userTurns === 0) {
            return <<<'HINT'
=== Якорные вопросы (порядок на старте) ===
1. Год рождения
2. Где выросли
3. 3–5 крупных глав жизни
4. Самый ранний переломный момент
HINT;
        }

        if ($userTurns > 0 && $userTurns % 5 === 0) {
            return <<<'HINT'
=== Режим подтверждения каркаса ===
Сейчас выведи markdown-таблицу текущего каркаса:

| Период | Годы/возраст | Ключевые события | Переживания | Паттерны |
|--------|--------------|------------------|-------------|----------|

Попроси подтвердить или поправить — затем один уточняющий вопрос про самый неясный период.
HINT;
        }

        return '';
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
