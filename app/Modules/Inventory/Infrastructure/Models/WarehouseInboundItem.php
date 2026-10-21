<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseInboundItem extends Model
{
    protected $fillable = [
        'batch_id','product_id','source_sku','canonical_sku','source_product_name','quantity','evidence_type','match_status','note',
    ];

    protected $casts = ['quantity'=>'integer'];

    public function batch(): BelongsTo { return $this->belongsTo(WarehouseInboundBatch::class, 'batch_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
