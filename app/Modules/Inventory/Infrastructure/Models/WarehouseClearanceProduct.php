<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inventory OS business view of the legacy warehouse_gift_sku_mappings table.
 * Clearance membership is the business source of truth; Logistics reads the same rows as Gift Pool projection.
 */
final class WarehouseClearanceProduct extends Model
{
    protected $table = 'warehouse_gift_sku_mappings';

    protected $fillable = ['warehouse_id', 'source_sku', 'fulfillment_product_id', 'priority', 'is_active', 'note'];

    protected $casts = ['warehouse_id' => 'integer', 'fulfillment_product_id' => 'integer', 'priority' => 'integer', 'is_active' => 'boolean'];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'fulfillment_product_id'); }
}
