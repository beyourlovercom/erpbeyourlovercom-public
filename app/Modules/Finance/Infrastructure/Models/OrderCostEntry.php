<?php

namespace App\Modules\Finance\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCostEntry extends Model
{
    protected $fillable = ['cost_type', 'amount', 'currency', 'note', 'provenance', 'status', 'source_reference', 'effective_at', 'source_at', 'created_by', 'confirmed_by', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'base_amount', 'base_currency'];

    protected $casts = ['amount' => 'decimal:2', 'base_amount' => 'decimal:2', 'exchange_rate' => 'decimal:8', 'effective_at' => 'immutable_datetime', 'source_at' => 'immutable_datetime', 'exchange_rate_date' => 'date'];

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }
}
