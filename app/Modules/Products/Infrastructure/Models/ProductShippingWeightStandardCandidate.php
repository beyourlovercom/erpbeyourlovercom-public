<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductShippingWeightStandardCandidate extends Model
{
    protected $table = 'product_shipping_weight_standard_candidates';

    protected $fillable = [
        'product_id',
        'suggested_weight_kg',
        'algorithm_version',
        'workflow_status',
        'confidence_status',
        'raw_sample_count',
        'eligible_sample_count',
        'gross_outlier_count',
        'eligible_min_kg',
        'eligible_max_kg',
        'eligible_spread_ratio',
        'policy_json',
        'fingerprint',
        'created_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'rejection_note',
    ];

    protected $casts = [
        'suggested_weight_kg' => 'decimal:4',
        'eligible_min_kg' => 'decimal:4',
        'eligible_max_kg' => 'decimal:4',
        'eligible_spread_ratio' => 'decimal:6',
        'policy_json' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Identity\Infrastructure\Models\User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Identity\Infrastructure\Models\User::class, 'reviewed_by_user_id');
    }

    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(ProductShippingWeightStandardCandidateEvidence::class, 'candidate_id');
    }
}
