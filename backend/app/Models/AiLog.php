<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLog extends Model
{
    use HasUuids;

    protected $table = 'ai_logs';

    protected $fillable = [
        'user_id',
        'operation',
        'prompt_version',
        'model',
        'input_hash',
        'prompt',
        'response',
        'tokens_in',
        'tokens_out',
        'cost_estimate',
        'duration_ms',
        'status',
        'error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'cost_estimate' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
