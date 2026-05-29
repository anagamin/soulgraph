<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'interview_session_id',
        'psychologist_session_id',
        'role',
        'content',
        'reasoning_metadata',
        'processing_status',
        'processing_key',
    ];

    protected function casts(): array
    {
        return [
            'reasoning_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }

    public function psychologistSession(): BelongsTo
    {
        return $this->belongsTo(PsychologistSession::class);
    }
}
