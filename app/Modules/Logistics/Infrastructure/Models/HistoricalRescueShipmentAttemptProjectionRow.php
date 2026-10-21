<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalRescueShipmentAttemptProjectionRow extends Model
{
    protected $table = 'logistics_historical_rescue_attempt_projection_rows';

    protected $fillable = [
        'projection_batch_id', 'rescue_order_projection_row_id', 'historical_evidence_id',
        'order_id', 'source_row_number', 'attempt_number', 'decision', 'decision_reason',
        'shipment_attempt_id', 'projection_manifest',
    ];

    protected $casts = ['projection_manifest' => 'array'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HistoricalRescueShipmentAttemptProjectionBatch::class, 'projection_batch_id');
    }

    public function rescueOrderProjectionRow(): BelongsTo
    {
        return $this->belongsTo(HistoricalRescueOrderProjectionRow::class, 'rescue_order_projection_row_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(HistoricalEvidence::class, 'historical_evidence_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipmentAttempt(): BelongsTo
    {
        return $this->belongsTo(ShipmentAttempt::class);
    }
}
