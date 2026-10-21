<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolImportPreviewCandidate extends Model
{
    protected $table = 'kol_import_preview_candidates';
    protected $fillable = ['import_preview_batch_id', 'cluster_key', 'source_row_ids', 'source_row_count', 'exact_identity', 'proposed_kol', 'proposed_social_accounts', 'owner_evidence', 'owner_resolution_status', 'matched_owner_id', 'history_summary', 'matched_existing_kol_id', 'existing_kol_status', 'affiliate_account_ids', 'preview_status', 'message'];
    protected $casts = ['source_row_ids' => 'array', 'exact_identity' => 'array', 'proposed_kol' => 'array', 'proposed_social_accounts' => 'array', 'owner_evidence' => 'array', 'history_summary' => 'array', 'affiliate_account_ids' => 'array'];
    public function batch(): BelongsTo { return $this->belongsTo(KolImportPreviewBatch::class, 'import_preview_batch_id'); }
    public function existingKol(): BelongsTo { return $this->belongsTo(Kol::class, 'matched_existing_kol_id'); }
}
