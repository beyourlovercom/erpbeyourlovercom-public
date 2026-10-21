<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShipmentCarrierBilling extends Model
{
    protected $table = 'shipment_carrier_billing';

    protected $fillable = [
        'shipment_id', 'chargeable_weight_kg', 'actual_cost', 'currency',
        'billing_document_number', 'provider_reference', 'delivery_status',
        'data_source', 'billed_at', 'raw_evidence',
    ];

    protected function casts(): array
    {
        return [
            'chargeable_weight_kg' => 'decimal:3',
            'actual_cost' => 'decimal:4',
            'billed_at' => 'datetime',
            'raw_evidence' => 'array',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
