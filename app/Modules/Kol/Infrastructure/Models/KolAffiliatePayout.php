<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class KolAffiliatePayout extends Model
{
    protected $table = 'kol_affiliate_payouts';
    protected $fillable = ['connection_key','payout_id','affiliate_id','kol_id','referral_ids','amount','currency','payout_method','provider_status','payout_date','reconciliation_status','sync_status','last_synced_at','last_successful_sync_at'];
    protected $casts = ['payout_id'=>'integer','affiliate_id'=>'integer','kol_id'=>'integer','referral_ids'=>'array','amount'=>'decimal:6','payout_date'=>'immutable_datetime','last_synced_at'=>'datetime','last_successful_sync_at'=>'datetime'];
}
