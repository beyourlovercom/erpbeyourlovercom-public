<?php

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerImportBatch extends Model
{
    protected $fillable = ['source_type', 'original_filename', 'checksum', 'preview_total_rows', 'preview_create_rows', 'preview_update_rows', 'preview_unchanged_rows', 'preview_warning_rows', 'preview_error_rows', 'status', 'creator_user_id', 'confirmer_user_id', 'confirmed_at'];
    protected function casts(): array { return ['confirmed_at' => 'datetime']; }
    public function rows(): HasMany { return $this->hasMany(CustomerImportRow::class, 'import_batch_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'creator_user_id'); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmer_user_id'); }
}
