<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseInventoryLot extends Model
{
    protected $table = 'warehouse_inventory_lots';

    protected $fillable = [
        'warehouse_id','product_id','stock_status','putaway_date','quantity','sync_batch_id',
    ];

    protected $casts = [
        'putaway_date' => 'date',
        'quantity'     => 'integer',
    ];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function product(): BelongsTo   { return $this->belongsTo(Product::class); }
    public function batch(): BelongsTo     { return $this->belongsTo(InventorySyncBatch::class, 'sync_batch_id'); }
}
