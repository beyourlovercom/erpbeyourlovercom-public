<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\AffiliateWp\ConnectorException;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\AffiliateWp\PayoutSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\PayoutWorkflowService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliatePayout;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Kol\Infrastructure\Models\KolPayoutRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use UnexpectedValueException;

class KolPayoutWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private const CONNECTION = 'test.example.com';

    private function userWith(array $permissions): User
    {
        $role=Role::create(['key'=>'payout_'.uniqid(),'label'=>'Payout Test']);
        foreach($permissions as $key){$permission=Permission::firstOrCreate(['key'=>$key],['label'=>$key]);$role->permissions()->attach($permission);}
        $user=User::factory()->create(['is_active'=>true]);$user->roles()->attach($role);return $user;
    }

    private function kolWithAffiliate(User $owner, int $affiliateId, ?User $manager = null, ?User $finance = null): Kol
    {
        $kol=Kol::create(['display_name'=>'Payout KOL '.$affiliateId,'status'=>'active','relationship_stage'=>'prospect','owner_id'=>$owner->id,'approval_manager_id'=>$manager?->id,'finance_owner_id'=>$finance?->id,'approval_status'=>'approved','payout_method'=>'paypal','payout_account'=>'creator-paypal@example.test','contact_email'=>'pay-'.$affiliateId.'@example.test']);
        KolAffiliateAccount::create(['connection_key'=>self::CONNECTION,'affiliate_id'=>$affiliateId,'kol_id'=>$kol->id,'provider_status'=>'active','origin'=>'external_sync','sync_status'=>'synced','reconciliation_status'=>'present','provisioning_status'=>'not_requested']);
        return $kol;
    }

    private function referral(Kol $kol, int $affiliateId, int $referralId, string $amount='5.00', string $currency='USD', string $status='unpaid', ?int $payoutId=null): KolAffiliateReferral
    {
        return KolAffiliateReferral::create(['connection_key'=>self::CONNECTION,'referral_id'=>$referralId,'affiliate_id'=>$affiliateId,'kol_id'=>$kol->id,'order_id'=>null,'order_reference'=>(string)(8000+$referralId),'description'=>'Payout referral','commission_amount'=>$amount,'currency'=>$currency,'provider_status'=>$status,'context'=>'woocommerce','referral_date'=>'2026-09-01 10:00:00','payout_id'=>$payoutId,'affiliate_resolution_status'=>'bound','order_resolution_status'=>'unresolved','reconciliation_status'=>'present','sync_status'=>'synced','last_synced_at'=>now(),'last_successful_sync_at'=>now()]);
    }

    private function mockGateway(): AffiliateWpGateway&MockInterface
    {
        $mock=Mockery::mock(AffiliateWpGateway::class);$mock->shouldReceive('descriptor')->andReturn(['connection_key'=>self::CONNECTION])->byDefault();$this->app->instance(AffiliateWpGateway::class,$mock);return $mock;
    }

    private function response(array $data): ConnectorResponse
    {
        return ConnectorResponse::fromArray(['ok'=>true,'contract_version'=>'1','request_id'=>uniqid('payout-',true),'connection_key'=>self::CONNECTION,'data'=>$data]);
    }

    public function test_payout_snapshot_mirrors_provider_rows_including_historical_zero_referral_payout(): void
    {
        $finance=$this->userWith(['kol.payouts.manage']);$kol=$this->kolWithAffiliate($finance,7);$gateway=$this->mockGateway();
        $gateway->shouldReceive('snapshot')->once()->with('payouts',null,null)->andReturn($this->response(['items'=>[
            ['payout_id'=>1,'affiliate_id'=>7,'referral_ids'=>[11],'amount'=>'5.00','currency'=>'USD','payout_method'=>'manual','status'=>'paid','date'=>'2026-08-01T00:00:00+00:00'],
            ['payout_id'=>2,'affiliate_id'=>7,'referral_ids'=>[],'amount'=>'1','currency'=>'USD','payout_method'=>'manual','status'=>'paid','date'=>'2026-08-02T00:00:00+00:00'],
        ],'next_cursor'=>null]));
        $result=$this->app->make(PayoutSnapshotSyncService::class)->sync(self::CONNECTION,$finance);
        self::assertSame(2,$result['synced']);self::assertSame(0,$result['kol_unresolved']);self::assertSame([] ,KolAffiliatePayout::where('payout_id',2)->firstOrFail()->referral_ids);self::assertSame($kol->id,KolAffiliatePayout::where('payout_id',1)->value('kol_id'));
    }

    public function test_create_request_locks_exact_eligible_referrals_and_sums_amount(): void
    {
        $manager=$this->userWith(['kol.payouts.request']);$kol=$this->kolWithAffiliate($manager,7);$this->referral($kol,7,11,'4.25');$this->referral($kol,7,12,'5.75');$this->mockGateway();
        $request=$this->app->make(PayoutWorkflowService::class)->create([12,11],$manager);
        self::assertSame('pending_approval',$request->status);self::assertSame('10.000000',(string)$request->amount);self::assertSame([11,12],$request->items->pluck('referral_id')->all());self::assertNull($request->paypal_transaction_id);self::assertSame('creator-paypal@example.test',$request->payout_recipient);self::assertNotEmpty($request->idempotency_key);
        self::assertNotSame('creator-paypal@example.test',DB::table('kol_payout_requests')->where('id',$request->id)->value('payout_recipient'));
        $this->expectException(UnexpectedValueException::class);$this->app->make(PayoutWorkflowService::class)->create([11],$manager);
    }

    public function test_request_reject_releases_referrals_for_a_new_request(): void
    {
        $specialist=$this->userWith(['kol.payouts.request']);$manager=$this->userWith(['kol.payouts.approve']);$kol=$this->kolWithAffiliate($specialist,7,$manager);$this->referral($kol,7,11);$this->mockGateway();$service=$this->app->make(PayoutWorkflowService::class);
        $first=$service->create([11],$specialist);$service->reject($first->id,'Not approved',$manager);$second=$service->create([11],$specialist);
        self::assertSame('rejected',$first->fresh()->status);self::assertSame('pending_approval',$second->status);self::assertNotSame($first->id,$second->id);
    }

    public function test_requester_cannot_approve_their_own_request_even_with_manage_permission(): void
    {
        $actor=$this->userWith(['kol.payouts.request','kol.payouts.approve']);$kol=$this->kolWithAffiliate($actor,7,$actor);$this->referral($kol,7,11);$this->mockGateway();$service=$this->app->make(PayoutWorkflowService::class);$row=$service->create([11],$actor);
        $this->expectException(AuthorizationException::class);$service->approve($row->id,$actor);
    }

    public function test_manager_approval_then_finance_exact_complete_marks_request_and_referrals_paid(): void
    {
        $specialist=$this->userWith(['kol.payouts.request']);$manager=$this->userWith(['kol.payouts.approve']);$finance=$this->userWith(['kol.payouts.manage']);$kol=$this->kolWithAffiliate($specialist,7,$manager,$finance);$this->referral($kol,7,11,'4.25');$this->referral($kol,7,12,'5.75');$gateway=$this->mockGateway();$service=$this->app->make(PayoutWorkflowService::class);$row=$service->create([11,12],$specialist);$service->approve($row->id,$manager);$key=$row->idempotency_key;
        $gateway->shouldReceive('completePayout')->once()->with(Mockery::on(function(array $p) use($row){ self::assertSame(['erp_payout_request_id','affiliate_id','referral_ids','amount','currency','payout_method','paypal_transaction_id','paid_at'],array_keys($p));self::assertSame($row->id,$p['erp_payout_request_id']);self::assertSame([11,12],$p['referral_ids']);self::assertSame('10',$p['amount']);self::assertSame('paypal_manual',$p['payout_method']);return true;}),$key)->andReturn($this->response(['payout_id'=>99,'affiliate_id'=>7,'referral_ids'=>[11,12],'amount'=>'10','currency'=>'USD','payout_method'=>'paypal_manual','status'=>'paid','date'=>'2026-09-01']));
        $paid=$service->complete($row->id,'PAYPAL-TXN-123','2026-09-01',$finance);
        self::assertSame('paid',$paid->status);self::assertSame(99,$paid->provider_payout_id);self::assertSame(2,KolAffiliateReferral::where('payout_id',99)->where('provider_status','paid')->count());self::assertDatabaseHas('kol_affiliate_payouts',['payout_id'=>99,'provider_status'=>'paid']);
        $raw=DB::table('kol_payout_requests')->where('id',$row->id)->value('paypal_transaction_id');self::assertNotSame('PAYPAL-TXN-123',$raw);self::assertSame('PAYPAL-TXN-123',$paid->paypal_transaction_id);
    }

    public function test_provider_error_persists_retry_state_and_retry_requires_same_evidence(): void
    {
        $specialist=$this->userWith(['kol.payouts.request']);$manager=$this->userWith(['kol.payouts.approve']);$finance=$this->userWith(['kol.payouts.manage']);$kol=$this->kolWithAffiliate($specialist,7,$manager,$finance);$this->referral($kol,7,11);$gateway=$this->mockGateway();$service=$this->app->make(PayoutWorkflowService::class);$row=$service->create([11],$specialist);$service->approve($row->id,$manager);$key=$row->idempotency_key;
        $gateway->shouldReceive('completePayout')->once()->with(Mockery::type('array'),$key)->andThrow(ConnectorException::retryable('rate limited','RATE_LIMITED',429));
        try{$service->complete($row->id,'TXN-RETRY','2026-09-01',$finance);self::fail('expected connector error');}catch(ConnectorException $e){self::assertSame('RATE_LIMITED',$e->errorCode);}
        self::assertSame('provider_error',$row->fresh()->status);
        $this->expectException(UnexpectedValueException::class);$service->complete($row->id,'DIFFERENT','2026-09-01',$finance);
    }

    public function test_readback_mismatch_never_marks_paid(): void
    {
        $specialist=$this->userWith(['kol.payouts.request']);$manager=$this->userWith(['kol.payouts.approve']);$finance=$this->userWith(['kol.payouts.manage']);$kol=$this->kolWithAffiliate($specialist,7,$manager,$finance);$this->referral($kol,7,11);$gateway=$this->mockGateway();$service=$this->app->make(PayoutWorkflowService::class);$row=$service->create([11],$specialist);$service->approve($row->id,$manager);
        $gateway->shouldReceive('completePayout')->once()->andReturn($this->response(['payout_id'=>99,'affiliate_id'=>7,'referral_ids'=>[11],'amount'=>'999','currency'=>'USD','payout_method'=>'paypal_manual','status'=>'paid','date'=>'2026-09-01']));
        try{$service->complete($row->id,'TXN-MISMATCH','2026-09-01',$finance);self::fail('expected mismatch');}catch(\RuntimeException){}
        self::assertSame('readback_mismatch',$row->fresh()->status);self::assertNull(KolAffiliateReferral::where('referral_id',11)->value('payout_id'));
    }

    public function test_request_requires_frozen_paypal_recipient_profile(): void
    {
        $manager=$this->userWith(['kol.payouts.request']);$kol=$this->kolWithAffiliate($manager,7);$kol->update(['payout_method'=>'bank','payout_account'=>'bank-destination']);$this->referral($kol,7,11);$this->mockGateway();
        $this->expectException(UnexpectedValueException::class);
        $this->app->make(PayoutWorkflowService::class)->create([11],$manager);
    }

    public function test_payout_visibility_and_actions_are_limited_to_assigned_responsibility(): void
    {
        $specialistA=$this->userWith(['kol.payouts.request']); $specialistB=$this->userWith(['kol.payouts.request']);
        $managerA=$this->userWith(['kol.payouts.approve']); $managerB=$this->userWith(['kol.payouts.approve']);
        $financeA=$this->userWith(['kol.payouts.manage']); $financeB=$this->userWith(['kol.payouts.manage']);
        $kolA=$this->kolWithAffiliate($specialistA,71,$managerA,$financeA); $kolB=$this->kolWithAffiliate($specialistB,72,$managerB,$financeB);
        $this->referral($kolA,71,711); $this->referral($kolB,72,721); $this->mockGateway(); $service=$this->app->make(PayoutWorkflowService::class);
        $requestA=$service->create([711],$specialistA); $requestB=$service->create([721],$specialistB);

        self::assertSame([$requestA->id], collect($service->workspace($managerA)['requests'])->pluck('id')->all());
        self::assertSame([$requestB->id], collect($service->workspace($managerB)['requests'])->pluck('id')->all());
        self::assertSame([$requestA->id], collect($service->workspace($financeA)['requests'])->pluck('id')->all());
        self::assertSame([$requestB->id], collect($service->workspace($financeB)['requests'])->pluck('id')->all());

        try { $service->approve($requestB->id,$managerA); self::fail('manager A must not approve manager B KOL'); } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {}
        $service->approve($requestA->id,$managerA);
        try { $service->complete($requestA->id,'TXN-FOREIGN','2026-09-01',$financeB); self::fail('finance B must not complete finance A KOL'); } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {}
        $this->actingAs($financeB)->post("/kol/payouts/{$requestA->id}/complete", ['paypal_transaction_id'=>'SAFE-FOREIGN','paid_at'=>'2026-09-01'])->assertNotFound();
    }

}
