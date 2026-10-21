<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolReconciliationRow extends Model
{
    protected $table = 'kol_reconciliation_rows';
    protected $fillable = ['batch_id', 'source_row_number', 'row_hash', 'raw_source', 'normalized_identity', 'source_identity_key', 'matched_kol_id', 'matched_affiliate_account_id', 'kol_status', 'affiliate_status', 'reconciliation_status', 'exact_match_evidence', 'message'];
    protected $casts = ['raw_source' => 'array', 'normalized_identity' => 'array', 'exact_match_evidence' => 'array'];
    public function batch(): BelongsTo { return $this->belongsTo(KolReconciliationBatch::class, 'batch_id'); }
    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'matched_kol_id'); }
    public function affiliateAccount(): BelongsTo { return $this->belongsTo(KolAffiliateAccount::class, 'matched_affiliate_account_id'); }
}
