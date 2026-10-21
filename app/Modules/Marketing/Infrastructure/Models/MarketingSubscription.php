<?php

namespace App\Modules\Marketing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MarketingSubscription extends Model
{
    protected $table = 'marketing_subscriptions';

    protected $fillable = ['subscriber_id', 'channel', 'provider', 'source_site', 'external_subscription_id', 'external_list_id', 'external_list_name', 'status', 'consent_source', 'subscribed_at', 'unsubscribed_at', 'status_updated_at', 'last_synced_at'];

    protected function casts(): array
    {
        return ['subscribed_at' => 'datetime', 'unsubscribed_at' => 'datetime', 'status_updated_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(MarketingSubscriber::class, 'subscriber_id');
    }
}
