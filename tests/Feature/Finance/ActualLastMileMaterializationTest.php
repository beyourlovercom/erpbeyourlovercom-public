<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ActualLastMileMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_currency_actual_billing_replaces_p6a_estimate_and_is_idempotent(): void
    {
        $order = $this->order('USD');
        $shipment = $this->shipment($order, 'ACTUAL-1');
        $order->costEntries()->create([
            'cost_type' => 'last_mile_cost', 'amount' => '9.99', 'currency' => 'USD',
            'provenance' => 'estimated_reference', 'status' => 'estimated',
            'source_reference' => 'last-mile-estimate:v1:order:'.$order->id,
        ]);
        $billing = $shipment->billingEvidence()->create([
            'actual_cost' => '12.3400', 'currency' => 'USD', 'billing_document_number' => 'B-A1',
            'data_source' => 'carrier_bill_import:dhl', 'billed_at' => now(),
        ]);
        $service = app(CostMaterializationService::class);

        $candidate = $service->previewCarrierBillingGate($order)['candidates'][0];
        $this->assertSame('apply', $candidate['materialization_status']);
        $this->assertSame('provider_import', $candidate['provenance']);
        $this->assertSame('confirmed', $candidate['status']);
        $this->assertSame('12.34', $candidate['amount']);
        $this->assertSame('USD', $candidate['currency']);

        $service->applyCarrierBillingGate($order);
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->fresh();
        $this->assertSame('12.34', $entry->amount);
        $this->assertSame('provider_import', $entry->provenance);
        $this->assertSame('confirmed', $entry->status);
        $this->assertSame('carrier-billing:v1:shipment:'.$shipment->id.':billing:'.$billing->id, $entry->source_reference);
        $this->assertCount(1, $service->applyCarrierBillingGate($order->fresh())['noop']);
    }

    public function test_cross_currency_actual_billing_uses_fx_bridge_and_freezes_transaction_snapshot(): void
    {
        $this->manualFx();
        $order = $this->order('EUR', withFinancials: true);
        $shipment = $this->shipment($order, 'ACTUAL-FX');
        $shipment->billingEvidence()->create([
            'actual_cost' => '80.0000', 'currency' => 'CNY', 'provider_reference' => 'P-FX',
            'data_source' => 'carrier_bill_import:tianyun', 'billed_at' => now(),
        ]);
        $service = app(CostMaterializationService::class);

        $candidate = $service->previewCarrierBillingGate($order)['candidates'][0];
        $this->assertSame('apply', $candidate['materialization_status']);
        $this->assertSame('CNY', $candidate['source_currency']);
        $this->assertSame('80.00', $candidate['source_amount']);
        $this->assertSame('10.00', $candidate['amount']);
        $this->assertTrue($candidate['posting_fx']['freeze_transaction_fx']);
        $this->assertSame('10.67', $candidate['posting_fx']['base_amount']);

        $service->applyCarrierBillingGate($order);
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->fresh();
        $this->assertSame('EUR', $entry->currency);
        $this->assertSame('10.00', $entry->amount);
        $this->assertSame('USD', $entry->base_currency);
        $this->assertSame('10.67', $entry->base_amount);
        $this->assertNotNull($order->financials()->firstOrFail()->fresh()->fx_rate);
    }

    public function test_actual_billing_never_overwrites_stronger_manual_fact_or_different_provider_import(): void
    {
        foreach (['confirmed_manual', 'actual'] as $provenance) {
            $order = $this->order('USD'); $shipment = $this->shipment($order, 'STRONG-'.strtoupper($provenance));
            $shipment->billingEvidence()->create(['actual_cost'=>'5.0000','currency'=>'USD','billing_document_number'=>'NEW','data_source'=>'carrier_bill_import:dhl']);
            $order->costEntries()->create([
                'cost_type'=>'last_mile_cost','amount'=>'4.00','currency'=>'USD','provenance'=>$provenance,'status'=>'confirmed','source_reference'=>'strong:'.$provenance,
            ]);
            $candidate = app(CostMaterializationService::class)->previewCarrierBillingGate($order)['candidates'][0];
            $this->assertSame('blocked', $candidate['materialization_status']);
            $this->assertSame('stronger_or_different_existing_fact', $candidate['blocker']);
        }

        $order = $this->order('USD'); $shipment = $this->shipment($order, 'PROVIDER-DIFF');
        $shipment->billingEvidence()->create(['actual_cost'=>'7.0000','currency'=>'USD','billing_document_number'=>'NEW','data_source'=>'carrier_bill_import:dhl']);
        $order->costEntries()->create([
            'cost_type'=>'last_mile_cost','amount'=>'6.00','currency'=>'USD','provenance'=>'provider_import','status'=>'confirmed','source_reference'=>'carrier-billing:v1:shipment:999:billing:999',
        ]);
        $candidate = app(CostMaterializationService::class)->previewCarrierBillingGate($order)['candidates'][0];
        $this->assertSame('blocked', $candidate['materialization_status']);
    }

    public function test_command_is_dry_run_first_and_production_apply_is_exact_count_guarded(): void
    {
        $this->manualFx();
        $order = $this->order('EUR', withFinancials: true);
        $this->shipment($order, 'CMD-FX')->billingEvidence()->create([
            'actual_cost'=>'80.0000','currency'=>'CNY','billing_document_number'=>'CMD-BILL','data_source'=>'carrier_bill_import:tianyun',
        ]);
        $this->artisan('finance:materialize-actual-last-mile', ['--order'=>$order->id])->assertSuccessful();
        $this->assertFalse($order->costEntries()->where('cost_type','last_mile_cost')->exists());
        $this->assertNull($order->financials()->firstOrFail()->fresh()->fx_rate);

        $old=$this->app['env']; $this->app['env']='production'; putenv('ERP_ACCOUNTING_ACTUAL_LAST_MILE_PRODUCTION_APPLY_ENABLED');
        $args=['--order'=>$order->id,'--apply'=>true,'--expect-seen'=>'1','--expect-apply'=>'1','--expect-noop'=>'0','--expect-blocked'=>'0','--expect-fx-freeze'=>'1'];
        try {
            $this->artisan('finance:materialize-actual-last-mile',$args)->assertFailed();
            putenv('ERP_ACCOUNTING_ACTUAL_LAST_MILE_PRODUCTION_APPLY_ENABLED=true');
            $wrong=$args; $wrong['--expect-apply']='2';
            $this->artisan('finance:materialize-actual-last-mile',$wrong)->assertFailed();
            $this->assertFalse($order->costEntries()->where('cost_type','last_mile_cost')->exists());
            $this->artisan('finance:materialize-actual-last-mile',$args)->assertSuccessful();
            $this->assertDatabaseHas('order_cost_entries',['order_id'=>$order->id,'cost_type'=>'last_mile_cost','provenance'=>'provider_import','status'=>'confirmed']);
        } finally { putenv('ERP_ACCOUNTING_ACTUAL_LAST_MILE_PRODUCTION_APPLY_ENABLED'); $this->app['env']=$old; }
    }

    private function manualFx(): void
    {
        app(FxReferenceSettingsService::class)->save(['USD'=>7.5,'EUR'=>8.0,'CNY'=>1.0], ['USD'=>false,'EUR'=>false,'CNY'=>false], null);
    }

    private function order(string $currency, bool $withFinancials=false): Order
    {
        $order=Order::query()->create([
            'internal_order_number'=>'P6B-FIN-'.strtoupper(uniqid()),'source_type'=>'manual','order_kind'=>'customer',
            'order_date'=>'2026-09-14','order_status'=>'shipped','currency'=>$currency,
        ]);
        if($withFinancials) $order->financials()->create(['currency'=>$currency]);
        return $order;
    }

    private function shipment(Order $order,string $tracking): Shipment
    {
        return $order->shipment()->create(['shipment_status'=>'shipped','tracking_number'=>$tracking]);
    }
}
