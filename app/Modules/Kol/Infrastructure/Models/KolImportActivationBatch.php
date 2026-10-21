<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolImportActivationBatch extends Model
{
    protected $fillable = ['import_preview_batch_id', 'reconciliation_batch_id', 'source_checksum', 'plan_version', 'status', 'candidate_count', 'ready_count', 'blocked_count', 'existing_count', 'skipped_count', 'plan_checksum', 'generated_by', 'applied_by', 'generated_at', 'applied_at', 'failure_message'];
    protected $casts = ['generated_at' => 'datetime', 'applied_at' => 'datetime'];

    public function previewBatch(): BelongsTo { return $this->belongsTo(KolImportPreviewBatch::class, 'import_preview_batch_id'); }
    public function reconciliationBatch(): BelongsTo { return $this->belongsTo(KolReconciliationBatch::class, 'reconciliation_batch_id'); }
    public function items(): HasMany { return $this->hasMany(KolImportActivationItem::class, 'activation_batch_id')->orderBy('id'); }
    public function generator(): BelongsTo { return $this->belongsTo(User::class, 'generated_by'); }
    public function applier(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
}
