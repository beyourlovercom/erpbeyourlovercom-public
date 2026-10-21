<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Kol\Infrastructure\Models\Kol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalEvidence extends Model
{
    protected $table = 'logistics_historical_evidences';
    protected $fillable = ['batch_id', 'historical_row_id', 'source_row_number', 'kol_id', 'source_checksum', 'normalized_tracking_candidate', 'evidence_hash', 'evidence_snapshot'];
    protected $casts = ['evidence_snapshot' => 'array'];
    public function batch(): BelongsTo { return $this->belongsTo(HistoricalImportBatch::class, 'batch_id'); }
    public function row(): BelongsTo { return $this->belongsTo(HistoricalImportRow::class, 'historical_row_id'); }
    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'kol_id'); }
}
