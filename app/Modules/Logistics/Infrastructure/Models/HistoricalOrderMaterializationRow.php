<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalOrderMaterializationRow extends Model
{
    protected $table = 'logistics_historical_order_materialization_rows';
    protected $fillable = ['materialization_batch_id', 'historical_evidence_id', 'source_row_number', 'source_order_no', 'decision', 'decision_reason', 'materialization_manifest', 'order_id'];
    protected $casts = ['materialization_manifest' => 'array'];

    public function batch(): BelongsTo { return $this->belongsTo(HistoricalOrderMaterializationBatch::class, 'materialization_batch_id'); }
    public function evidence(): BelongsTo { return $this->belongsTo(HistoricalEvidence::class, 'historical_evidence_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function dateCorrectionRows(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(HistoricalOrderDateCorrectionRow::class, 'materialization_row_id'); }
}
