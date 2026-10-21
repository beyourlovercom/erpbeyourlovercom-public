<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolImportPreviewBatch extends Model
{
    protected $table = 'kol_import_preview_batches';
    protected $fillable = ['reconciliation_batch_id', 'creator_user_id', 'status', 'total_source_rows', 'candidate_count', 'unresolved_source_rows', 'preview_summary'];
    protected $casts = ['preview_summary' => 'array'];
    public function reconciliationBatch(): BelongsTo { return $this->belongsTo(KolReconciliationBatch::class, 'reconciliation_batch_id'); }
    public function candidates(): HasMany { return $this->hasMany(KolImportPreviewCandidate::class, 'import_preview_batch_id')->orderBy('id'); }
}
