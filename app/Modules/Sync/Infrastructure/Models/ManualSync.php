<?php

namespace App\Modules\Sync\Infrastructure\Models;

use App\Modules\Sync\Application\ManualSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualSync extends Model
{
    protected $fillable = ['website_sync_status', 'synced_at', 'synced_by', 'sync_note'];

    protected $casts = ['synced_at' => 'datetime', 'website_sync_status' => ManualSyncStatus::class];

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'synced_by');
    }
}
