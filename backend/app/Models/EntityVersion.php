<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'source_message_id',
        'valid_from',
        'valid_until',
        'payload',
        'confidence',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'confidence' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
