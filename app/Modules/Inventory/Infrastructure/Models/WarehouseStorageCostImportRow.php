<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseStorageCostImportRow extends Model
{
    protected $fillable = [
        'batch_id','source_row_number','reference_number','tracking_number','outbound_units','shipment_id','order_id','decision','reason_code',
        'allocated_source_amount','row_hash','raw_data','normalized_data',
    ];
    protected $casts = ['raw_data'=>'array','normalized_data'=>'array'];
    public function batch(): BelongsTo { return $this->belongsTo(WarehouseStorageCostImportBatch::class, 'batch_id'); }
}
