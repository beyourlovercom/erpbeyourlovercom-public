<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolSocialMetric extends Model
{
    protected $table = 'kol_social_metrics';

    protected $fillable = [
        'social_account_id',
        'followers',
        'raw_followers_value',
        'measured_at',
    ];

    protected $casts = [
        'followers' => 'integer',
        'measured_at' => 'datetime',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(KolSocialAccount::class, 'social_account_id');
    }
}
