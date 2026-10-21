<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCostSource extends Model
{
    protected $fillable = [
        'product_id', 'source_key', 'unit_cost', 'currency', 'provenance', 'status',
        'source_type', 'source_reference', 'effective_from', 'effective_to', 'source_at',
        'confirmed_at', 'metadata', 'source_fingerprint',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:12',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'source_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
