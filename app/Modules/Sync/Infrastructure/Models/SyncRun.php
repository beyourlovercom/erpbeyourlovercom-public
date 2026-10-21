<?php

namespace App\Modules\Sync\Infrastructure\Models;

use App\Modules\Sync\Application\SyncRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SyncRun extends Model
{
    protected $fillable = [
        'run_type', 'provider_key', 'connection_key', 'status',
        'requested_items', 'processed_items', 'succeeded_items',
        'retrying_items', 'failed_items', 'conflict_items', 'skipped_items',
        'initiated_by', 'context', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncRunStatus::class,
            'context' => 'array',
            'requested_items' => 'integer',
            'processed_items' => 'integer',
            'succeeded_items' => 'integer',
            'retrying_items' => 'integer',
            'failed_items' => 'integer',
            'conflict_items' => 'integer',
            'skipped_items' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'initiated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SyncRunItem::class, 'sync_run_id')->orderBy('sequence');
    }
}
