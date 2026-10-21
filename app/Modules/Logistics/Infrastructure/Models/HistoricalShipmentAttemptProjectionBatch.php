<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalShipmentAttemptProjectionBatch extends Model
{
    protected $table = 'logistics_historical_shipment_attempt_projection_batches';
    protected $fillable = ['materialization_batch_id', 'source_checksum', 'status', 'total_rows', 'ready_count', 'review_count', 'applied_count', 'created_by'];
    public function materializationBatch(): BelongsTo { return $this->belongsTo(HistoricalOrderMaterializationBatch::class, 'materialization_batch_id'); }
    public function rows(): HasMany { return $this->hasMany(HistoricalShipmentAttemptProjectionRow::class, 'projection_batch_id')->orderBy('source_row_number'); }
}
