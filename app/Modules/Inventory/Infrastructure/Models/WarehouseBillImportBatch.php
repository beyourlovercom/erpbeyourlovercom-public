<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseBillImportBatch extends Model
{
    protected $fillable = [
        'provider','warehouse_id','original_filename','source_checksum','parser_version','plan_checksum','status','invoice_number','client_name','invoice_date',
        'currency','invoice_total','shipping_total','parcel_outbound_total','storage_total','total_rows','shipping_rows','parcel_outbound_rows','storage_rows','applied_by','applied_at',
    ];
    protected $casts = ['invoice_date'=>'date','applied_at'=>'datetime'];
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function rows(): HasMany { return $this->hasMany(WarehouseBillImportRow::class, 'batch_id'); }
}
