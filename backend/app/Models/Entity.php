<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasMany as HasManyRelations;

class Entity extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'type',
        'layer',
        'canonical_label',
        'normalized_key',
        'merged_into_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'merged_into_id');
    }

    public function mergedEntities(): HasMany
    {
        return $this->hasMany(Entity::class, 'merged_into_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(EntityAlias::class);
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

    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    public function isCanonical(): bool
    {
        return $this->merged_into_id === null;
    }

    /**
     * @return list<string>
     */
    public function familyIds(): array
    {
        $canonicalId = $this->merged_into_id ?? $this->id;

        return self::query()
            ->where('id', $canonicalId)
            ->orWhere('merged_into_id', $canonicalId)
            ->pluck('id')
            ->all();
    }
}
