<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalOrderDateCorrectionBatch extends Model
{
    protected $table = 'logistics_historical_order_date_correction_batches';
    protected $fillable = ['materialization_batch_id', 'source_checksum', 'correction_version', 'as_of_date', 'status', 'total_rows', 'ready_count', 'review_count', 'applied_count', 'created_by'];

    public function materializationBatch(): BelongsTo { return $this->belongsTo(HistoricalOrderMaterializationBatch::class, 'materialization_batch_id'); }
    public function rows(): HasMany { return $this->hasMany(HistoricalOrderDateCorrectionRow::class, 'correction_batch_id')->orderBy('source_row_number'); }
}
