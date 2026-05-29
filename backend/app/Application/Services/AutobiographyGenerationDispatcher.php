<?php

namespace App\Application\Services;

use App\Jobs\GenerateAutobiographyJob;

class AutobiographyGenerationDispatcher
{
    public function dispatch(string $autobiographyId): void
    {
        if (config('queue.default') === 'sync') {
            GenerateAutobiographyJob::dispatchSync($autobiographyId);

            return;
        }

        GenerateAutobiographyJob::dispatch($autobiographyId);
    }
}
