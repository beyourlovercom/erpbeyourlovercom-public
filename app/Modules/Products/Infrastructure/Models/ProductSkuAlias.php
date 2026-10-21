<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductSkuAlias extends Model
{
    protected $table = 'product_sku_aliases';

    protected $fillable = [
        'product_id',
        'alias_sku',
        'match_key',
        'source_type',
        'source_reference',
        'notes',
        'created_by_user_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
