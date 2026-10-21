<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseCostEntry extends Model
{
    protected $fillable = ['warehouse_id','cost_month','category','amount','currency','note','source_type','source_reference','created_by_user_id'];
    protected $casts = ['cost_month' => 'date', 'amount' => 'decimal:2', 'created_by_user_id' => 'integer'];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
}
