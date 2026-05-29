<?php

namespace App\Infrastructure\Logging;

use App\Models\JobLog;

class JobLogWriter
{
    public function log(array $data): JobLog
    {
        return JobLog::create($data);
    }
}
