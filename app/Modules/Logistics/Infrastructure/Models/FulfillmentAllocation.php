<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FulfillmentAllocation extends Model
{
    protected $fillable = [
        'order_id', 'status', 'decision_source', 'recommended_plan_key', 'selected_plan_key',
        'plan_snapshot', 'manual_assignments', 'decision_note', 'submitted_by', 'submitted_at',
    ];

    protected $casts = [
        'plan_snapshot' => 'array',
        'manual_assignments' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'submitted_by');
    }
}
