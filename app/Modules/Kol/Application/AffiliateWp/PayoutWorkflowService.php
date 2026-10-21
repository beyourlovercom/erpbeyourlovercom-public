<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use UnexpectedValueException;

final class PayoutWorkflowService
{
    private const REFERRAL = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateReferral';
    private const REQUEST = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolPayoutRequest';
    private const ITEM = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolPayoutRequestItem';
    private const PAYOUT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliatePayout';
    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';
    private const ACTIVE = ['pending_approval','approved','provider_processing','provider_error','readback_mismatch'];

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    public function create(array $referralIds, Authenticatable $actor): object
    {
        if (! $actor->can('kol.payouts.request')) { throw new AuthorizationException; }
        $ids = $this->normalizeIds($referralIds);
        $connectionKey = (string)($this->gateway->descriptor()['connection_key'] ?? '');
        if ($connectionKey === '') { throw new RuntimeException('AffiliateWP connection is not configured.'); }
        $referralClass=self::REFERRAL; $requestClass=self::REQUEST; $itemClass=self::ITEM; $kolClass=self::KOL;

        return DB::transaction(function () use ($ids,$actor,$connectionKey,$referralClass,$requestClass,$itemClass,$kolClass): object {
            $rows=$referralClass::query()->where('connection_key',$connectionKey)->whereIn('referral_id',$ids)->orderBy('referral_id')->lockForUpdate()->get();
            if ($rows->count() !== count($ids)) { throw new UnexpectedValueException('One or more Referral IDs are not mirrored.'); }
            $affiliateId=null; $kolId=null; $currency=null; $micros=0;
            foreach ($rows as $row) {
                if ($row->reconciliation_status !== 'present' || strtolower((string)$row->provider_status) !== 'unpaid' || $row->payout_id !== null || $row->affiliate_resolution_status !== 'bound' || $row->kol_id === null) { throw new UnexpectedValueException('Referral set contains an ineligible Referral.'); }
                $affiliateId ??= (int)$row->affiliate_id; $kolId ??= (int)$row->kol_id; $currency ??= strtoupper((string)$row->currency);
                if ((int)$row->affiliate_id !== $affiliateId || (int)$row->kol_id !== $kolId || strtoupper((string)$row->currency) !== $currency) { throw new UnexpectedValueException('Referral set must belong to one KOL/Affiliate and currency.'); }
                $micros += $this->decimalMicros((string)$row->commission_amount);
            }
            if ($micros <= 0) { throw new UnexpectedValueException('Payout amount must be positive.'); }
            $kol=$kolClass::query()->lockForUpdate()->find($kolId);
            if ($kol !== null && (int) $kol->owner_id !== (int) $actor->getAuthIdentifier()) {
                throw new AuthorizationException('Payout requests are limited to KOLs owned by the current user.');
            }
            $recipient=$kol === null ? '' : trim((string)$kol->payout_account);
            if ($kol === null || $kol->archived_at !== null || (string)$kol->status !== 'active' || (string)$kol->approval_status !== 'approved' || (string)$kol->payout_method !== 'paypal' || $recipient === '') {
                throw new UnexpectedValueException('KOL must be active, approved, and have a PayPal payout account before requesting payout.');
            }
            $claimed=DB::table('kol_payout_request_items as i')->join('kol_payout_requests as r','r.id','=','i.payout_request_id')->where('i.connection_key',$connectionKey)->whereIn('i.referral_id',$ids)->whereIn('r.status',self::ACTIVE)->exists();
            if ($claimed) { throw new UnexpectedValueException('A Referral is already claimed by an active payout request.'); }
            $amount=$this->microsDecimal($micros);
            $fingerprint=hash('sha256',json_encode(['connection_key'=>$connectionKey,'affiliate_id'=>$affiliateId,'kol_id'=>$kolId,'referral_ids'=>$ids,'amount'=>$amount,'currency'=>$currency,'payout_method'=>'paypal_manual','payout_recipient'=>$recipient],JSON_UNESCAPED_SLASHES));
            $request=$requestClass::create(['connection_key'=>$connectionKey,'kol_id'=>$kolId,'affiliate_id'=>$affiliateId,'amount'=>$amount,'currency'=>$currency,'payout_method'=>'paypal_manual','payout_recipient'=>$recipient,'status'=>'pending_approval','payload_hash'=>$fingerprint,'idempotency_key'=>(string)Str::uuid(),'requested_by_user_id'=>(int)$actor->getAuthIdentifier()]);
            foreach ($rows as $row) { $itemClass::create(['payout_request_id'=>$request->id,'referral_row_id'=>$row->id,'connection_key'=>$connectionKey,'referral_id'=>$row->referral_id,'commission_amount'=>$row->commission_amount,'currency'=>$currency]); }
            return $request->fresh('items');
        });
    }

    public function approve(int $requestId, Authenticatable $actor): object
    {
        if (! $actor->can('kol.payouts.approve')) { throw new AuthorizationException; }
        $requestClass=self::REQUEST;
        return DB::transaction(function () use ($requestId,$actor,$requestClass): object {
            $request=$requestClass::query()->whereHas('kol', fn ($q) => $q->where('approval_manager_id', (int) $actor->getAuthIdentifier()))->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending_approval') { throw new UnexpectedValueException('Only pending payout requests can be approved.'); }
            if ((int)$request->requested_by_user_id === (int)$actor->getAuthIdentifier()) { throw new AuthorizationException('Requester cannot approve their own payout request.'); }
            $request->update(['status'=>'approved','approved_by_user_id'=>(int)$actor->getAuthIdentifier(),'approved_at'=>now(),'rejected_by_user_id'=>null,'rejected_at'=>null,'rejection_reason'=>null]);
            return $request->fresh('items');
        });
    }

    public function reject(int $requestId, string $reason, Authenticatable $actor): object
    {
        if (! $actor->can('kol.payouts.approve')) { throw new AuthorizationException; }
        $reason=trim($reason); if ($reason==='' || mb_strlen($reason)>1000) { throw new UnexpectedValueException('Rejection reason is required.'); }
        $requestClass=self::REQUEST;
        return DB::transaction(function () use ($requestId,$reason,$actor,$requestClass): object {
            $request=$requestClass::query()->whereHas('kol', fn ($q) => $q->where('approval_manager_id', (int) $actor->getAuthIdentifier()))->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending_approval') { throw new UnexpectedValueException('Only pending payout requests can be rejected.'); }
            if ((int)$request->requested_by_user_id === (int)$actor->getAuthIdentifier()) { throw new AuthorizationException('Requester cannot reject their own payout request.'); }
            $request->update(['status'=>'rejected','rejected_by_user_id'=>(int)$actor->getAuthIdentifier(),'rejected_at'=>now(),'rejection_reason'=>$reason]);
            return $request->fresh('items');
        });
    }

    public function complete(int $requestId, string $transactionId, string $paidAt, Authenticatable $actor): object
    {
        if (! $actor->can('kol.payouts.manage')) { throw new AuthorizationException; }
        $transactionId=trim($transactionId);
        if ($transactionId==='' || strlen($transactionId)>255 || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$paidAt) || date('Y-m-d',strtotime($paidAt))!==$paidAt) { throw new UnexpectedValueException('Payment evidence is invalid.'); }
        $requestClass=self::REQUEST;
        $request=DB::transaction(function () use ($requestId,$transactionId,$paidAt,$actor,$requestClass): object {
            $row=$requestClass::query()->whereHas('kol', fn ($q) => $q->where('finance_owner_id', (int) $actor->getAuthIdentifier()))->with('items')->lockForUpdate()->findOrFail($requestId);
            if (! in_array($row->status,['approved','provider_error','readback_mismatch'],true)) { throw new UnexpectedValueException('Payout request is not ready for completion.'); }
            if ($row->approved_by_user_id === null) { throw new UnexpectedValueException('Payout request is not approved.'); }
            $payload=$this->payload($row,$transactionId,$paidAt); $hash=hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES));
            if ($row->execution_payload_hash !== null && ! hash_equals((string)$row->execution_payload_hash,$hash)) { throw new UnexpectedValueException('Retry payment evidence must match the original provider attempt.'); }
            $row->update(['status'=>'provider_processing','execution_payload_hash'=>$hash,'paypal_transaction_id'=>$transactionId,'paid_at'=>$paidAt,'provider_last_attempt_at'=>now(),'provider_last_error_code'=>null,'provider_last_error_message'=>null]);
            return $row->fresh('items');
        });
        $payload=$this->payload($request,(string)$request->paypal_transaction_id,$request->paid_at->format('Y-m-d'));
        try { $response=$this->gateway->completePayout($payload,(string)$request->idempotency_key); }
        catch (RuntimeException $e) {
            $code = property_exists($e, 'errorCode') ? (string) $e->errorCode : 'PROVIDER_ERROR';
            $request->update(['status'=>'provider_error','provider_last_error_code'=>$code,'provider_last_error_message'=>mb_substr($e->getMessage(),0,512)]);
            throw $e;
        }
        $data=$response->data;
        if (! $this->readbackMatches($payload,$data)) {
            $request->update(['status'=>'readback_mismatch','provider_last_error_code'=>'READBACK_MISMATCH','provider_last_error_message'=>'Provider payout read-back did not match exact ERP request.']);
            throw new RuntimeException('Provider payout read-back mismatch.');
        }
        $payoutClass=self::PAYOUT; $referralClass=self::REFERRAL;
        return DB::transaction(function () use ($request,$payload,$data,$actor,$payoutClass,$referralClass): object {
            $providerId=(int)$data['payout_id'];
            $payoutClass::query()->updateOrCreate(['connection_key'=>$request->connection_key,'payout_id'=>$providerId],[
                'affiliate_id'=>$request->affiliate_id,'kol_id'=>$request->kol_id,'referral_ids'=>$payload['referral_ids'],'amount'=>$request->amount,'currency'=>$request->currency,'payout_method'=>'manual','provider_status'=>'paid','payout_date'=>$payload['paid_at'].' 00:00:00','reconciliation_status'=>'present','sync_status'=>'synced','last_synced_at'=>now(),'last_successful_sync_at'=>now(),
            ]);
            $referralClass::query()->where('connection_key',$request->connection_key)->whereIn('referral_id',$payload['referral_ids'])->update(['provider_status'=>'paid','payout_id'=>$providerId,'last_synced_at'=>now(),'last_successful_sync_at'=>now()]);
            $request->update(['status'=>'paid','provider_payout_id'=>$providerId,'provider_status'=>'paid','completed_by_user_id'=>(int)$actor->getAuthIdentifier(),'provider_last_error_code'=>null,'provider_last_error_message'=>null,'last_reconciled_at'=>now()]);
            return $request->fresh('items');
        });
    }

    /** @return array<string,mixed> */
    public function workspace(Authenticatable $actor): array
    {
        $canRequest=$actor->can('kol.payouts.request'); $canApprove=$actor->can('kol.payouts.approve'); $canManage=$actor->can('kol.payouts.manage');
        if (! $canRequest && ! $canApprove && ! $canManage) { throw new AuthorizationException; }
        $requestClass=self::REQUEST; $referralClass=self::REFERRAL;
        $connectionKey=(string)($this->gateway->descriptor()['connection_key']??'');
        $ownerId=(int)$actor->getAuthIdentifier();
        $requestQuery=$requestClass::query()->where('connection_key',$connectionKey)
            ->whereHas('kol', function ($q) use ($ownerId, $canRequest, $canApprove, $canManage): void {
                $q->where(function ($scope) use ($ownerId, $canRequest, $canApprove, $canManage): void {
                    if ($canRequest) { $scope->orWhere('owner_id', $ownerId); }
                    if ($canApprove) { $scope->orWhere('approval_manager_id', $ownerId); }
                    if ($canManage) { $scope->orWhere('finance_owner_id', $ownerId); }
                });
            })->with(['items.referral.order:id,erp_order_id,external_order_number','kol:id,approval_manager_id,finance_owner_id']);
        $requests=$requestQuery->orderByDesc('id')->limit(200)->get();
        $eligible=collect();
        if ($canRequest) {
            $eligibleQuery=$referralClass::query()->where('connection_key',$connectionKey)->where('reconciliation_status','present')->where('provider_status','unpaid')->whereNull('payout_id')->where('affiliate_resolution_status','bound')->whereNotNull('kol_id')
                ->whereHas('kol',fn($q)=>$q->where('owner_id',$ownerId));
            $eligible=$eligibleQuery->with('order:id,erp_order_id,external_order_number')->orderBy('affiliate_id')->orderBy('referral_id')->limit(500)->get(['id','referral_id','affiliate_id','kol_id','order_id','commission_amount','currency','order_reference','referral_date']);
        }
        return ['connection_key'=>$connectionKey,'requests'=>$requests->map(fn($r)=>$this->requestView($r, $canApprove && (int)($r->kol?->approval_manager_id ?? 0)===$ownerId, $canManage && (int)($r->kol?->finance_owner_id ?? 0)===$ownerId))->values()->all(),'eligible'=>$eligible->map(fn($r)=>['referral_id'=>(int)$r->referral_id,'affiliate_id'=>(int)$r->affiliate_id,'kol_id'=>(int)$r->kol_id,'amount'=>(string)$r->commission_amount,'currency'=>(string)$r->currency,'order_number'=>$r->order?->erp_order_id,'source_order_number'=>$r->order?->external_order_number,'order_reference'=>(string)$r->order_reference,'referral_date'=>$r->referral_date?->toIso8601String()])->values()->all()];
    }

    private function payload(object $row, string $transactionId, string $paidAt): array
    {
        $ids=$row->items->pluck('referral_id')->map(fn($v)=>(int)$v)->sort()->values()->all();
        return ['erp_payout_request_id'=>(int)$row->id,'affiliate_id'=>(int)$row->affiliate_id,'referral_ids'=>$ids,'amount'=>$this->canonicalDecimal((string)$row->amount),'currency'=>(string)$row->currency,'payout_method'=>'paypal_manual','paypal_transaction_id'=>$transactionId,'paid_at'=>$paidAt];
    }

    private function readbackMatches(array $payload, mixed $data): bool
    {
        if (! is_array($data)) { return false; }
        $ids=$data['referral_ids']??null; if (! is_array($ids)) { return false; } $ids=array_map('intval',$ids); sort($ids,SORT_NUMERIC);
        return (int)($data['payout_id']??0)>0 && (int)($data['affiliate_id']??0)===$payload['affiliate_id'] && $ids===$payload['referral_ids'] && $this->canonicalDecimal((string)($data['amount']??''))===$payload['amount'] && strtoupper((string)($data['currency']??''))===$payload['currency'] && (string)($data['payout_method']??'')==='paypal_manual' && strtolower((string)($data['status']??''))==='paid' && (string)($data['date']??'')===$payload['paid_at'];
    }

    private function normalizeIds(array $values): array
    {
        if (count($values)<1 || count($values)>100) { throw new UnexpectedValueException('Payout requests require 1-100 Referral IDs.'); }
        $ids=[]; foreach($values as $v){ if((is_int($v)||(is_string($v)&&ctype_digit($v)))&&(int)$v>0){$ids[]=(int)$v;}else{throw new UnexpectedValueException('Referral IDs must be positive integers.');} }
        if(count(array_unique($ids))!==count($ids)){throw new UnexpectedValueException('Referral IDs must be unique.');} sort($ids,SORT_NUMERIC); return $ids;
    }

    private function decimalMicros(string $value): int
    {
        $value=$this->canonicalDecimal($value); [$w,$f]=array_pad(explode('.',$value,2),2,''); return ((int)$w)*1000000+(int)str_pad($f,6,'0');
    }
    private function microsDecimal(int $micros): string
    {
        $w=intdiv($micros,1000000); $f=rtrim(str_pad((string)($micros%1000000),6,'0',STR_PAD_LEFT),'0'); return $f===''?(string)$w:$w.'.'.$f;
    }
    private function canonicalDecimal(string $value): string
    {
        $value=trim($value); if(!preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,6})?$/',$value)){throw new UnexpectedValueException('Amount is invalid.');} [$w,$f]=array_pad(explode('.',$value,2),2,'');$f=rtrim($f,'0');return $f===''?$w:$w.'.'.$f;
    }
    private function requestView(object $r, bool $canApprove, bool $canComplete): array
    {
        return ['id'=>(int)$r->id,'kol_id'=>(int)$r->kol_id,'affiliate_id'=>(int)$r->affiliate_id,'amount'=>(string)$r->amount,'currency'=>(string)$r->currency,'status'=>(string)$r->status,'provider_payout_id'=>$r->provider_payout_id===null?null:(int)$r->provider_payout_id,'payout_recipient'=>$canComplete?(string)$r->payout_recipient:null,'can_approve'=>$canApprove,'can_complete'=>$canComplete,'paid_at'=>$r->paid_at?->format('Y-m-d'),'requested_by_user_id'=>(int)$r->requested_by_user_id,'approved_by_user_id'=>$r->approved_by_user_id===null?null:(int)$r->approved_by_user_id,'referral_ids'=>$r->items->pluck('referral_id')->map(fn($v)=>(int)$v)->values()->all(),'created_at'=>$r->created_at?->toIso8601String()];
    }
}
