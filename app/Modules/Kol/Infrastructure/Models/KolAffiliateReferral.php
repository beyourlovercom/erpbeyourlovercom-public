<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolAffiliateReferral extends Model
{
    protected $table = 'kol_affiliate_referrals';

    protected $fillable = [
        'connection_key', 'referral_id', 'affiliate_id', 'kol_id', 'order_id', 'order_reference',
        'description', 'commission_amount', 'currency', 'provider_status', 'context', 'referral_date',
        'payout_id', 'affiliate_resolution_status', 'order_resolution_status', 'reconciliation_status',
        'sync_status', 'last_synced_at', 'last_successful_sync_at',
    ];

    protected $casts = [
        'referral_id' => 'integer', 'affiliate_id' => 'integer', 'kol_id' => 'integer', 'order_id' => 'integer',
        'commission_amount' => 'decimal:6', 'payout_id' => 'integer', 'referral_date' => 'immutable_datetime',
        'last_synced_at' => 'datetime', 'last_successful_sync_at' => 'datetime',
    ];

    public function kol(): BelongsTo
    {
        return $this->belongsTo(Kol::class, 'kol_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order', 'order_id');
    }
}
