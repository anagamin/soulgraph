<?php

namespace App\Infrastructure\Logging;

use App\Models\GraphProjectionLog;

class ProjectionLogWriter
{
    public function log(array $data): GraphProjectionLog
    {
        return GraphProjectionLog::create($data);
    }
}
