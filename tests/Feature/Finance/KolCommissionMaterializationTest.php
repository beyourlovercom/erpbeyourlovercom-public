<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Logistics\Application\Contracts\OverseasHandlingEvidenceReader;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KolCommissionMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_usd_affiliate_source_converts_to_transaction_currency_and_freezes_fx(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $order = $this->order('EUR');
        $order->financials()->create(['amount_paid'=>'100.00','refund_amount'=>'0.00','currency'=>'EUR','revenue_provenance'=>'provider_import','completeness_status'=>'INCOMPLETE']);
        $this->referral($order, 1, '11.000000', 'USD', 'paid');
        $service = $this->app->make(CostMaterializationService::class);

        $candidate = $service->previewKolCommissionGate($order)['candidates'][0];
        self::assertSame('apply', $candidate['materialization_status']);
        self::assertSame('10.00', $candidate['amount']);
        self::assertSame('EUR', $candidate['currency']);
        self::assertSame('USD', $candidate['source_currency']);
        self::assertSame('11.00', $candidate['source_amount']);
        self::assertTrue($candidate['posting_fx']['freeze_transaction_fx']);

        $result = $service->applyKolCommissionGate($order);
        self::assertCount(1, $result['applied']);
        $entry = $order->costEntries()->where('cost_type','kol_commission')->firstOrFail();
        self::assertSame('10.00', $entry->amount);
        self::assertSame('EUR', $entry->currency);
        self::assertSame('provider_import', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
        self::assertSame('11.00', $entry->base_amount);
        self::assertSame('USD', $entry->base_currency);
        self::assertSame('1.10000000', $order->financials()->firstOrFail()->fresh()->fx_rate);
        self::assertSame('noop', $service->previewKolCommissionGate($order->fresh())['candidates'][0]['materialization_status']);
    }

    public function test_replay_keeps_same_affiliate_evidence_posting_snapshot_frozen_across_days(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');
        try {
            Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
            $order = $this->order('EUR');
            $order->financials()->create(['amount_paid'=>'100.00','refund_amount'=>'0.00','currency'=>'EUR','revenue_provenance'=>'provider_import','completeness_status'=>'INCOMPLETE']);
            $referral = $this->referral($order, 9, '11.000000', 'USD', 'paid');
            $service = $this->app->make(CostMaterializationService::class);

            $service->applyKolCommissionGate($order);
            $entry = $order->costEntries()->where('cost_type','kol_commission')->firstOrFail();
            $frozenDate = $entry->exchange_rate_date?->toDateString();
            $frozenBase = (string) $entry->base_amount;

            Carbon::setTestNow('2026-09-18 12:00:00');
            $sameEvidence = $service->previewKolCommissionGate($order->fresh())['candidates'][0];
            self::assertSame('noop', $sameEvidence['materialization_status']);
            self::assertSame('10.00', $sameEvidence['amount']);
            self::assertTrue($sameEvidence['posting_snapshot_frozen'] ?? false);
            self::assertCount(1, $service->applyKolCommissionGate($order->fresh())['noop']);
            self::assertSame($frozenDate, $entry->fresh()->exchange_rate_date?->toDateString());
            self::assertSame($frozenBase, (string) $entry->fresh()->base_amount);

            $referral->forceFill(['commission_amount' => '12.000000'])->save();
            $changedEvidence = $service->previewKolCommissionGate($order->fresh())['candidates'][0];
            self::assertSame('apply', $changedEvidence['materialization_status']);
            self::assertNotSame('10.00', $changedEvidence['amount']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mixed_source_currency_and_manual_strong_fact_fail_closed(): void
    {
        $mixed = $this->order('USD');
        $this->referral($mixed, 11, '1.000000', 'USD', 'paid');
        $this->referral($mixed, 12, '1.000000', 'EUR', 'unpaid');
        $candidate = $this->app->make(CostMaterializationService::class)->previewKolCommissionGate($mixed)['candidates'][0];
        self::assertSame('blocked', $candidate['materialization_status']);
        self::assertSame('foreign_or_mixed_currency_referrals', $candidate['blocker']);

        $manual = $this->order('USD');
        $this->referral($manual, 13, '5.000000', 'USD', 'paid');
        $manual->costEntries()->create(['cost_type'=>'kol_commission','amount'=>'9.00','currency'=>'USD','provenance'=>'confirmed_manual','status'=>'confirmed','source_reference'=>'owner:manual']);
        $candidate = $this->app->make(CostMaterializationService::class)->previewKolCommissionGate($manual)['candidates'][0];
        self::assertSame('blocked', $candidate['materialization_status']);
        self::assertSame('stronger_or_different_existing_fact', $candidate['blocker']);
        self::assertSame('9.00', $manual->costEntries()->where('cost_type','kol_commission')->value('amount'));
    }

    public function test_kol_commission_command_runs_when_overseas_handling_capability_is_not_installed(): void
    {
        $order = $this->order('USD');
        $this->referral($order, 19, '4.250000', 'USD', 'paid');
        $this->app->offsetUnset(OverseasHandlingEvidenceReader::class);
        self::assertFalse($this->app->bound(OverseasHandlingEvidenceReader::class));

        $this->artisan('finance:materialize-kol-commission', ['--order' => $order->id])
            ->expectsOutputToContain('"seen":1')
            ->assertSuccessful();
        self::assertFalse($order->costEntries()->where('cost_type', 'kol_commission')->exists());
    }

    public function test_production_command_requires_flag_and_exact_preflight(): void
    {
        $order = $this->order('USD');
        $this->referral($order, 21, '7.500000', 'USD', 'unpaid');
        $previous = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_ACCOUNTING_KOL_COMMISSION_PRODUCTION_APPLY_ENABLED');
        $base=['--order'=>$order->id,'--apply'=>true,'--expect-seen'=>'1','--expect-apply'=>'1','--expect-noop'=>'0','--expect-blocked'=>'0','--expect-fx-freeze'=>'0'];
        try {
            $this->artisan('finance:materialize-kol-commission',$base)->assertFailed();
            putenv('ERP_ACCOUNTING_KOL_COMMISSION_PRODUCTION_APPLY_ENABLED=true');
            $bad=$base;$bad['--expect-apply']='0';
            $this->artisan('finance:materialize-kol-commission',$bad)->assertFailed();
            $this->artisan('finance:materialize-kol-commission',$base)->assertSuccessful();
            self::assertSame('7.50',$order->costEntries()->where('cost_type','kol_commission')->value('amount'));
        } finally {
            putenv('ERP_ACCOUNTING_KOL_COMMISSION_PRODUCTION_APPLY_ENABLED');
            $this->app['env']=$previous;
        }
    }

    private function order(string $currency): Order
    {
        return Order::query()->create(['internal_order_number'=>'P8F-'.uniqid(),'source_type'=>'manual','order_kind'=>'customer','order_date'=>'2026-09-07','order_status'=>'pending_shipment','currency'=>$currency]);
    }

    private function referral(Order $order,int $id,string $amount,string $currency,string $status): KolAffiliateReferral
    {
        return KolAffiliateReferral::query()->create(['connection_key'=>'default','referral_id'=>$id,'affiliate_id'=>100+$id,'order_id'=>$order->id,'order_reference'=>(string)$order->id,'commission_amount'=>$amount,'currency'=>$currency,'provider_status'=>$status,'context'=>'woocommerce','referral_date'=>'2026-09-07 00:00:00','affiliate_resolution_status'=>'bound','order_resolution_status'=>'resolved','reconciliation_status'=>'present','sync_status'=>'synced','last_synced_at'=>'2026-09-07 00:00:00']);
    }

    private function ecbXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
  <Cube><Cube time="2026-09-07"><Cube currency="USD" rate="1.10"/><Cube currency="EUR" rate="1.00"/><Cube currency="CNY" rate="7.81"/></Cube></Cube>
</gesmes:Envelope>
XML;
    }
}
