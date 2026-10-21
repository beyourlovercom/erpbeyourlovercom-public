<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryReservationItem extends Model
{
    protected $fillable = ['inventory_reservation_id', 'warehouse_inventory_id', 'product_id', 'quantity'];

    protected $casts = ['quantity' => 'integer'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
    }
}
