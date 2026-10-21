<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentAttempt extends Model
{
    protected $fillable = ['order_id', 'shipment_id', 'attempt_number', 'shipment_status', 'warehouse_id', 'warehouse_selection_source', 'carrier', 'service', 'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by', 'snapshotted_by', 'snapshotted_at', 'evidence_snapshot'];
    protected $casts = ['shipped_at' => 'datetime', 'snapshotted_at' => 'datetime', 'evidence_snapshot' => 'array'];
    public function order(): BelongsTo { return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order'); }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }

    public function warehouse(): BelongsTo { return $this->belongsTo('App\\Modules\\Inventory\\Infrastructure\\Models\\Warehouse'); }

    public function packagingSnapshots(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(ShipmentItemPackagingSnapshot::class); }
}
