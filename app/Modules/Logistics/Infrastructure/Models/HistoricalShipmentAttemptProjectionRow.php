<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalShipmentAttemptProjectionRow extends Model
{
    protected $table = 'logistics_historical_shipment_attempt_projection_rows';
    protected $fillable = ['projection_batch_id', 'materialization_row_id', 'historical_evidence_id', 'source_row_number', 'decision', 'decision_reason', 'shipment_attempt_id', 'projection_manifest'];
    protected $casts = ['projection_manifest' => 'array'];
    public function batch(): BelongsTo { return $this->belongsTo(HistoricalShipmentAttemptProjectionBatch::class, 'projection_batch_id'); }
    public function materializationRow(): BelongsTo { return $this->belongsTo(HistoricalOrderMaterializationRow::class, 'materialization_row_id'); }
    public function evidence(): BelongsTo { return $this->belongsTo(HistoricalEvidence::class, 'historical_evidence_id'); }
    public function attempt(): BelongsTo { return $this->belongsTo(ShipmentAttempt::class, 'shipment_attempt_id'); }
}
