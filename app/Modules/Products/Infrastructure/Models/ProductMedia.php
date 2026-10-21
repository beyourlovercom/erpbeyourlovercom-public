<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductMedia extends Model
{
    protected $fillable = ['product_id', 'provider', 'connection_key', 'provider_media_key', 'remote_media_id', 'source_url', 'storage_path', 'mime_type', 'size_bytes', 'sha256', 'alt_text', 'position', 'is_primary', 'last_synced_at'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'position' => 'integer', 'is_primary' => 'boolean', 'last_synced_at' => 'immutable_datetime'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
