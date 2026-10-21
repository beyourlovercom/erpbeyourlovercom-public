<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductSkuReconciliation extends Model
{
    protected $table = 'product_sku_reconciliations';

    protected $fillable = [
        'match_key',
        'raw_sku',
        'product_name_hint',
        'occurrence_count',
        'source_counts',
        'last_source_type',
        'last_source_reference',
        'status',
        'resolved_product_id',
        'resolved_alias_id',
        'resolution_note',
        'resolved_by_user_id',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'source_counts'   => 'array',
        'occurrence_count'=> 'integer',
        'first_seen_at'   => 'datetime',
        'last_seen_at'    => 'datetime',
        'resolved_at'     => 'datetime',
    ];

    public function resolvedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'resolved_product_id');
    }

    public function resolvedAlias(): BelongsTo
    {
        return $this->belongsTo(ProductSkuAlias::class, 'resolved_alias_id');
    }
}
