<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseStorageCostImportBatch extends Model
{
    protected $fillable = [
        'provider','warehouse_id','billing_period_start','billing_period_end','original_filename','source_checksum','parser_version','plan_checksum','status',
        'storage_pool_amount','currency','allocation_rate_per_unit','total_rows','ready_count','excluded_count','review_count','total_outbound_units',
        'ready_customer_units','excluded_units','review_units','ready_customer_source_amount','excluded_source_amount','review_source_amount','applied_by','applied_at',
    ];

    protected $casts = ['billing_period_start'=>'date','billing_period_end'=>'date','applied_at'=>'datetime'];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function rows(): HasMany { return $this->hasMany(WarehouseStorageCostImportRow::class, 'batch_id'); }
}
