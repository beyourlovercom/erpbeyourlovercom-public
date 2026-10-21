<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolPayoutRequest extends Model
{
    protected $table = 'kol_payout_requests';
    protected $fillable = ['connection_key','kol_id','affiliate_id','amount','currency','payout_method','payout_recipient','status','payload_hash','execution_payload_hash','idempotency_key','provider_payout_id','provider_status','paypal_transaction_id','paid_at','requested_by_user_id','approved_by_user_id','approved_at','rejected_by_user_id','rejected_at','rejection_reason','completed_by_user_id','provider_last_error_code','provider_last_error_message','provider_last_attempt_at','last_reconciled_at'];
    protected $casts = ['kol_id'=>'integer','affiliate_id'=>'integer','amount'=>'decimal:6','payout_recipient'=>'encrypted','provider_payout_id'=>'integer','paypal_transaction_id'=>'encrypted','paid_at'=>'date','requested_by_user_id'=>'integer','approved_by_user_id'=>'integer','approved_at'=>'datetime','rejected_by_user_id'=>'integer','rejected_at'=>'datetime','completed_by_user_id'=>'integer','provider_last_attempt_at'=>'datetime','last_reconciled_at'=>'datetime'];
    public function items(): HasMany { return $this->hasMany(KolPayoutRequestItem::class, 'payout_request_id')->orderBy('referral_id'); }
    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'kol_id'); }
    public function requestedBy(): BelongsTo { return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'requested_by_user_id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'approved_by_user_id'); }
    public function rejectedBy(): BelongsTo { return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'rejected_by_user_id'); }
}
