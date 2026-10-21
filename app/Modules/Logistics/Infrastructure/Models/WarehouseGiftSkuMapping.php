<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseGiftSkuMapping extends Model
{
    protected $fillable = ['warehouse_id', 'source_sku', 'fulfillment_product_id', 'priority', 'is_active', 'note'];

    protected $casts = ['warehouse_id' => 'integer', 'fulfillment_product_id' => 'integer', 'priority' => 'integer', 'is_active' => 'boolean'];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'fulfillment_product_id'); }
}
