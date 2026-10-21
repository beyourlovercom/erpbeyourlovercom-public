<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'woocommerce_product_id',
        'source_type',
        'product_type',
        'sku',
        'label_sku',
        'label_description',
        'gtin',
        'name',
        'unit',
        'is_active',
        'weight_kg',
        'weight_evidence_id',
        'sale_price',
        'regular_price',
        'shipping_weight_kg',
        'shipping_weight_evidence_id',
        'shipping_weight_standard_candidate_id',
        'length_cm',
        'width_cm',
        'height_cm',
        'country_of_origin',
        'hs_code',
        'standard_production_cost',
        'standard_packaging_cost',
        'standard_purchase_cost',
        'cost_currency',
        'cost_note',
        'notes',
    ];

    public function skuAliases(): HasMany
    {
        return $this->hasMany(ProductSkuAlias::class);
    }

    public function costSources(): HasMany
    {
        return $this->hasMany(ProductCostSource::class);
    }

    public function externalReferences(): HasMany
    {
        return $this->hasMany(ProductExternalReference::class);
    }

    public function providerSnapshots(): HasMany
    {
        return $this->hasMany(ProductProviderSnapshot::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('position');
    }

    public function packagingProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProductPackagingProfile::class);
    }

    public function weightEvidence(): BelongsTo
    {
        return $this->belongsTo(ProductWeightEvidence::class, 'weight_evidence_id');
    }

    public function shippingWeightEvidence(): BelongsTo
    {
        return $this->belongsTo(ProductWeightEvidence::class, 'shipping_weight_evidence_id');
    }

    public function shippingWeightStandardCandidate(): BelongsTo
    {
        return $this->belongsTo(ProductShippingWeightStandardCandidate::class, 'shipping_weight_standard_candidate_id');
    }

    protected $casts = [
        'woocommerce_product_id' => 'integer',
        'is_active' => 'boolean',
        'weight_kg' => 'decimal:4',
        'weight_evidence_id' => 'integer',
        'shipping_weight_evidence_id' => 'integer',
        'shipping_weight_standard_candidate_id' => 'integer',
        'sale_price' => 'decimal:2',
        'regular_price' => 'decimal:2',
        'shipping_weight_kg' => 'decimal:4',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'standard_production_cost' => 'decimal:4',
        'standard_packaging_cost' => 'decimal:4',
        'standard_purchase_cost' => 'decimal:4',
    ];
}
