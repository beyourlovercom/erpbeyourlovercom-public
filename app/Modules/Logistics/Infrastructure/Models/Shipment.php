<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shipment extends Model
{
    protected $fillable = [
        'warehouse_id', 'warehouse_selection_source', 'shipment_status',
        'carrier', 'service', 'carrier_service_id', 'carrier_selection_source', 'tracking_number',
        'shipped_at', 'logistics_note', 'handled_by',
    ];

    protected $casts = ['shipped_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Inventory\\Infrastructure\\Models\\Warehouse');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'handled_by');
    }

    public function carrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class, 'carrier_service_id');
    }

    public function billingEvidence(): HasOne
    {
        return $this->hasOne(ShipmentCarrierBilling::class);
    }

    public function overseasHandlingEvidence(): HasOne
    {
        return $this->hasOne(ShipmentOverseasHandlingBilling::class);
    }

    public function overseaReview(): HasOne
    {
        return $this->hasOne(OverseaWarehouseReview::class, 'order_id', 'order_id');
    }
}
