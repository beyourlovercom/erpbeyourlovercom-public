<?php

namespace Tests\Feature\Kol;

use App\Modules\Kol\Application\AffiliateWp\ReferralOrderResolutionService;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReferralOrderResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_resolution_is_exact_fail_closed_and_idempotent(): void
    {
        $order = $this->order('USD');
        $order->externalReferences()->create(['provider'=>'woocommerce','connection_key'=>'default','reference'=>'1001']);
        $ready = $this->referral(1, '1001', 'woocommerce');
        $missing = $this->referral(2, '9999', 'woocommerce');
        $nonWoo = $this->referral(3, '1001', 'affiliate');

        $service = $this->app->make(ReferralOrderResolutionService::class);
        $preview = $service->preview('default');
        self::assertSame(['dry_run',3,1,2,0,1,2], array_values($preview));

        $result = $service->apply('default');
        self::assertSame(1, $result['changed']);
        self::assertSame($order->id, $ready->fresh()->order_id);
        self::assertSame('resolved', $ready->fresh()->order_resolution_status);
        self::assertNull($missing->fresh()->order_id);
        self::assertSame('unresolved', $nonWoo->fresh()->order_resolution_status);
        self::assertSame('10.000000', $ready->fresh()->commission_amount);
        self::assertSame('paid', $ready->fresh()->provider_status);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'kol.referral_order_resolution.applied',
            'entity_type' => 'kol_affiliate_referrals',
            'entity_id' => 'default',
        ]);

        $replay = $service->preview('default');
        self::assertSame(0, $replay['changed']);
        self::assertSame(3, $replay['noop']);
    }

    public function test_production_command_requires_process_flag_and_exact_counts(): void
    {
        $order = $this->order('USD');
        $order->externalReferences()->create(['provider'=>'woocommerce','connection_key'=>'default','reference'=>'1001']);
        $this->referral(1, '1001', 'woocommerce');
        $this->referral(2, '9999', 'woocommerce');
        $previous = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_KOL_REFERRAL_ORDER_RESOLUTION_PRODUCTION_APPLY_ENABLED');
        $base = ['--connection'=>'default','--apply'=>true,'--expect-seen'=>'2','--expect-ready'=>'1','--expect-unresolved'=>'1','--expect-ambiguous'=>'0','--expect-change'=>'1'];
        try {
            $this->artisan('kol:reresolve-referral-orders', $base)->assertFailed();
            self::assertNull(KolAffiliateReferral::where('referral_id',1)->value('order_id'));
            putenv('ERP_KOL_REFERRAL_ORDER_RESOLUTION_PRODUCTION_APPLY_ENABLED=true');
            $bad = $base; $bad['--expect-ready'] = '2';
            $this->artisan('kol:reresolve-referral-orders', $bad)->assertFailed();
            $this->artisan('kol:reresolve-referral-orders', $base)->assertSuccessful();
            self::assertSame($order->id, KolAffiliateReferral::where('referral_id',1)->value('order_id'));
        } finally {
            putenv('ERP_KOL_REFERRAL_ORDER_RESOLUTION_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $previous;
        }
    }

    private function order(string $currency): Order
    {
        return Order::query()->create(['internal_order_number'=>'P8-'.uniqid(),'source_type'=>'manual','order_kind'=>'customer','order_date'=>'2026-09-16','order_status'=>'pending_shipment','currency'=>$currency]);
    }

    private function referral(int $id, string $reference, string $context): KolAffiliateReferral
    {
        return KolAffiliateReferral::query()->create(['connection_key'=>'default','referral_id'=>$id,'affiliate_id'=>100+$id,'order_reference'=>$reference,'commission_amount'=>'10.000000','currency'=>'USD','provider_status'=>'paid','context'=>$context,'referral_date'=>'2026-09-01 00:00:00','affiliate_resolution_status'=>'bound','order_resolution_status'=>'unresolved','reconciliation_status'=>'present','sync_status'=>'synced']);
    }
}
