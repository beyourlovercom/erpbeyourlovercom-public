<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolAffiliateAccount extends Model
{
    protected $table = 'kol_affiliate_accounts';

    protected $fillable = [
        'connection_key',
        'affiliate_id',
        'kol_id',
        'wordpress_user_id',
        'wordpress_user_login',
        'wordpress_user_email',
        'display_name',
        'email',
        'provider_status',
        'origin',
        'sync_status',
        'reconciliation_status',
        'provisioning_status',
        'provision_idempotency_key',
        'last_synced_at',
        'last_successful_sync_at',
        'last_error_code',
        'last_error_message',
    ];

    protected $casts = [
        'affiliate_id' => 'integer',
        'wordpress_user_id' => 'integer',
        'last_synced_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
    ];

    public function kol(): BelongsTo
    {
        return $this->belongsTo(Kol::class, 'kol_id');
    }
}
