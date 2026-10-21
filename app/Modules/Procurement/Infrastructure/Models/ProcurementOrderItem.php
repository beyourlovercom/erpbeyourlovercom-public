<?php

namespace App\Modules\Procurement\Infrastructure\Models;

use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementOrderItem extends Model
{
    protected $fillable = ['procurement_order_id', 'source_order_item_id', 'product_id', 'sku', 'product_summary', 'source_quantity', 'purchase_url', 'purchase_size', 'purchase_quantity'];
    protected $casts = ['source_quantity' => 'integer', 'purchase_quantity' => 'integer'];
    public function procurementOrder(): BelongsTo { return $this->belongsTo(ProcurementOrder::class); }
    public function sourceOrderItem(): BelongsTo { return $this->belongsTo(OrderItem::class, 'source_order_item_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
