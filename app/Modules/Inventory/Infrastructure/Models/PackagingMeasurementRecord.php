<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PackagingMeasurementRecord extends Model
{
    protected $fillable = ['product_id','warehouse_id','status','packaging_method_key','packaging_length_cm','packaging_width_cm','packaging_height_cm','packaged_weight_kg','measured_at','note','created_by','submitted_at','reviewed_by','reviewed_at','review_note'];
    protected $casts = ['measured_at'=>'datetime','submitted_at'=>'datetime','reviewed_at'=>'datetime','packaging_length_cm'=>'decimal:2','packaging_width_cm'=>'decimal:2','packaging_height_cm'=>'decimal:2','packaged_weight_kg'=>'decimal:3'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function media(): HasMany { return $this->hasMany(PackagingMeasurementMedia::class, 'record_id')->orderBy('sort_order'); }
}
