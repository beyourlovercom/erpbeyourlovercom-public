<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProductPackagingProfile extends Model
{
    protected $fillable = [
        'product_id', 'current_measurement_record_id', 'preset_key', 'custom_method', 'scenario', 'version',
        'units_per_carton', 'custom_materials', 'custom_steps', 'custom_tips', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'current_measurement_record_id' => 'integer',
            'units_per_carton' => 'integer',
            'custom_materials' => 'array',
            'custom_steps' => 'array',
            'custom_tips' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function media(): HasMany { return $this->hasMany(ProductPackagingProfileMedia::class, 'product_packaging_profile_id')->orderBy('sort_order')->orderBy('id'); }
}
