<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CarrierBillingImportBatch extends Model
{
    protected $fillable = [
        'provider', 'original_filename', 'source_checksum', 'parser_version', 'plan_checksum',
        'status', 'total_rows', 'ready_count', 'review_count', 'skip_count', 'applied_by', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CarrierBillingImportRow::class, 'batch_id');
    }
}
