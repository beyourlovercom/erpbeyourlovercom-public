<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentCarrierBilling;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CostMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_reference_materializes_only_unambiguous_same_currency_estimates(): void
    {
        $purchase = $this->order('USD');
        $this->attachProduct($purchase, ['standard_purchase_cost' => '2.5000', 'cost_currency' => 'USD'], 3);
        $this->seedRevenueAndOtherConfirmedCosts($purchase, except: 'product_cost');

        $result = $this->service()->apply($purchase);
        $entry = $purchase->costEntries()->where('cost_type', 'product_cost')->firstOrFail();
        self::assertSame('7.50', $entry->amount);
        self::assertSame('estimated_reference', $entry->provenance);
        self::assertSame('estimated', $entry->status);
        self::assertSame('ESTIMATED', $purchase->financials()->firstOrFail()->fresh()->completeness_status);
        self::assertCount(1, array_filter($result['applied'], fn (array $row): bool => ($row['cost_type'] ?? null) === 'product_cost'));

        $production = $this->order('USD');
        $this->attachProduct($production, [
            'standard_production_cost' => '2.0000',
            'standard_packaging_cost' => '0.5000',
            'cost_currency' => 'USD',
        ], 2);
        $preview = $this->service()->preview($production);
        $candidate = $this->candidate($preview, 'product_cost');
        self::assertSame('5.00', $candidate['amount']);
        self::assertSame('apply', $candidate['materialization_status']);

        $ambiguous = $this->order('USD');
        $this->attachProduct($ambiguous, [
            'standard_purchase_cost' => '1.0000',
            'standard_production_cost' => '1.0000',
            'cost_currency' => 'USD',
        ]);
        self::assertSame('blocked', $this->candidate($this->service()->preview($ambiguous), 'product_cost')['materialization_status']);

        $foreign = $this->order('USD');
        $this->attachProduct($foreign, ['standard_purchase_cost' => '1.0000', 'cost_currency' => 'EUR']);
        self::assertSame('blocked', $this->candidate($this->service()->preview($foreign), 'product_cost')['materialization_status']);
    }

    public function test_carrier_actual_is_confirmed_idempotent_and_never_overwrites_manual_fact(): void
    {
        $order = $this->order('USD');
        $this->attachBilling($order, '12.3400', 'USD', 'INV-1');

        $first = $this->service()->apply($order);
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail();
        self::assertSame('12.34', $entry->amount);
        self::assertSame('provider_import', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
        self::assertCount(1, array_filter($first['applied'], fn (array $row): bool => ($row['cost_type'] ?? null) === 'last_mile_cost'));

        $second = $this->service()->apply($order->fresh());
        self::assertCount(1, array_filter($second['noop'], fn (array $row): bool => ($row['cost_type'] ?? null) === 'last_mile_cost'));
        self::assertSame(1, $order->costEntries()->where('cost_type', 'last_mile_cost')->count());

        $manual = $this->order('USD');
        $this->attachBilling($manual, '99.0000', 'USD', 'INV-2');
        $manual->costEntries()->create([
            'cost_type' => 'last_mile_cost', 'amount' => '3.00', 'currency' => 'USD',
            'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'owner:manual',
        ]);
        $candidate = $this->candidate($this->service()->preview($manual), 'last_mile_cost');
        self::assertSame('blocked', $candidate['materialization_status']);
        $this->service()->apply($manual);
        self::assertSame('3.00', $manual->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->amount);
    }

    public function test_affiliate_commission_sums_only_resolved_present_paid_or_unpaid_and_blocks_mixed_currency(): void
    {
        $order = $this->order('USD');
        $this->referral($order, 1, '1.100000', 'USD', 'paid', 'resolved', 'present');
        $this->referral($order, 2, '2.200000', 'USD', 'unpaid', 'resolved', 'present');
        $this->referral($order, 3, '100.000000', 'USD', 'pending', 'resolved', 'present');
        $this->referral($order, 4, '100.000000', 'USD', 'rejected', 'resolved', 'present');
        $this->referral($order, 5, '100.000000', 'USD', 'failed', 'resolved', 'present');
        $this->referral($order, 6, '100.000000', 'USD', 'draft', 'resolved', 'present');
        $this->referral($order, 7, '50.000000', 'USD', 'paid', 'unresolved', 'present');
        $this->referral($order, 8, '60.000000', 'USD', 'paid', 'resolved', 'missing_from_snapshot');

        $result = $this->service()->apply($order);
        $entry = $order->costEntries()->where('cost_type', 'kol_commission')->firstOrFail();
        self::assertSame('3.30', $entry->amount);
        self::assertSame('provider_import', $entry->provenance);
        self::assertSame([1, 2], $this->candidate($result, 'kol_commission')['referral_ids']);

        $mixed = $this->order('USD');
        $this->referral($mixed, 11, '1.000000', 'USD', 'paid', 'resolved', 'present');
        $this->referral($mixed, 12, '1.000000', 'EUR', 'unpaid', 'resolved', 'present');
        $candidate = $this->candidate($this->service()->preview($mixed), 'kol_commission');
        self::assertSame('blocked', $candidate['materialization_status']);
        self::assertSame('foreign_or_mixed_currency_referrals', $candidate['blocker']);
        $this->service()->apply($mixed);
        self::assertFalse($mixed->costEntries()->where('cost_type', 'kol_commission')->exists());
    }

    public function test_partial_product_reference_and_stale_provider_evidence_fail_closed_without_deleting_existing_fact(): void
    {
        $partial = $this->order('USD');
        $this->attachProduct($partial, ['standard_purchase_cost' => '2.0000', 'cost_currency' => 'USD']);
        $this->attachProduct($partial, ['cost_currency' => 'USD']);
        $candidate = $this->candidate($this->service()->preview($partial), 'product_cost');
        self::assertSame('blocked', $candidate['materialization_status']);
        $this->service()->apply($partial);
        self::assertFalse($partial->costEntries()->where('cost_type', 'product_cost')->exists());

        $stale = $this->order('USD');
        $billing = $this->attachBilling($stale, '8.0000', 'USD', 'INV-STALE');
        $this->service()->apply($stale);
        $entry = $stale->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail();
        $billing->delete();

        $preview = $this->service()->preview($stale->fresh());
        $staleCandidate = $this->candidate($preview, 'last_mile_cost');
        self::assertSame('blocked', $staleCandidate['materialization_status']);
        self::assertTrue($staleCandidate['stale_source']);
        $this->service()->apply($stale->fresh());
        self::assertSame($entry->id, $stale->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->id);
        self::assertSame('8.00', $stale->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->amount);
    }

    public function test_console_command_is_dry_run_by_default_and_requires_explicit_apply(): void
    {
        $order = $this->order('USD');
        $this->attachProduct($order, ['standard_purchase_cost' => '4.0000', 'cost_currency' => 'USD']);

        $this->artisan('finance:materialize-costs', ['--order' => $order->id])->assertSuccessful();
        self::assertFalse($order->costEntries()->where('cost_type', 'product_cost')->exists());

        $this->artisan('finance:materialize-costs', ['--order' => $order->id, '--apply' => true])->assertSuccessful();
        self::assertSame('4.00', $order->costEntries()->where('cost_type', 'product_cost')->firstOrFail()->amount);
    }

    public function test_fixed_cost_command_is_dry_run_and_writes_only_fixed_rules(): void
    {
        $order = $this->order('USD');
        $this->attachWarehouseShipment($order, 'us-la');
        $this->attachProduct($order, ['standard_purchase_cost' => '4.0000', 'cost_currency' => 'USD']);

        $this->artisan('finance:materialize-fixed-costs', ['--order' => $order->id])->assertSuccessful();
        self::assertSame(0, $order->costEntries()->count());

        $this->artisan('finance:materialize-fixed-costs', ['--order' => $order->id, '--apply' => true])->assertSuccessful();
        self::assertSame('1.00', $order->costEntries()->where('cost_type', 'first_mile_cost')->firstOrFail()->amount);
        self::assertSame('0.00', $order->costEntries()->where('cost_type', 'customs_tax')->firstOrFail()->amount);
        self::assertFalse($order->costEntries()->where('cost_type', 'product_cost')->exists());

        $this->artisan('finance:materialize-fixed-costs', ['--order' => $order->id, '--apply' => true])->assertSuccessful();
        self::assertSame(1, $order->costEntries()->where('cost_type', 'first_mile_cost')->count());
        self::assertSame(1, $order->costEntries()->where('cost_type', 'customs_tax')->count());
    }

    public function test_fixed_cost_production_apply_requires_explicit_single_process_gate(): void
    {
        $order = $this->order('USD');
        $this->attachWarehouseShipment($order, 'us-la');
        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_ACCOUNTING_FIXED_COSTS_PRODUCTION_APPLY_ENABLED');

        try {
            $args = ['--order' => $order->id, '--apply' => true];
            $this->artisan('finance:materialize-fixed-costs', $args)->assertFailed();
            self::assertSame(0, $order->costEntries()->count());

            putenv('ERP_ACCOUNTING_FIXED_COSTS_PRODUCTION_APPLY_ENABLED=true');
            $this->artisan('finance:materialize-fixed-costs', $args)->assertSuccessful();
            self::assertSame(2, $order->costEntries()->count());
        } finally {
            putenv('ERP_ACCOUNTING_FIXED_COSTS_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $previousEnv;
        }
    }

    public function test_product_cost_gate_writes_only_confirmed_product_and_fx_released_first_mile(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $order = $this->order('EUR');
        $order->financials()->create([
            'amount_paid' => '100.00', 'refund_amount' => '0.00', 'currency' => 'EUR',
            'revenue_provenance' => 'provider_import', 'completeness_status' => 'INCOMPLETE',
        ]);
        $product = $this->attachProduct($order, []);
        $this->confirmedProductCost($product, 'LONG-P2', '70.00', 'CNY');
        $this->attachWarehouseShipment($order, 'de');

        $preview = $this->service()->previewProductCostGate($order);
        self::assertSame('apply', $this->candidate($preview, 'product_cost')['materialization_status']);
        self::assertSame('apply', $this->candidate($preview, 'first_mile_cost')['materialization_status']);
        self::assertCount(2, $preview['candidates']);

        $first = $this->service()->applyProductCostGate($order);
        self::assertCount(2, $first['applied']);
        self::assertTrue($order->costEntries()->where('cost_type', 'product_cost')->exists());
        self::assertTrue($order->costEntries()->where('cost_type', 'first_mile_cost')->exists());
        self::assertFalse($order->costEntries()->where('cost_type', 'customs_tax')->exists());
        self::assertFalse($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());
        self::assertFalse($order->costEntries()->where('cost_type', 'kol_commission')->exists());
        $financials = $order->financials()->firstOrFail()->fresh();
        self::assertNotNull($financials->fx_rate);
        self::assertNotNull($financials->fx_rate_date);
        self::assertNotNull($financials->fx_rate_source);

        $second = $this->service()->applyProductCostGate($order->fresh());
        self::assertCount(2, $second['noop']);
    }

    public function test_product_cost_gate_blocks_estimated_reference_product_cost(): void
    {
        $order = $this->order('USD');
        $this->attachProduct($order, ['standard_purchase_cost' => '4.0000', 'cost_currency' => 'USD']);
        $this->attachWarehouseShipment($order, 'us-la');

        $preview = $this->service()->previewProductCostGate($order);
        $product = $this->candidate($preview, 'product_cost');
        self::assertSame('blocked', $product['materialization_status']);
        self::assertSame('confirmed_product_cost_required_for_p2_gate', $product['blocker']);

        $this->service()->applyProductCostGate($order);
        self::assertFalse($order->costEntries()->where('cost_type', 'product_cost')->exists());
        self::assertSame('1.00', $order->costEntries()->where('cost_type', 'first_mile_cost')->firstOrFail()->amount);
        self::assertFalse($order->costEntries()->where('cost_type', 'customs_tax')->exists());
    }

    public function test_product_cost_production_command_requires_env_and_exact_preflight_counts(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $order = $this->order('USD');
        $product = $this->attachProduct($order, []);
        $this->confirmedProductCost($product, 'LONG-CMD', '70.00', 'CNY');
        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_ACCOUNTING_PRODUCT_COST_PRODUCTION_APPLY_ENABLED');

        try {
            $base = ['--order' => $order->id, '--apply' => true,
                '--expect-seen' => '1', '--expect-product-apply' => '1',
                '--expect-first-mile-apply' => '0', '--expect-fx-freeze' => '0'];
            $this->artisan('finance:materialize-product-costs', $base)->assertFailed();
            self::assertSame(0, $order->costEntries()->count());

            putenv('ERP_ACCOUNTING_PRODUCT_COST_PRODUCTION_APPLY_ENABLED=true');
            $wrong = $base;
            $wrong['--expect-product-apply'] = '2';
            $this->artisan('finance:materialize-product-costs', $wrong)->assertFailed();
            self::assertSame(0, $order->costEntries()->count());

            $this->artisan('finance:materialize-product-costs', $base)->assertSuccessful();
            self::assertSame(1, $order->costEntries()->where('cost_type', 'product_cost')->count());
            self::assertFalse($order->costEntries()->where('cost_type', 'customs_tax')->exists());
        } finally {
            putenv('ERP_ACCOUNTING_PRODUCT_COST_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $previousEnv;
        }
    }

    public function test_operational_scope_is_fail_closed_and_payment_fee_requires_provider_total(): void
    {
        $historical = $this->order('USD');
        $historical->externalReferences()->create([
            'provider' => 'historical_logistics', 'connection_key' => 'default', 'reference' => 'HIST-COST-1',
        ]);
        $historical->financials()->create(['currency' => 'USD', 'provider_order_total' => '100.00']);
        $preview = $this->service()->preview($historical);
        self::assertSame('blocked', $preview['scope_status']);
        self::assertSame('historical_logistics_excluded', $preview['scope_blocker']);
        $this->service()->apply($historical);
        self::assertFalse($historical->costEntries()->where('cost_type', 'payment_fee')->exists());

        $missingFinancials = $this->order('USD');
        $candidate = $this->candidate($this->service()->preview($missingFinancials), 'payment_fee');
        self::assertSame('payment_fee_order_financials_missing', $candidate['blocker']);

        $missingTotal = $this->order('USD');
        $missingTotal->financials()->create(['currency' => 'USD']);
        $candidate = $this->candidate($this->service()->preview($missingTotal), 'payment_fee');
        self::assertSame('payment_fee_provider_order_total_missing', $candidate['blocker']);
        self::assertNotContains('payment_fee', $this->service()->preview($missingTotal)['unsupported']);
    }

    public function test_payment_fee_is_provider_order_total_times_3_5_percent_and_refund_is_separate(): void
    {
        $order = $this->order('USD');
        $order->financials()->create([
            'currency' => 'USD', 'provider_order_total' => '100.00', 'refund_amount' => '25.00',
            'revenue_provenance' => 'provider_import', 'completeness_status' => 'INCOMPLETE',
        ]);

        $candidate = $this->candidate($this->service()->previewPaymentFeeGate($order), 'payment_fee');
        self::assertSame('3.50', $candidate['amount']);
        self::assertSame('provider_order_total', $candidate['basis']);
        self::assertSame('100.00', $candidate['basis_amount']);
        self::assertSame('0.03500000', $candidate['fee_rate']);
        self::assertSame('separate_no_fee_reversal', $candidate['refund_policy']);
        self::assertSame('confirmed_manual', $candidate['provenance']);
        self::assertSame('confirmed', $candidate['status']);

        $this->service()->applyPaymentFeeGate($order);
        $entry = $order->costEntries()->where('cost_type', 'payment_fee')->firstOrFail();
        self::assertSame('3.50', $entry->amount);
        self::assertSame('owner-rule:payment-fee:v1:provider-order-total:3.5pct', $entry->source_reference);

        $order->financials()->update(['refund_amount' => '90.00']);
        $candidate = $this->candidate($this->service()->previewPaymentFeeGate($order->fresh()), 'payment_fee');
        self::assertSame('noop', $candidate['materialization_status']);
        self::assertSame('3.50', $order->costEntries()->where('cost_type', 'payment_fee')->firstOrFail()->amount);

        $zero = $this->order('USD');
        $zero->financials()->create(['currency' => 'USD', 'provider_order_total' => '0.00']);
        self::assertSame('0.00', $this->candidate($this->service()->previewPaymentFeeGate($zero), 'payment_fee')['amount']);

        $negative = $this->order('USD');
        $negative->financials()->create(['currency' => 'USD', 'provider_order_total' => '-1.00']);
        self::assertSame('payment_fee_provider_order_total_negative', $this->candidate($this->service()->previewPaymentFeeGate($negative), 'payment_fee')['blocker']);
    }

    public function test_payment_fee_non_usd_freezes_fx_and_command_is_scoped_and_idempotent(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $order = $this->order('EUR');
        $order->financials()->create([
            'currency' => 'EUR', 'provider_order_total' => '100.00', 'refund_amount' => '0.00',
            'revenue_provenance' => 'provider_import', 'completeness_status' => 'INCOMPLETE',
        ]);
        $this->attachWarehouseShipment($order, 'de');

        $this->artisan('finance:materialize-payment-fees', ['--order' => $order->id])->assertSuccessful();
        self::assertSame(0, $order->costEntries()->count());
        $this->artisan('finance:materialize-payment-fees', ['--order' => $order->id, '--apply' => true])->assertSuccessful();

        $entry = $order->costEntries()->where('cost_type', 'payment_fee')->firstOrFail();
        self::assertSame('3.50', $entry->amount);
        self::assertSame('EUR', $entry->currency);
        self::assertSame('1.10000000', $entry->exchange_rate);
        self::assertSame('3.85', $entry->base_amount);
        self::assertSame('USD', $entry->base_currency);
        self::assertSame('1.10000000', $order->financials()->firstOrFail()->fresh()->fx_rate);
        self::assertFalse($order->costEntries()->where('cost_type', 'first_mile_cost')->exists());
        self::assertFalse($order->costEntries()->where('cost_type', 'customs_tax')->exists());

        // Simulate a later reference-rate change. The already-frozen transaction
        // snapshot must remain the source snapshot because the fee is denominated
        // in the same currency as the transaction.
        Cache::forget('ecb_fx_snapshot');
        Http::fake(['ecb.europa.eu/*' => Http::response(str_replace('rate="1.10"', 'rate="1.20"', $this->ecbXml()), 200)]);
        $candidate = $this->candidate($this->service()->previewPaymentFeeGate($order->fresh()), 'payment_fee');
        self::assertSame('noop', $candidate['materialization_status']);
        self::assertSame('1.00000000', $candidate['posting_fx']['source_to_transaction_rate']);
        self::assertSame('1.10000000', $candidate['posting_fx']['source_to_usd_rate']);

        $this->artisan('finance:materialize-payment-fees', ['--order' => $order->id, '--apply' => true])->assertSuccessful();
        self::assertSame(1, $order->costEntries()->where('cost_type', 'payment_fee')->count());
    }

    public function test_payment_fee_production_apply_requires_process_gate_and_exact_preflight_counts(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $order = $this->order('EUR');
        $order->financials()->create([
            'currency' => 'EUR', 'provider_order_total' => '100.00', 'refund_amount' => '0.00',
            'revenue_provenance' => 'provider_import', 'completeness_status' => 'INCOMPLETE',
        ]);

        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_ACCOUNTING_PAYMENT_FEE_PRODUCTION_APPLY_ENABLED');
        try {
            $base = [
                '--order' => $order->id, '--apply' => true,
                '--expect-seen' => '1', '--expect-apply' => '1',
                '--expect-blocked' => '0', '--expect-fx-freeze' => '1',
            ];
            $this->artisan('finance:materialize-payment-fees', $base)->assertFailed();
            self::assertSame(0, $order->costEntries()->count());

            putenv('ERP_ACCOUNTING_PAYMENT_FEE_PRODUCTION_APPLY_ENABLED=true');
            $wrong = $base;
            $wrong['--expect-apply'] = '2';
            $this->artisan('finance:materialize-payment-fees', $wrong)->assertFailed();
            self::assertSame(0, $order->costEntries()->count());
            self::assertNull($order->financials()->firstOrFail()->fresh()->fx_rate);

            $this->artisan('finance:materialize-payment-fees', $base)->assertSuccessful();
            self::assertSame(1, $order->costEntries()->where('cost_type', 'payment_fee')->count());
            self::assertFalse($order->costEntries()->where('cost_type', 'product_cost')->exists());
            self::assertFalse($order->costEntries()->where('cost_type', 'customs_tax')->exists());
            self::assertSame('1.10000000', $order->financials()->firstOrFail()->fresh()->fx_rate);
        } finally {
            putenv('ERP_ACCOUNTING_PAYMENT_FEE_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $previousEnv;
        }
    }

    public function test_owner_fixed_cost_rules_are_exact_and_fail_closed(): void
    {
        $overseasUsd = $this->order('USD');
        $this->attachWarehouseShipment($overseasUsd, 'us-la');
        $preview = $this->service()->preview($overseasUsd);
        $firstMile = $this->candidate($preview, 'first_mile_cost');
        self::assertSame('1.00', $firstMile['amount']);
        self::assertSame('USD', $firstMile['currency']);
        self::assertSame('apply', $firstMile['materialization_status']);
        $customs = $this->candidate($preview, 'customs_tax');
        self::assertSame('0.00', $customs['amount']);
        self::assertSame('USD', $customs['currency']);
        self::assertSame('apply', $customs['materialization_status']);

        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $overseasEur = $this->order('EUR');
        $overseasEur->financials()->create(['currency' => 'EUR', 'revenue_provenance' => 'provider_import', 'completeness_status' => 'INCOMPLETE']);
        $this->attachWarehouseShipment($overseasEur, 'de');
        $preview = $this->service()->preview($overseasEur);
        $firstMile = $this->candidate($preview, 'first_mile_cost');
        self::assertSame('apply', $firstMile['materialization_status']);
        self::assertSame('0.91', $firstMile['amount']);
        self::assertSame('USD', $firstMile['source_currency']);
        self::assertSame('1.00', $firstMile['source_amount']);
        self::assertSame('0.00', $this->candidate($preview, 'customs_tax')['amount']);
        $this->service()->apply($overseasEur);
        self::assertSame('0.91', $overseasEur->costEntries()->where('cost_type', 'first_mile_cost')->firstOrFail()->amount);
        self::assertSame('1.10000000', $overseasEur->financials()->firstOrFail()->fresh()->fx_rate);

        $chinaDirect = $this->order('EUR');
        $this->attachWarehouseShipment($chinaDirect, 'factory');
        $firstMile = $this->candidate($this->service()->preview($chinaDirect), 'first_mile_cost');
        self::assertSame('0.00', $firstMile['amount']);
        self::assertSame('EUR', $firstMile['currency']);
        self::assertSame('apply', $firstMile['materialization_status']);

        $unknown = $this->order('USD');
        $this->attachWarehouseShipment($unknown, 'mystery');
        $candidate = $this->candidate($this->service()->preview($unknown), 'first_mile_cost');
        self::assertSame('blocked', $candidate['materialization_status']);
        self::assertSame('first_mile_warehouse_classification_unknown', $candidate['blocker']);

        $missingShipment = $this->order('USD');
        $candidate = $this->candidate($this->service()->preview($missingShipment), 'first_mile_cost');
        self::assertSame('blocked', $candidate['materialization_status']);
        self::assertSame('canonical_shipment_missing', $candidate['blocker']);

        self::assertNotContains('first_mile_cost', $preview['unsupported']);
        self::assertNotContains('customs_tax', $preview['unsupported']);
        self::assertNotContains('payment_fee', $preview['unsupported']);
    }

    public function test_materialization_invalidates_reconciled_status_when_cost_truth_changes(): void
    {
        $order = $this->order('USD');
        $this->seedRevenueAndOtherConfirmedCosts($order, except: 'last_mile_cost');
        $order->costEntries()->create([
            'cost_type' => 'last_mile_cost', 'amount' => '1.00', 'currency' => 'USD',
            'provenance' => 'provider_import', 'status' => 'confirmed',
            'source_reference' => 'carrier-billing:v1:shipment:999:billing:999',
        ]);
        $order->financials()->update(['completeness_status' => 'RECONCILED']);

        // The changed canonical billing source cannot silently replace a different provider source.
        $this->attachBilling($order, '2.0000', 'USD', 'INV-RECON');
        $preview = $this->service()->preview($order);
        self::assertSame('blocked', $this->candidate($preview, 'last_mile_cost')['materialization_status']);
        self::assertSame('RECONCILED', $order->financials()->firstOrFail()->completeness_status);

        // An owned source update is allowed and must demote RECONCILED for fresh completeness evaluation.
        $billingCandidate = $this->candidate($preview, 'last_mile_cost');
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail();
        $entry->forceFill(['source_reference' => $billingCandidate['source_reference']])->save();
        $this->service()->apply($order->fresh());
        self::assertSame('CONFIRMED', $order->financials()->firstOrFail()->fresh()->completeness_status);
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

    private function service(): CostMaterializationService
    {
        return $this->app->make(CostMaterializationService::class);
    }

    private function order(string $currency, string $kind = 'customer'): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'CM-'.strtoupper(uniqid()),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'order_date' => '2026-09-07',
            'order_status' => 'pending_shipment',
            'currency' => $currency,
        ]);
    }

    private function attachProduct(Order $order, array $costs, int $quantity = 1): Product
    {
        $product = Product::query()->create([
            'source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'CM-SKU-'.strtoupper(uniqid()),
            'name' => 'Cost Materialization Test', ...$costs,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_summary' => $product->name,
            'sku' => $product->sku,
            'quantity' => $quantity,
            'unit_price' => '10.00',
            'line_revenue' => number_format(10 * $quantity, 2, '.', ''),
        ]);
        return $product;
    }

    private function confirmedProductCost(Product $product, string $sourceKey, string $unitCost, string $currency): ProductCostSource
    {
        return ProductCostSource::query()->create([
            'product_id' => $product->id,
            'source_key' => $sourceKey,
            'unit_cost' => $unitCost,
            'currency' => $currency,
            'provenance' => 'confirmed_source_file',
            'status' => 'confirmed',
            'source_type' => 'inventory_cost_book',
            'source_reference' => 'p2-gate-source.xlsx',
            'effective_from' => '2026-07-01',
            'source_at' => '2026-07-31 00:00:00',
            'confirmed_at' => '2026-09-08 00:00:00',
            'source_fingerprint' => hash('sha256', $product->id.'|'.$sourceKey.'|2026-07-01'),
        ]);
    }

    private function attachWarehouseShipment(Order $order, string $slug): Shipment
    {
        $warehouse = Warehouse::query()->firstOrCreate(
            ['slug' => $slug],
            ['code' => strtoupper($slug), 'name' => 'Test '.$slug,
                'type' => 'warehouse', 'ownership_type' => 'owned', 'is_active' => true, 'priority' => 100],
        );
        $shipment = new Shipment([
            'shipment_status' => 'pending', 'warehouse_id' => $warehouse->id, 'warehouse_selection_source' => 'uat_fixture',
        ]);
        $order->shipment()->save($shipment);
        return $shipment;
    }

    private function attachBilling(Order $order, string $amount, string $currency, string $document): ShipmentCarrierBilling
    {
        $shipment = new Shipment(['shipment_status' => 'pending']);
        $order->shipment()->save($shipment);
        return $shipment->billingEvidence()->create([
            'actual_cost' => $amount,
            'currency' => $currency,
            'billing_document_number' => $document,
            'data_source' => 'uat_fixture',
            'billed_at' => '2026-09-07 00:00:00',
        ]);
    }

    private function referral(Order $order, int $id, string $amount, string $currency, string $status, string $resolution, string $reconciliation): KolAffiliateReferral
    {
        return KolAffiliateReferral::query()->create([
            'connection_key' => 'default', 'referral_id' => $id, 'affiliate_id' => 100 + $id,
            'order_id' => $order->id, 'order_reference' => (string) $order->id,
            'commission_amount' => $amount, 'currency' => $currency, 'provider_status' => $status,
            'context' => 'sale', 'referral_date' => '2026-09-07 00:00:00',
            'order_resolution_status' => $resolution, 'reconciliation_status' => $reconciliation,
            'affiliate_resolution_status' => 'resolved', 'sync_status' => 'synced',
            'last_synced_at' => '2026-09-07 00:00:00',
        ]);
    }

    private function seedRevenueAndOtherConfirmedCosts(Order $order, string $except): void
    {
        $order->financials()->create([
            'amount_paid' => '100.00', 'refund_amount' => '0.00', 'currency' => $order->currency,
            'revenue_provenance' => 'confirmed_manual', 'completeness_status' => 'INCOMPLETE',
        ]);
        foreach ([
            'product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost',
            'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
        ] as $type) {
            if ($type === $except) {
                continue;
            }
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => '0.00', 'currency' => $order->currency,
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:'.$type,
            ]);
        }
    }

    private function candidate(array $payload, string $type): array
    {
        foreach ($payload['candidates'] as $candidate) {
            if (($candidate['cost_type'] ?? null) === $type) {
                return $candidate;
            }
        }
        self::fail('Missing candidate '.$type);
    }
}
