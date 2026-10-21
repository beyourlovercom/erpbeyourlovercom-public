<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventorySyncRow extends Model
{
    protected $table = 'inventory_sync_rows';

    protected $fillable = [
        'sync_batch_id','source_row_no',
        'raw_sku','raw_product_name','raw_warehouse_name','raw_stock_status',
        'raw_stock_count_date','raw_putaway_date','raw_quantity','raw_dimension','raw_total_volume',
        'sku','stock_status','stock_count_at','putaway_date','quantity',
        'product_id','validation_status','validation_message',
    ];

    protected $casts = [
        'stock_count_at' => 'date',
        'putaway_date'   => 'date',
        'quantity'       => 'integer',
        'source_row_no'  => 'integer',
    ];

    public function batch(): BelongsTo   { return $this->belongsTo(InventorySyncBatch::class, 'sync_batch_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
