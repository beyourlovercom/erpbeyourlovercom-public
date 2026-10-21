<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolImportActivationItem extends Model
{
    protected $fillable = ['activation_batch_id', 'preview_candidate_id', 'cluster_key', 'readiness_status', 'preview_status', 'block_reasons', 'effective_owner_id', 'canonical_proposal', 'social_proposal', 'exact_identity', 'existing_guard', 'source_owner_evidence', 'affiliate_evidence', 'history_summary', 'created_kol_id', 'applied_by', 'applied_at'];
    protected $casts = ['block_reasons' => 'array', 'canonical_proposal' => 'array', 'social_proposal' => 'array', 'exact_identity' => 'array', 'existing_guard' => 'array', 'source_owner_evidence' => 'array', 'affiliate_evidence' => 'array', 'history_summary' => 'array', 'applied_at' => 'datetime'];

    public function batch(): BelongsTo { return $this->belongsTo(KolImportActivationBatch::class, 'activation_batch_id'); }
    public function previewCandidate(): BelongsTo { return $this->belongsTo(KolImportPreviewCandidate::class, 'preview_candidate_id'); }
    public function effectiveOwner(): BelongsTo { return $this->belongsTo(User::class, 'effective_owner_id'); }
    public function createdKol(): BelongsTo { return $this->belongsTo(Kol::class, 'created_kol_id'); }
}
