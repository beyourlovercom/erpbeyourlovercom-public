<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseRestockPlanItem extends Model
{
    protected $fillable = [
        'restock_plan_id', 'product_id', 'product_sku', 'label_sku', 'label_description', 'product_name',
        'final_restock_qty', 'formula_restock_qty', 'manual_restock_qty', 'shipped_120d_qty',
        'quantity_available', 'quantity_on_hand', 'quantity_reserved', 'units_per_carton', 'carton_count',
        'packaging_profile_id', 'packaging_measurement_record_id', 'packaging_method_label',
        'packaging_length_cm', 'packaging_width_cm', 'packaging_height_cm', 'packaged_weight_kg',
        'logistics_weight_kg', 'weight_source', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'restock_plan_id' => 'integer',
            'product_id' => 'integer',
            'final_restock_qty' => 'integer',
            'formula_restock_qty' => 'integer',
            'manual_restock_qty' => 'integer',
            'shipped_120d_qty' => 'integer',
            'quantity_available' => 'integer',
            'quantity_on_hand' => 'integer',
            'quantity_reserved' => 'integer',
            'units_per_carton' => 'integer',
            'carton_count' => 'integer',
            'packaging_profile_id' => 'integer',
            'packaging_measurement_record_id' => 'integer',
            'packaging_length_cm' => 'decimal:2',
            'packaging_width_cm' => 'decimal:2',
            'packaging_height_cm' => 'decimal:2',
            'packaged_weight_kg' => 'decimal:3',
            'logistics_weight_kg' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo { return $this->belongsTo(WarehouseRestockPlan::class, 'restock_plan_id'); }
}
