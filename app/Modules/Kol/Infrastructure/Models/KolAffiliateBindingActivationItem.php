<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolAffiliateBindingActivationItem extends Model
{
    protected $fillable = ['binding_activation_batch_id', 'import_activation_item_id', 'cluster_key', 'canonical_kol_id', 'affiliate_account_id', 'readiness_status', 'block_reasons', 'exact_identity', 'affiliate_snapshot', 'referral_impact_count', 'referral_provider_checksum', 'applied_by', 'applied_at'];
    protected $casts = ['block_reasons' => 'array', 'exact_identity' => 'array', 'affiliate_snapshot' => 'array', 'applied_at' => 'datetime'];

    public function batch(): BelongsTo { return $this->belongsTo(KolAffiliateBindingActivationBatch::class, 'binding_activation_batch_id'); }
    public function importActivationItem(): BelongsTo { return $this->belongsTo(KolImportActivationItem::class, 'import_activation_item_id'); }
    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'canonical_kol_id'); }
    public function affiliateAccount(): BelongsTo { return $this->belongsTo(KolAffiliateAccount::class, 'affiliate_account_id'); }
    public function applier(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
}
