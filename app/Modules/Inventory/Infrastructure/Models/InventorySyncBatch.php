<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InventorySyncBatch extends Model
{
    protected $table = 'inventory_sync_batches';

    protected $fillable = [
        'warehouse_id','source_type','sync_mode','original_filename','checksum',
        'stock_count_at','preview_total_rows','preview_unique_skus','preview_sellable_qty',
        'preview_defective_qty','preview_unmatched_skus','preview_duplicate_skus',
        'preview_warning_rows','preview_zeroed_skus','preview_error_rows',
        'status','creator_user_id','confirmer_user_id','confirmed_at',
    ];

    protected $casts = [
        'stock_count_at' => 'datetime',
        'confirmed_at'   => 'datetime',
        'preview_total_rows'    => 'integer',
        'preview_unique_skus'   => 'integer',
        'preview_sellable_qty'  => 'integer',
        'preview_defective_qty' => 'integer',
        'preview_unmatched_skus'=> 'integer',
        'preview_duplicate_skus'=> 'integer',
        'preview_warning_rows'  => 'integer',
        'preview_zeroed_skus'   => 'integer',
        'preview_error_rows'    => 'integer',
    ];

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function creator(): BelongsTo   { return $this->belongsTo(User::class, 'creator_user_id'); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmer_user_id'); }
    public function rows(): HasMany        { return $this->hasMany(InventorySyncRow::class, 'sync_batch_id'); }
}
