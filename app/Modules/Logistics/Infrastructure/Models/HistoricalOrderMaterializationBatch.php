<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalOrderMaterializationBatch extends Model
{
    protected $table = 'logistics_historical_order_materialization_batches';
    protected $fillable = ['historical_import_batch_id', 'source_checksum', 'status', 'total_rows', 'ready_count', 'review_count', 'already_applied_count', 'created_by'];

    public function importBatch(): BelongsTo { return $this->belongsTo(HistoricalImportBatch::class, 'historical_import_batch_id'); }
    public function rows(): HasMany { return $this->hasMany(HistoricalOrderMaterializationRow::class, 'materialization_batch_id')->orderBy('source_row_number'); }
    public function dateCorrectionBatches(): HasMany { return $this->hasMany(HistoricalOrderDateCorrectionBatch::class, 'materialization_batch_id'); }
}
