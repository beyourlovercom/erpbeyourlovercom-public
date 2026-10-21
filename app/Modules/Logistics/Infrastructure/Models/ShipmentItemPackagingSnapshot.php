<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class ShipmentItemPackagingSnapshot extends Model
{
    protected $fillable = [
        'shipment_id', 'order_item_id', 'product_id', 'product_packaging_profile_id',
        'packaging_measurement_record_id', 'packaging_method_key', 'packaging_method_label',
        'packaging_length_cm', 'packaging_width_cm', 'packaging_height_cm',
        'packaged_weight_kg', 'measured_at', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'packaging_length_cm' => 'decimal:2',
            'packaging_width_cm' => 'decimal:2',
            'packaging_height_cm' => 'decimal:2',
            'packaged_weight_kg' => 'decimal:3',
            'measured_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }
}
