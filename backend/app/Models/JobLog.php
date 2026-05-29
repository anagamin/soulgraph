<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobLog extends Model
{
    use HasUuids;

    protected $table = 'jobs_logs';

    protected $fillable = [
        'user_id',
        'job_class',
        'payload_summary',
        'attempt',
        'status',
        'exception',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
