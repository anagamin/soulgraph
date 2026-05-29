<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasMany as HasManyRelations;

class Entity extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'type', 'layer', 'canonical_label'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EntityVersion::class)->orderByDesc('valid_from');
    }

    public function activeVersion(): ?EntityVersion
    {
        return $this->versions()->where('is_active', true)->latest('valid_from')->first();
    }

    public function outgoingRelations(): HasManyRelations
    {
        return $this->hasMany(Relation::class, 'source_entity_id');
    }

    public function incomingRelations(): HasManyRelations
    {
        return $this->hasMany(Relation::class, 'target_entity_id');
    }
}
