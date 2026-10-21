<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseBillImportRow extends Model
{
    protected $fillable = [
        'batch_id','category','source_sheet','source_row_number','transaction_number','outbound_order_number','tracking_number','charged_on','quantity',
        'charge_item_name','charge_amount','row_hash','raw_data','normalized_data',
    ];
    protected $casts = ['charged_on'=>'date','raw_data'=>'array','normalized_data'=>'array'];
    public function batch(): BelongsTo { return $this->belongsTo(WarehouseBillImportBatch::class, 'batch_id'); }
}
