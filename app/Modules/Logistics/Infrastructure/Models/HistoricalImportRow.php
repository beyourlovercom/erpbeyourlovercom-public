<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Kol\Infrastructure\Models\Kol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalImportRow extends Model
{
    protected $table = 'logistics_historical_import_rows';
    protected $fillable = ['batch_id', 'source_row_number', 'source_order_no', 'kol_id', 'source_status_raw', 'source_carrier_raw', 'source_warehouse_raw', 'source_tracking_raw', 'normalized_tracking_candidate', 'source_input_time_raw', 'source_sku_raw', 'source_quantity_raw', 'classification', 'classification_reason', 'evidence_hash'];
    public function batch(): BelongsTo { return $this->belongsTo(HistoricalImportBatch::class, 'batch_id'); }
    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'kol_id'); }
}
