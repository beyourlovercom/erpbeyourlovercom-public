<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderExternalReference extends Model
{
    protected $fillable = ['provider', 'connection_key', 'reference', 'provider_modified_at'];

    protected $casts = ['provider_modified_at' => 'immutable_datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
