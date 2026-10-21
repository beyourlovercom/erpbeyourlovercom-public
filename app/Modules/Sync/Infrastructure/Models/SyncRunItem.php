<?php

namespace App\Modules\Sync\Infrastructure\Models;

use App\Modules\Sync\Application\SyncRunItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SyncRunItem extends Model
{
    protected $fillable = [
        'sync_run_id', 'sequence', 'operation', 'order_id', 'shipment_id', 'product_id',
        'outbox_id', 'provider_reference', 'status', 'result_code',
        'error_code', 'error_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'status' => SyncRunItemStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Logistics\\Infrastructure\\Models\\Shipment');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Products\\Infrastructure\\Models\\Product');
    }

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(SyncOutbox::class, 'outbox_id');
    }
}
