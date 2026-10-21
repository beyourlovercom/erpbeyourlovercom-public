<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedView extends Model
{
    protected $table = 'saved_views';

    protected $fillable = [
        'module',
        'name',
        'view_type',
        'created_by',
        'visibility',
        'filters_json',
        'sort_json',
        'is_pinned',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'filters_json' => 'array',
        'sort_json' => 'array',
        'is_pinned' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function scopeVisibleTo(Builder $query, int $userId, bool $canSeeShared = true): Builder
    {
        return $query->where(function (Builder $q) use ($userId, $canSeeShared): void {
            $q->where('created_by', $userId);
            if ($canSeeShared) {
                $q->orWhere('visibility', 'shared');
            }
        });
    }

    public function scopePinned(Builder $query): Builder
    {
        return $query->where('is_pinned', true);
    }
}
