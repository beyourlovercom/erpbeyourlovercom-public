<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolReconciliationBatch extends Model
{
    protected $table = 'kol_reconciliation_batches';
    protected $fillable = ['source_type', 'original_filename', 'checksum', 'connection_key', 'creator_user_id', 'total_rows', 'distinct_source_identities', 'status', 'preview_summary'];
    protected $casts = ['preview_summary' => 'array'];
    public function rows(): HasMany { return $this->hasMany(KolReconciliationRow::class, 'batch_id')->orderBy('source_row_number'); }
}
