<?php

namespace App\Jobs\Concerns;

use Illuminate\Queue\MaxAttemptsExceededException;

trait AutobiographyPipelineJob
{
    public int $tries = 2;

    public int $maxExceptions = 2;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    protected function humanizeFailure(\Throwable $e): string
    {
        if ($e instanceof MaxAttemptsExceededException) {
            return 'Старая задача в очереди исчерпала попытки. Создайте автобиографию заново — при старте очередь для неё очищается автоматически.';
        }

        return $e->getMessage();
    }
}
