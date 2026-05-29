<?php

namespace App\Infrastructure\Logging;

use App\Models\AiLog;
use Illuminate\Support\Str;

class AiLogWriter
{
    public function log(array $data): AiLog
    {
        return AiLog::create([
            'user_id' => $data['user_id'] ?? null,
            'operation' => $data['operation'],
            'prompt_version' => $data['prompt_version'] ?? null,
            'model' => $data['model'] ?? null,
            'input_hash' => isset($data['prompt']) ? hash('sha256', $data['prompt']) : null,
            'prompt' => $data['prompt'] ?? null,
            'response' => $data['response'] ?? null,
            'tokens_in' => $data['tokens_in'] ?? null,
            'tokens_out' => $data['tokens_out'] ?? null,
            'cost_estimate' => $data['cost_estimate'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'status' => $data['status'] ?? 'success',
            'error' => $data['error'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }
}
