<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseInventoryLotConsumption extends Model
{
    protected $fillable = [
        'shipment_id', 'order_id', 'order_item_id', 'warehouse_id', 'product_id', 'stock_status',
        'putaway_date', 'source_sync_batch_id', 'source_inventory_lot_id', 'quantity', 'aging_months_charged',
        'unit_storage_cost_cny', 'allocated_storage_cost_cny', 'consumed_at', 'lot_business_key',
        'source_reference', 'captured_at',
    ];

    protected $casts = [
        'shipment_id' => 'integer',
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'putaway_date' => 'date',
        'source_sync_batch_id' => 'integer',
        'source_inventory_lot_id' => 'integer',
        'quantity' => 'integer',
        'aging_months_charged' => 'integer',
        'unit_storage_cost_cny' => 'decimal:2',
        'allocated_storage_cost_cny' => 'decimal:2',
        'consumed_at' => 'datetime',
        'captured_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(InventorySyncBatch::class, 'source_sync_batch_id');
    }
}
