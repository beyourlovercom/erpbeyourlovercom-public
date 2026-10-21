<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductShippingWeightStandardCandidateEvidence extends Model
{
    protected $table = 'product_shipping_weight_standard_candidate_evidences';

    public $timestamps = false;

    protected $fillable = [
        'candidate_id',
        'product_weight_evidence_id',
        'is_eligible',
        'is_gross_outlier',
        'deviation_ratio',
    ];

    protected $casts = [
        'is_eligible' => 'boolean',
        'is_gross_outlier' => 'boolean',
        'deviation_ratio' => 'decimal:6',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ProductShippingWeightStandardCandidate::class, 'candidate_id');
    }

    public function weightEvidence(): BelongsTo
    {
        return $this->belongsTo(ProductWeightEvidence::class, 'product_weight_evidence_id');
    }
}
