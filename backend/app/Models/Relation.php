<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Relation extends Model
{
    use HasUuids;

    protected $table = 'relations';

    protected $fillable = [
        'user_id',
        'type',
        'source_entity_id',
        'target_entity_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'source_entity_id');
    }

    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'target_entity_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RelationVersion::class)->orderByDesc('valid_from');
    }
}
