<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Autobiography extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'title',
        'style',
        'scope',
        'scope_params',
        'content',
        'version',
        'parent_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'scope_params' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
