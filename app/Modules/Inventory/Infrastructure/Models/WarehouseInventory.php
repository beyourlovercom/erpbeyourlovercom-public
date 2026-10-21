<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseInventory extends Model
{
    protected $table = 'warehouse_inventory';
    protected $fillable = ['warehouse_id','product_id','quantity_on_hand','quantity_reserved','inbound_at','location','standard_qoh','expected_restock_qty'];
    protected $casts = ['quantity_on_hand'=>'integer','quantity_reserved'=>'integer','standard_qoh'=>'integer','expected_restock_qty'=>'integer','inbound_at'=>'datetime'];
    protected $appends = ['quantity_available','stay_days'];
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function product(): BelongsTo { return $this->belongsTo(\App\Modules\Products\Infrastructure\Models\Product::class); }
    protected function quantityAvailable(): Attribute { return Attribute::get(fn () => max($this->quantity_on_hand - $this->quantity_reserved, 0)); }
    protected function stayDays(): Attribute { return Attribute::get(fn () => $this->inbound_at ? $this->inbound_at->startOfDay()->diffInDays(now()->startOfDay()) : null); }
}
