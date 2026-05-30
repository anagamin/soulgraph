<?php

namespace App\Jobs;

use App\Application\Services\TimelineReconciliationService;
use App\Infrastructure\Logging\JobLogWriter;
use App\Models\InterviewSession;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TimelineReconciliationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $messageId) {}

    public function handle(TimelineReconciliationService $reconciliation, JobLogWriter $jobLog): void
    {
        $started = microtime(true);
        $message = Message::find($this->messageId);

        if (! $message || ! $message->interview_session_id) {
            return;
        }

        $session = InterviewSession::find($message->interview_session_id);
        if (! $session) {
            return;
        }

        try {
            $applied = $reconciliation->reconcileSession($session);
            $jobLog->log([
                'user_id' => $message->user_id,
                'job_class' => self::class,
                'payload_summary' => ['message_id' => $message->id, 'applied' => $applied],
                'status' => 'success',
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            $jobLog->log([
                'user_id' => $message->user_id,
                'job_class' => self::class,
                'status' => 'error',
                'exception' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }
}
