<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Warehouse extends Model
{
    protected $fillable = ['slug','code','name','country_code','address_line1','address_line2','city','state_region','postal_code','timezone','type','ownership_type','is_domestic','is_active','priority','fulfillment_available_on','replenishment_lead_time_days','replenishment_safety_stock_days','replenishment_target_cover_days','billing_markdown'];
    protected $casts = ['is_domestic' => 'boolean', 'is_active' => 'boolean', 'priority' => 'integer', 'fulfillment_available_on' => 'date', 'replenishment_lead_time_days' => 'integer', 'replenishment_safety_stock_days' => 'integer', 'replenishment_target_cover_days' => 'integer'];
    public function inventories(): HasMany { return $this->hasMany(WarehouseInventory::class); }
    public function serviceRegions(): HasMany { return $this->hasMany(WarehouseServiceRegion::class); }
}
