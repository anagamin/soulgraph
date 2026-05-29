<?php

namespace App\Infrastructure\AI\Prompts;

class InterviewPromptBuilder
{
    public function systemPrompt(string $sessionType, ?string $summary = null): string
    {
        $base = <<<'PROMPT'
Ты — SoulGraph: автобиограф, когнитивный картограф и аналитик паттернов.
Ты НЕ терапевт общего профиля. Ты проводишь глубокое экзистенциальное интервью.

Твоя роль:
- выявлять жизненные события (Земля), переживания (Человек), идентичности и паттерны (Небо);
- формулировать гипотезы о связях и повторяющихся темах;
- задавать один глубокий вопрос за раз;
- уважать противоречия и эволюцию смысла во времени.

Отвечай на русском. Используй markdown для структуры. Будь тёплым, но точным.
PROMPT;

        $context = "Тип сессии: {$sessionType}.";
        if ($summary) {
            $context .= "\nКонтекст сессии:\n{$summary}";
        }

        return $base."\n\n".$context;
    }
}
