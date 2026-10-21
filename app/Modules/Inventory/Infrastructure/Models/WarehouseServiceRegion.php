<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseServiceRegion extends Model
{
    protected $fillable = ['warehouse_id','country_code','priority'];
    protected $casts = ['priority'=>'integer'];
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
}
