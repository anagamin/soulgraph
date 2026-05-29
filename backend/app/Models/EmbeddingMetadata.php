<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmbeddingMetadata extends Model
{
    use HasUuids;

    protected $table = 'embeddings_metadata';

    protected $fillable = [
        'user_id',
        'collection',
        'point_id',
        'source_type',
        'source_id',
        'model',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
