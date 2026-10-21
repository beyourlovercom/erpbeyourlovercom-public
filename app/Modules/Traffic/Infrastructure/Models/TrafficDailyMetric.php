<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficDailyMetric extends Model
{
    protected $table = 'traffic_daily_metrics';

    protected $fillable = [
        'traffic_campaign_id', 'metric_date', 'impressions', 'clicks',
        'cost_micros', 'provider_conversions',
    ];

    protected $casts = [
        'impressions' => 'integer',
        'clicks' => 'integer',
        'cost_micros' => 'integer',
        'provider_conversions' => 'decimal:4',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrafficCampaign::class, 'traffic_campaign_id');
    }
}
