<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductExternalReference extends Model
{
    protected $fillable = [
        'product_id',
        'provider',
        'connection_key',
        'reference',
        'reference_type',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
