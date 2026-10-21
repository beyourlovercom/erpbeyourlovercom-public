<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalRescueShipmentAttemptProjectionBatch extends Model
{
    protected $table = 'logistics_historical_rescue_attempt_projection_batches';

    protected $fillable = [
        'rescue_order_projection_batch_id', 'source_checksum', 'projection_version', 'status',
        'total_rows', 'ready_count', 'review_count', 'applied_count',
        'ready_manifest_sha256', 'created_by',
    ];

    public function rescueOrderProjectionBatch(): BelongsTo
    {
        return $this->belongsTo(HistoricalRescueOrderProjectionBatch::class, 'rescue_order_projection_batch_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(HistoricalRescueShipmentAttemptProjectionRow::class, 'projection_batch_id')
            ->orderBy('source_row_number')
            ->orderBy('attempt_number');
    }
}
