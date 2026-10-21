<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalRescueOrderProjectionBatch extends Model
{
    protected $table = 'logistics_historical_rescue_order_projection_batches';

    protected $fillable = [
        'historical_import_batch_id', 'source_checksum', 'projection_version', 'status',
        'total_rows', 'ready_count', 'review_count', 'applied_count',
        'ready_manifest_sha256', 'created_by',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(HistoricalImportBatch::class, 'historical_import_batch_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(HistoricalRescueOrderProjectionRow::class, 'projection_batch_id')
            ->orderBy('source_row_number');
    }
}
