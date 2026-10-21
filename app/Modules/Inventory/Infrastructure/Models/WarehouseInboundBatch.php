<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseInboundBatch extends Model
{
    protected $fillable = [
        'warehouse_id','reference','transport_mode','status','carton_count','quantity_total',
        'gross_weight_kg','volume_cbm','shipped_on','eta_date','eta_is_estimated','received_on',
        'source_manifest_checksum','source_filename','note',
    ];

    protected $casts = [
        'carton_count'=>'integer','quantity_total'=>'integer','gross_weight_kg'=>'decimal:4',
        'volume_cbm'=>'decimal:6','shipped_on'=>'date','eta_date'=>'date','eta_is_estimated'=>'boolean',
        'received_on'=>'date',
    ];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function items(): HasMany { return $this->hasMany(WarehouseInboundItem::class, 'batch_id'); }
}
