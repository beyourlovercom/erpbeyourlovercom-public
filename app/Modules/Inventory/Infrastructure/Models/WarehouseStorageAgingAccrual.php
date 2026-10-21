<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseStorageAgingAccrual extends Model
{
    protected $fillable = [
        'warehouse_id','product_id','stock_status','putaway_date','aging_month_index','cycle_started_on',
        'quantity_snapshot','unit_rate_cny','amount_cny','source_sync_batch_id','source_reference',
        'accrual_as_of','is_backfill','accrued_at',
    ];

    protected $casts = [
        'putaway_date' => 'date',
        'aging_month_index' => 'integer',
        'cycle_started_on' => 'date',
        'quantity_snapshot' => 'integer',
        'unit_rate_cny' => 'decimal:2',
        'amount_cny' => 'decimal:2',
        'source_sync_batch_id' => 'integer',
        'accrual_as_of' => 'date',
        'is_backfill' => 'boolean',
        'accrued_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function sourceBatch(): BelongsTo { return $this->belongsTo(InventorySyncBatch::class, 'source_sync_batch_id'); }
}
