<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderOperationalMarker extends Model
{
    protected $fillable = ['order_id', 'marker', 'marked_by', 'marked_at'];

    protected function casts(): array
    {
        return ['marked_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
