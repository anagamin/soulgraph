<?php

namespace App\Application\Services;

use App\Domain\Interview\Enums\InterviewSessionType;
use App\Domain\Shared\TemporalSource;
use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\DTOs\ExtractOptions;
use App\Infrastructure\Logging\AiLogWriter;
use App\Infrastructure\Projection\Neo4jGraphProjector;
use App\Models\Entity;
use App\Models\EntityVersion;
use App\Models\InterviewSession;
use App\Models\Message;
use App\Models\Relation;
use App\Models\RelationVersion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class TimelineReconciliationService
{
    public function __construct(
        private TimelineService $timeline,
        private EntityTemporalService $temporal,
        private AiProviderInterface $ai,
        private Neo4jGraphProjector $graphProjector,
        private AiLogWriter $logger,
    ) {}

    public function reconcileSession(InterviewSession $session): int
    {
        if ($session->session_type !== InterviewSessionType::GeneralStory->value) {
            return 0;
        }

        $messages = $session->messages()
            ->orderBy('created_at')
            ->get();

        if ($messages->count() < 2) {
            return 0;
        }

        $transcript = $messages->map(fn (Message $m) => strtoupper($m->role).': '.$m->content)->implode("\n\n");
        $chronology = $this->timeline->chronologyContextForUser($session->user_id);

        $prompt = <<<PROMPT
Проанализируй сессию «Общая история» и текущий таймлайн. Верни правки хронологии и порядка событий.

Правила:
- Обновляй даты только если пользователь явно подтвердил или исправил в диалоге.
- temporal_source = "user_stated" — если пользователь сам назвал год/период; "reconciliation" — если вывод из подтверждённого контекста сессии.
- Не выдумывай годы — если неясно, укажи только life_period.
- Создавай epoch (главы жизни) для крупных периодов, если их ещё нет.
- Связи: part_of (событие → эпоха), precedes (A раньше B), during (событие внутри эпохи).

=== Текущий таймлайн ===
{$chronology}

=== Диалог сессии ===
{$transcript}
PROMPT;

        $schema = [
            'entity_updates' => [
                [
                    'entity_id' => 'uuid',
                    'approx_year' => null,
                    'life_period' => null,
                    'temporal_source' => 'user_stated',
                ],
            ],
            'new_epochs' => [
                [
                    'label' => 'string',
                    'approx_year' => null,
                    'life_period' => 'детство',
                ],
            ],
            'relations' => [
                [
                    'from_entity_id' => 'uuid',
                    'to_entity_id' => 'uuid',
                    'type' => 'part_of',
                ],
            ],
        ];

        $started = microtime(true);
        try {
            $raw = $this->ai->extract($prompt, $schema, new ExtractOptions(temperature: 0.2));
            $this->logger->log([
                'user_id' => $session->user_id,
                'operation' => 'timeline_reconciliation',
                'prompt_version' => config('ai.extraction.prompt_version'),
                'response' => json_encode($raw, JSON_UNESCAPED_UNICODE),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'status' => 'success',
            ]);
        } catch (\Throwable $e) {
            $this->logger->log([
                'user_id' => $session->user_id,
                'operation' => 'timeline_reconciliation',
                'status' => 'error',
                'response' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            return 0;
        }

        return DB::transaction(function () use ($session, $raw) {
            $applied = 0;
            $user = User::findOrFail($session->user_id);

            foreach ($raw['entity_updates'] ?? [] as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $entityId = Arr::get($update, 'entity_id');
                if (! $entityId) {
                    continue;
                }

                $entity = Entity::canonical()
                    ->where('user_id', $session->user_id)
                    ->find($entityId);

                if (! $entity) {
                    continue;
                }

                $source = Arr::get($update, 'temporal_source', TemporalSource::RECONCILIATION);
                $this->temporal->updateTemporal($user, $entity, [
                    'approx_year' => Arr::get($update, 'approx_year'),
                    'life_period' => Arr::get($update, 'life_period'),
                ], is_string($source) ? $source : TemporalSource::RECONCILIATION);
                $applied++;
            }

            foreach ($raw['new_epochs'] ?? [] as $epochData) {
                if (! is_array($epochData)) {
                    continue;
                }
                $label = Arr::get($epochData, 'label');
                if (! is_string($label) || trim($label) === '') {
                    continue;
                }

                $exists = Entity::canonical()
                    ->where('user_id', $session->user_id)
                    ->where('type', 'epoch')
                    ->where('canonical_label', trim($label))
                    ->exists();

                if ($exists) {
                    continue;
                }

                $entity = Entity::create([
                    'user_id' => $session->user_id,
                    'type' => 'epoch',
                    'layer' => 'earth',
                    'canonical_label' => trim($label),
                ]);

                EntityVersion::create([
                    'entity_id' => $entity->id,
                    'valid_from' => now(),
                    'payload' => array_filter([
                        'approx_year' => Arr::get($epochData, 'approx_year'),
                        'life_period' => Arr::get($epochData, 'life_period'),
                        'temporal_source' => TemporalSource::RECONCILIATION,
                        'summary' => 'Глава жизни, выделенная при выстраивании общей истории.',
                    ], fn ($v) => $v !== null && $v !== ''),
                    'confidence' => 0.85,
                    'is_active' => true,
                ]);

                $this->graphProjector->projectEntity($entity->load('versions'));
                $applied++;
            }

            foreach ($raw['relations'] ?? [] as $rel) {
                if (! is_array($rel)) {
                    continue;
                }
                $from = Arr::get($rel, 'from_entity_id');
                $to = Arr::get($rel, 'to_entity_id');
                $type = Arr::get($rel, 'type');
                if (! $from || ! $to || ! $type) {
                    continue;
                }

                if (! in_array($type, ['part_of', 'precedes', 'follows', 'during'], true)) {
                    continue;
                }

                $exists = Relation::where('user_id', $session->user_id)
                    ->where('type', $type)
                    ->where('source_entity_id', $from)
                    ->where('target_entity_id', $to)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $relation = Relation::create([
                    'user_id' => $session->user_id,
                    'type' => $type,
                    'source_entity_id' => $from,
                    'target_entity_id' => $to,
                ]);

                RelationVersion::create([
                    'relation_id' => $relation->id,
                    'valid_from' => now(),
                    'confidence' => 0.8,
                    'is_active' => true,
                ]);

                $this->graphProjector->projectRelation($relation);
                $applied++;
            }

            return $applied;
        });
    }
}
