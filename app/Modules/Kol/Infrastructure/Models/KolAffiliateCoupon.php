<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolAffiliateCoupon extends Model
{
    protected $table = 'kol_affiliate_coupons';

    protected $fillable = [
        'connection_key', 'coupon_id', 'code', 'normalized_code', 'discount_type', 'amount',
        'provider_status', 'date_expires', 'affiliate_id', 'kol_id', 'origin', 'sync_status',
        'reconciliation_status', 'provisioning_status', 'provision_idempotency_key',
        'last_synced_at', 'last_successful_sync_at', 'last_error_code', 'last_error_message',
    ];

    protected $casts = [
        'coupon_id' => 'integer',
        'affiliate_id' => 'integer',
        'date_expires' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
    ];

    public function kol(): BelongsTo
    {
        return $this->belongsTo(Kol::class, 'kol_id');
    }
}
