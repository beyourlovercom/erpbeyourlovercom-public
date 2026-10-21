<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolAffiliateBindingActivationBatch extends Model
{
    protected $fillable = ['import_activation_batch_id', 'reconciliation_batch_id', 'connection_key', 'source_checksum', 'plan_version', 'status', 'eligible_kol_count', 'ready_count', 'already_bound_count', 'no_affiliate_count', 'blocked_count', 'plan_checksum', 'generated_by', 'applied_by', 'generated_at', 'applied_at', 'failure_message'];
    protected $casts = ['generated_at' => 'datetime', 'applied_at' => 'datetime'];

    public function importActivationBatch(): BelongsTo { return $this->belongsTo(KolImportActivationBatch::class, 'import_activation_batch_id'); }
    public function reconciliationBatch(): BelongsTo { return $this->belongsTo(KolReconciliationBatch::class, 'reconciliation_batch_id'); }
    public function items(): HasMany { return $this->hasMany(KolAffiliateBindingActivationItem::class, 'binding_activation_batch_id')->orderBy('id'); }
    public function generator(): BelongsTo { return $this->belongsTo(User::class, 'generated_by'); }
    public function applier(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
}
