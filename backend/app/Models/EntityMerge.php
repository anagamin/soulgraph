<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityMerge extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'canonical_entity_id',
        'merged_entity_id',
        'reason',
        'confidence',
        'method',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
        ];
    }

    public function canonicalEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'canonical_entity_id');
    }

    public function mergedEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'merged_entity_id');
    }
}
