<?php

namespace App\Application\Services;

use App\Jobs\ProcessMessageJob;

class MessageProcessingDispatcher
{
    public function dispatch(string $messageId): void
    {
        if (config('queue.default') === 'sync') {
            ProcessMessageJob::dispatchSync($messageId);

            return;
        }

        ProcessMessageJob::dispatch($messageId)->afterCommit();
    }
}
