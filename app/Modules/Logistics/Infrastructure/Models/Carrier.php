<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Carrier extends Model
{
    protected $table = 'logistics_carriers';

    protected $fillable = ['name', 'name_en', 'name_zh', 'default_service_id', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'default_service_id' => 'integer'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(CarrierService::class, 'carrier_id');
    }

    public function defaultService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class, 'default_service_id');
    }

    public function displayName(): string
    {
        $parts = array_values(array_filter([trim((string) $this->name_en), trim((string) $this->name_zh)]));
        return $parts !== [] ? implode(' / ', array_unique($parts)) : $this->name;
    }
}
