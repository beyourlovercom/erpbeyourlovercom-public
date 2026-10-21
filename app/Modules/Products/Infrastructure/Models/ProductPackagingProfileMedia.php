<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductPackagingProfileMedia extends Model
{
    protected $table = 'product_packaging_profile_media';
    protected $fillable = ['product_packaging_profile_id', 'kind', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sort_order'];
    public function profile(): BelongsTo { return $this->belongsTo(ProductPackagingProfile::class, 'product_packaging_profile_id'); }
}
