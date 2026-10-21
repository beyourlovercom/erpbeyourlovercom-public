<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InventoryReservation extends Model
{
    protected $fillable = [
        'reservation_key', 'payload_hash', 'order_id', 'warehouse_id', 'status', 'expires_at',
        'committed_at', 'released_at', 'expired_at', 'release_reason', 'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime', 'committed_at' => 'datetime', 'released_at' => 'datetime', 'expired_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryReservationItem::class, 'inventory_reservation_id');
    }
}
