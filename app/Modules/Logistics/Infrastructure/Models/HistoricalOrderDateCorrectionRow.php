<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalOrderDateCorrectionRow extends Model
{
    protected $table = 'logistics_historical_order_date_correction_rows';
    protected $fillable = ['correction_batch_id', 'materialization_row_id', 'order_id', 'historical_evidence_id', 'source_row_number', 'decision', 'decision_reason', 'current_order_date', 'proposed_order_date', 'correction_manifest'];
    protected $casts = ['current_order_date' => 'date', 'proposed_order_date' => 'date', 'correction_manifest' => 'array'];

    public function batch(): BelongsTo { return $this->belongsTo(HistoricalOrderDateCorrectionBatch::class, 'correction_batch_id'); }
    public function materializationRow(): BelongsTo { return $this->belongsTo(HistoricalOrderMaterializationRow::class, 'materialization_row_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function evidence(): BelongsTo { return $this->belongsTo(HistoricalEvidence::class, 'historical_evidence_id'); }
}
