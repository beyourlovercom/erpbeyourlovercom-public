<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductProviderSnapshot extends Model
{
    protected $fillable = ['product_id', 'provider', 'connection_key', 'remote_product_id', 'permalink', 'provider_status', 'description_text', 'short_description_text', 'category_names', 'variation_count', 'provider_created_at', 'provider_modified_at', 'last_synced_at', 'payload_hash'];

    protected function casts(): array
    {
        return ['category_names' => 'array', 'provider_created_at' => 'immutable_datetime', 'provider_modified_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime', 'variation_count' => 'integer'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
