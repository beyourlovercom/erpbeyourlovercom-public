<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrafficCampaign extends Model
{
    protected $table = 'traffic_campaigns';

    protected $fillable = [
        'traffic_source_account_id', 'external_campaign_id', 'name',
        'status', 'advertising_channel_type', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(TrafficSourceAccount::class, 'traffic_source_account_id');
    }

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(TrafficDailyMetric::class, 'traffic_campaign_id')->orderBy('metric_date');
    }
}
