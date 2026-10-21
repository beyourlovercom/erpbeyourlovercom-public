<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CarrierBillingImportRow extends Model
{
    protected $fillable = [
        'batch_id', 'source_row_number', 'tracking_number', 'shipment_id', 'billing_id',
        'decision', 'reason_code', 'actual_cost', 'currency', 'chargeable_weight_kg',
        'billing_document_number', 'provider_reference', 'billed_at', 'row_hash',
        'raw_data', 'normalized_data',
    ];

    protected function casts(): array
    {
        return [
            'actual_cost' => 'decimal:4',
            'chargeable_weight_kg' => 'decimal:3',
            'billed_at' => 'datetime',
            'raw_data' => 'array',
            'normalized_data' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CarrierBillingImportBatch::class, 'batch_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(ShipmentCarrierBilling::class, 'billing_id');
    }
}
