<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalImportBatch extends Model
{
    protected $table = 'logistics_historical_import_batches';
    protected $fillable = ['original_filename', 'source_checksum', 'source_sheet', 'reconciliation_batch_id', 'status', 'total_rows', 'ready_count', 'review_count', 'skip_count', 'created_by', 'committed_by', 'committed_at'];
    protected $casts = ['committed_at' => 'datetime'];

    public function rows(): HasMany { return $this->hasMany(HistoricalImportRow::class, 'batch_id')->orderBy('source_row_number'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function committer(): BelongsTo { return $this->belongsTo(User::class, 'committed_by'); }
    public function reconciliationBatch(): BelongsTo { return $this->belongsTo(KolReconciliationBatch::class, 'reconciliation_batch_id'); }
}
