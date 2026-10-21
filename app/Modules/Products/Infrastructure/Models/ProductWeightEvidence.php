<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductWeightEvidence extends Model
{
    protected $table = 'product_weight_evidences';


    protected $fillable = [
        'product_id',
        'weight_kind',
        'observed_weight_kg',
        'raw_sku',
        'source_type',
        'source_reference',
        'source_note',
        'observed_at',
        'status',
        'created_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'rejection_note',
    ];

    protected $casts = [
        'observed_weight_kg' => 'decimal:4',
        'observed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
