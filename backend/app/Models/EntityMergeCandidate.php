<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityMergeCandidate extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'entity_a_id',
        'entity_b_id',
        'similarity',
        'method',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'similarity' => 'float',
        ];
    }

    public function entityA(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_a_id');
    }

    public function entityB(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_b_id');
    }
}
