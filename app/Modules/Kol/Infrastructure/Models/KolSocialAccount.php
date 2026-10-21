<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KolSocialAccount extends Model
{
    protected $table = 'kol_social_accounts';

    protected $fillable = [
        'kol_id',
        'platform',
        'handle',
        'profile_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function kol(): BelongsTo
    {
        return $this->belongsTo(Kol::class, 'kol_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(KolSocialMetric::class, 'social_account_id')->orderByDesc('measured_at');
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(KolSocialMetric::class, 'social_account_id')->latestOfMany('measured_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
