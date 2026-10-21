<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class InventoryAdjustment extends Model
{
    protected $fillable = ['warehouse_id','product_id','warehouse_inventory_id','quantity_before','quantity_after','reserved_before','reserved_after','actor_user_id','reason'];
}
