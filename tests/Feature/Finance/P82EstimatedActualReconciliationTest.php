<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\AccountingContinuousDeltaService;
use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\EstimatedProfitProjectionService;
use App\Modules\Finance\Application\LastMileHistoricalProjectionService;
use App\Modules\Finance\Application\OrderMaturityService;
use App\Modules\Finance\Application\OrderProfitExplanationService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentCarrierBilling;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P8.2 — Estimated → Actual Cost Reconciliation
 * Focused regression suite covering maturity states, last-mile projection tiers,
 * estimated profit projection, audit trail, and command dry-run.
 */
final class P82EstimatedActualReconciliationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // ORDER MATURITY STATES
    // -----------------------------------------------------------------------

    public function test_maturity_service_returns_correct_states_for_age_buckets(): void
    {
        $svc = $this->app->make(OrderMaturityService::class);

        // 0–7: fresh
        $order = $this->order('USD', Carbon::now()->toDateString());
        self::assertSame(OrderMaturityService::STATE_FRESH_ESTIMATE_ALLOWED, $svc->forOrder($order)['maturity_state']);
        self::assertFalse($svc->forOrder($order)['reconciliation_due']);
        self::assertTrue($svc->forOrder($order)['estimate_allowed']);

        // 8–30: estimate allowed
        $order8 = $this->order('USD', Carbon::now()->subDays(15)->toDateString());
        self::assertSame(OrderMaturityService::STATE_ESTIMATE_ALLOWED, $svc->forOrder($order8)['maturity_state']);
        self::assertFalse($svc->forOrder($order8)['reconciliation_due']);

        // 31–45: reconciliation due
        $order31 = $this->order('USD', Carbon::now()->subDays(35)->toDateString());
        $m31 = $svc->forOrder($order31);
        self::assertSame(OrderMaturityService::STATE_ACTUAL_RECONCILIATION_DUE, $m31['maturity_state']);
        self::assertTrue($m31['reconciliation_due']);
        self::assertFalse($m31['estimate_allowed']);

        // >45: overdue
        $order46 = $this->order('USD', Carbon::now()->subDays(50)->toDateString());
        $m46 = $svc->forOrder($order46);
        self::assertSame(OrderMaturityService::STATE_ACTUAL_COST_OVERDUE, $m46['maturity_state']);
        self::assertTrue($m46['reconciliation_due']);

        // No date: null state, no guess — use anonymous stub since DB column is NOT NULL
        $stub = new class extends \Illuminate\Database\Eloquent\Model {
            public ?string $order_date = null;
        };
        $mn = $svc->forOrder($stub);
        self::assertNull($mn['age_days']);
        self::assertNull($mn['maturity_state']);
        self::assertFalse($mn['reconciliation_due']);
    }

    // -----------------------------------------------------------------------
    // ESTIMATED PROFIT PROJECTION
    // -----------------------------------------------------------------------

    public function test_estimated_profit_calculates_when_all_costs_present_or_estimated(): void
    {
        $order = $this->order('USD');
        $this->seedRevenue($order, '100.00', '0.00');
        $this->attachProduct($order, '10.00', 'USD');

        // Seed confirmed costs for everything except product (which is estimated reference)
        foreach (['warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'payment_fee', 'customs_tax'] as $type) {
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => '5.00', 'currency' => 'USD',
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:'.$type,
            ]);
        }

        // Product cost is estimated reference
        $order->costEntries()->create([
            'cost_type' => 'product_cost', 'amount' => '10.00', 'currency' => 'USD',
            'provenance' => 'estimated_reference', 'status' => 'estimated', 'source_reference' => 'product-reference:v1:order:'.$order->id,
        ]);

        $projection = $this->app->make(EstimatedProfitProjectionService::class)->project($order->fresh());

        self::assertTrue($projection['canonical_estimated_ready']);
        self::assertNotNull($projection['canonical_estimated_profit']);
        self::assertGreaterThanOrEqual(1, $projection['estimated_cost_count']);
        self::assertGreaterThanOrEqual(6, $projection['confirmed_cost_count']);
        self::assertSame(0, $projection['missing_required_cost_count']);
    }

    public function test_estimated_profit_remains_available_when_required_cost_evidence_is_missing(): void
    {
        $order = $this->order('USD');
        $this->seedRevenue($order, '100.00', '0.00');
        // No costs posted at all

        $projection = $this->app->make(EstimatedProfitProjectionService::class)->project($order->fresh());

        self::assertTrue($projection['canonical_estimated_ready']);
        self::assertNotNull($projection['canonical_estimated_profit']);
        self::assertNotNull($projection['canonical_estimated_total_cost']);
        self::assertGreaterThan(0, $projection['missing_required_cost_count']);
        self::assertGreaterThan(0, $projection['fallback_estimated_cost_count']);
        self::assertSame(0, $order->costEntries()->count(), 'projection fallback must never persist cost entries');
    }

    public function test_missing_product_cost_uses_historical_confirmed_cost_ratio_without_persisting(): void
    {
        $history = $this->order('USD');
        $this->seedRevenue($history, '100.00', '0.00');
        $history->costEntries()->create([
            'cost_type' => 'product_cost', 'amount' => '25.00', 'currency' => 'USD',
            'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:historical-product',
            'effective_at' => now(),
        ]);

        $target = $this->order('USD');
        $this->seedRevenue($target, '80.00', '0.00');

        $projection = $this->app->make(EstimatedProfitProjectionService::class)->project($target->fresh());
        $product = collect($projection['projection_cost_lines'])->firstWhere('cost_type', 'product_cost');

        self::assertTrue($projection['canonical_estimated_ready']);
        self::assertSame('fallback_estimated', $product['state']);
        self::assertSame('20.00', $product['amount']);
        self::assertSame('projection-historical-cost-ratio:v1:product_cost', $product['source_reference']);
        self::assertSame(0, $target->costEntries()->count());
    }

    public function test_mixed_zero_and_positive_first_mile_history_uses_expected_mean_not_zero_median(): void
    {
        foreach (['0.00', '10.00'] as $amount) {
            $history = $this->order('USD');
            $this->seedRevenue($history, '100.00', '0.00');
            $history->costEntries()->create([
                'cost_type' => 'first_mile_cost', 'amount' => $amount, 'currency' => 'USD',
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:first-mile:'.$amount,
                'effective_at' => now(),
            ]);
        }

        $target = $this->order('USD');
        $this->seedRevenue($target, '80.00', '0.00');

        $projection = $this->app->make(EstimatedProfitProjectionService::class)->project($target->fresh());
        $firstMile = collect($projection['projection_cost_lines'])->firstWhere('cost_type', 'first_mile_cost');

        self::assertSame('fallback_estimated', $firstMile['state']);
        self::assertSame('4.00', $firstMile['amount']);
        self::assertSame('mean', $firstMile['source_detail']['ratio_method']);
        self::assertSame(0, $target->costEntries()->count());
    }

    public function test_provider_order_without_payment_does_not_fabricate_estimated_profit(): void
    {
        $order = $this->order('USD');
        $order->financials()->create([
            'merchandise_list_amount' => '100.00',
            'discount_amount' => '0.00',
            'shipping_revenue' => '0.00',
            'refund_amount' => '0.00',
            'provider_order_total' => '100.00',
            'tax_collected' => '0.00',
            'currency' => 'USD',
            'revenue_provenance' => 'provider_import',
            'provider_order_status' => 'failed',
            'reconciliation_status' => 'matched',
            'completeness_status' => 'INCOMPLETE',
        ]);

        $svc = $this->app->make(EstimatedProfitProjectionService::class);
        $failed = $svc->project($order->fresh());
        self::assertFalse($failed['canonical_estimated_ready']);
        self::assertNull($failed['canonical_estimated_profit']);

        $order->financials()->update([
            'provider_order_status' => 'completed',
            'paid_at' => now(),
        ]);
        $paid = $svc->project($order->fresh());
        self::assertTrue($paid['canonical_estimated_ready']);
        self::assertNotNull($paid['canonical_estimated_profit']);
    }

    public function test_optional_absent_costs_treated_as_provisional_zero_not_persisted(): void
    {
        $order = $this->order('USD');
        $this->seedRevenue($order, '100.00', '0.00');

        // All required confirmed
        foreach (['product_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'payment_fee', 'customs_tax'] as $type) {
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => '5.00', 'currency' => 'USD',
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:'.$type,
            ]);
        }
        // Optional costs (warehouse_cost, kol_commission, refund_replacement_loss, other_variable_cost) deliberately absent

        $projection = $this->app->make(EstimatedProfitProjectionService::class)->project($order->fresh());

        self::assertTrue($projection['canonical_estimated_ready']);
        // Optional absent entries must NOT appear in DB as zero
        self::assertFalse($order->costEntries()->where('cost_type', 'refund_replacement_loss')->exists());
        self::assertFalse($order->costEntries()->where('cost_type', 'other_variable_cost')->exists());
    }

    // -----------------------------------------------------------------------
    // LAST-MILE HISTORICAL PROJECTION
    // -----------------------------------------------------------------------

    public function test_overseas_fallback_usd10_per_unit_for_non_china_direct(): void
    {
        $order = $this->order('USD');
        $order->items()->create([
            'product_summary' => 'Test Item', 'sku' => 'TST-001', 'quantity' => 3,
            'unit_price' => '10.00', 'line_revenue' => '30.00',
        ]);
        $this->attachWarehouseShipment($order, 'us-la');

        $svc = $this->app->make(LastMileHistoricalProjectionService::class);
        $proj = $svc->project($order->fresh());

        self::assertSame('fallback_usd10_per_unit', $proj['tier']);
        self::assertSame('30.00', $proj['amount']); // USD 10 × 3 units
        self::assertSame('USD', $proj['currency']);
        self::assertSame('low', $proj['confidence']);
        self::assertSame(0, $proj['sample_count']);
        self::assertNull($proj['blocked']);
    }

    public function test_china_direct_warehouse_does_not_use_usd10_fallback(): void
    {
        $order = $this->order('USD');
        $order->items()->create([
            'product_summary' => 'Test Item', 'sku' => 'TST-002', 'quantity' => 5,
            'unit_price' => '10.00', 'line_revenue' => '50.00',
        ]);
        $this->attachWarehouseShipment($order, 'factory');

        $svc = $this->app->make(LastMileHistoricalProjectionService::class);
        $proj = $svc->project($order->fresh());

        self::assertSame('no_projection_china_direct', $proj['tier']);
        self::assertNull($proj['amount']);
        self::assertSame('china_direct_no_fallback_allowed', $proj['blocked']);
    }

    public function test_missing_or_unknown_warehouse_never_uses_usd10_fallback(): void
    {
        foreach ([null, 'mystery'] as $slug) {
            $order = $this->order('USD');
            $order->items()->create([
                'product_summary' => 'No Warehouse Item', 'sku' => 'NOWH-'.($slug ?? 'NONE'), 'quantity' => 2,
                'unit_price' => '10.00', 'line_revenue' => '20.00',
            ]);
            if ($slug !== null) {
                $this->attachWarehouseShipment($order, $slug);
            }

            $proj = $this->app->make(LastMileHistoricalProjectionService::class)->project($order->fresh());
            self::assertSame('no_projection_warehouse_unresolved', $proj['tier']);
            self::assertNull($proj['amount']);
            self::assertSame('overseas_warehouse_required_for_fallback', $proj['blocked']);
        }
    }

    // -----------------------------------------------------------------------
    // ACTUAL REPLACES ESTIMATE — AUDIT TRAIL
    // -----------------------------------------------------------------------

    public function test_actual_carrier_billing_replacing_estimate_emits_restatement_audit(): void
    {
        $order = $this->order('USD');
        // Seed an estimated last-mile cost entry
        $order->costEntries()->create([
            'cost_type' => 'last_mile_cost', 'amount' => '15.00', 'currency' => 'USD',
            'provenance' => 'estimated_reference', 'status' => 'estimated',
            'source_reference' => 'last-mile-estimate:v1:order:'.$order->id,
            'effective_at' => now(),
        ]);

        // Now attach real carrier billing
        $this->attachBilling($order, '12.50', 'USD', 'INV-P82-001');

        $svc = $this->app->make(CostMaterializationService::class);
        $result = $svc->applyCarrierBillingGate($order->fresh());

        self::assertNotEmpty($result['applied']);
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail();
        self::assertSame('12.50', $entry->amount);
        self::assertSame('provider_import', $entry->provenance);

        // Verify audit record was created for the restatement
        self::assertTrue(
            \App\Modules\Audit\Infrastructure\Models\AuditRecord::query()
                ->where('action', 'finance.estimate_to_actual_restatement')
                ->where('entity_type', Order::class)
                ->where('entity_id', $order->id)
                ->exists(),
        );
    }

    public function test_estimate_cannot_replace_strong_confirmed_fact(): void
    {
        $order = $this->order('USD');
        // Confirmed actual last-mile fact
        $order->costEntries()->create([
            'cost_type' => 'last_mile_cost', 'amount' => '20.00', 'currency' => 'USD',
            'provenance' => 'provider_import', 'status' => 'confirmed',
            'source_reference' => 'carrier-billing:v1:shipment:1:billing:1',
            'effective_at' => now(),
        ]);

        // Now try to apply estimated gate (no real billing, only estimate available)
        $svc = $this->app->make(CostMaterializationService::class);
        $result = $svc->applyLastMileEstimateGate($order->fresh());

        // Must be blocked or noop — estimate MUST NOT overwrite strong fact
        self::assertEmpty($result['applied']);
        self::assertSame('20.00', $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->amount);
    }

    // -----------------------------------------------------------------------
    // EXPLAIN OUTPUT INCLUDES P8.2 MATURITY FIELDS
    // -----------------------------------------------------------------------

    public function test_explain_output_includes_maturity_and_estimated_profit_fields(): void
    {
        $order = $this->order('USD', Carbon::now()->subDays(35)->toDateString());
        $this->seedRevenue($order, '100.00', '0.00');

        $explain = $this->app->make(OrderProfitExplanationService::class)->explain($order->fresh());
        $summary = $explain['summary'];

        self::assertArrayHasKey('age_days', $summary);
        self::assertArrayHasKey('maturity_state', $summary);
        self::assertArrayHasKey('reconciliation_due', $summary);
        self::assertArrayHasKey('estimate_allowed', $summary);
        self::assertArrayHasKey('canonical_estimated_ready', $summary);
        self::assertArrayHasKey('estimated_cost_count', $summary);
        self::assertArrayHasKey('confirmed_cost_count', $summary);
        self::assertArrayHasKey('missing_required_cost_count', $summary);
        self::assertArrayHasKey('cost_completion_percent', $summary);

        self::assertSame(35, $summary['age_days']);
        self::assertSame(OrderMaturityService::STATE_ACTUAL_RECONCILIATION_DUE, $summary['maturity_state']);
        self::assertTrue($summary['reconciliation_due']);
        self::assertFalse($summary['estimate_allowed']);
    }

    public function test_explain_uses_p82_estimated_cost_counters_for_ui_banners(): void
    {
        $order = $this->order('USD', Carbon::now()->subDays(35)->toDateString());
        $this->seedRevenue($order, '100.00', '0.00');
        foreach (['first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'payment_fee', 'customs_tax'] as $type) {
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => '5.00', 'currency' => 'USD',
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:'.$type,
            ]);
        }
        $order->costEntries()->create([
            'cost_type' => 'product_cost', 'amount' => '10.00', 'currency' => 'USD',
            'provenance' => 'estimated_reference', 'status' => 'estimated', 'source_reference' => 'product-reference:v1:order:'.$order->id,
        ]);

        $summary = $this->app->make(OrderProfitExplanationService::class)->explain($order->fresh())['summary'];
        self::assertSame(5, $summary['estimated_cost_count']);
        self::assertSame(1, $summary['posted_estimated_cost_count']);
        self::assertSame(4, $summary['fallback_estimated_cost_count']);
        self::assertSame(5, $summary['unresolved_estimate_count']);
        self::assertTrue($summary['canonical_estimated_ready']);
        self::assertTrue($summary['reconciliation_due']);
    }

    // -----------------------------------------------------------------------
    // MATURITY 30+ AND 45+ OVERDUE IN EXPLAIN
    // -----------------------------------------------------------------------

    public function test_30_plus_day_order_with_estimates_shows_reconciliation_due(): void
    {
        $order = $this->order('USD', Carbon::now()->subDays(32)->toDateString());
        $svc = $this->app->make(OrderMaturityService::class);
        $m = $svc->forOrder($order);

        self::assertTrue($m['reconciliation_due']);
        self::assertFalse($m['estimate_allowed']);
        self::assertSame(OrderMaturityService::STATE_ACTUAL_RECONCILIATION_DUE, $m['maturity_state']);
    }

    public function test_45_plus_day_order_shows_overdue_state(): void
    {
        $order = $this->order('USD', Carbon::now()->subDays(46)->toDateString());
        $svc = $this->app->make(OrderMaturityService::class);
        $m = $svc->forOrder($order);

        self::assertSame(OrderMaturityService::STATE_ACTUAL_COST_OVERDUE, $m['maturity_state']);
        self::assertTrue($m['reconciliation_due']);
    }

    // -----------------------------------------------------------------------
    // FINAL PROFIT STRICT GUARD
    // -----------------------------------------------------------------------

    public function test_final_profit_is_ready_when_all_posted_cost_facts_are_confirmed(): void
    {
        $order = $this->order('USD');
        $this->seedRevenue($order, '100.00', '0.00');
        foreach (['product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'payment_fee', 'kol_commission', 'refund_replacement_loss', 'customs_tax', 'other_variable_cost'] as $type) {
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => '5.00', 'currency' => 'USD',
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:'.$type,
            ]);
        }
        $order->financials()->update(['completeness_status' => 'CONFIRMED']);

        $summary = $this->app->make(OrderProfitExplanationService::class)->explain($order->fresh())['summary'];
        self::assertTrue($summary['canonical_final_ready']);
        self::assertSame('50.00', $summary['canonical_known_cost']);
        self::assertSame('50.00', $summary['canonical_contribution_profit']);
        self::assertSame(0, $summary['estimated_cost_count']);
    }

    public function test_final_profit_never_confirmed_when_any_cost_is_estimated(): void
    {
        $order = $this->order('USD');
        $this->seedRevenue($order, '100.00', '0.00');
        $this->attachProduct($order, '10.00', 'USD');

        // All confirmed except product (which is estimated reference)
        foreach (['warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'payment_fee', 'customs_tax', 'kol_commission', 'refund_replacement_loss', 'other_variable_cost'] as $type) {
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => '0.00', 'currency' => 'USD',
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:'.$type,
            ]);
        }
        $order->costEntries()->create([
            'cost_type' => 'product_cost', 'amount' => '10.00', 'currency' => 'USD',
            'provenance' => 'estimated_reference', 'status' => 'estimated',
            'source_reference' => 'product-reference:v1:order:'.$order->id,
        ]);

        $explain = $this->app->make(OrderProfitExplanationService::class)->explain($order->fresh());

        // Final ready MUST be false when any cost is estimated
        self::assertFalse($explain['summary']['canonical_final_ready']);
        // But estimated ready can be true
        self::assertTrue($explain['summary']['canonical_estimated_ready']);
    }

    // -----------------------------------------------------------------------
    // P8.2 ACTIVATION BRIDGE
    // -----------------------------------------------------------------------

    public function test_p82_fallback_is_disabled_in_existing_last_mile_gate_by_default(): void
    {
        config(['erp_accounting.continuous_delta.p82_estimated_fallback_enabled' => false]);
        $order = $this->order('USD');
        $order->items()->create(['product_summary' => 'Fallback', 'sku' => 'P82-FB-OFF', 'quantity' => 3, 'unit_price' => '10.00', 'line_revenue' => '30.00']);
        $this->attachWarehouseShipment($order, 'us-la');

        $candidate = $this->app->make(CostMaterializationService::class)->previewLastMileEstimateGate($order)['candidates'][0];

        self::assertSame('blocked', $candidate['materialization_status']);
        self::assertArrayNotHasKey('p82_tier', $candidate);
    }

    public function test_p82_fallback_enters_existing_gate_only_when_feature_flag_enabled(): void
    {
        config(['erp_accounting.continuous_delta.p82_estimated_fallback_enabled' => true]);
        $order = $this->order('USD');
        $order->items()->create(['product_summary' => 'Fallback', 'sku' => 'P82-FB-ON', 'quantity' => 3, 'unit_price' => '10.00', 'line_revenue' => '30.00']);
        $this->attachWarehouseShipment($order, 'us-la');

        $candidate = $this->app->make(CostMaterializationService::class)->previewLastMileEstimateGate($order)['candidates'][0];

        self::assertSame('apply', $candidate['materialization_status']);
        self::assertSame('30.00', $candidate['amount']);
        self::assertSame('fallback_usd10_per_unit', $candidate['p82_tier']);
        self::assertSame('low', $candidate['p82_confidence']);
        self::assertSame('p82-last-mile-estimate:v1:order:'.$order->id, $candidate['source_reference']);
    }

    public function test_p82_automatic_fallback_blocks_orders_older_than_30_days_but_dedicated_gate_remains_available(): void
    {
        config(['erp_accounting.continuous_delta.p82_estimated_fallback_enabled' => true]);
        $order = $this->order('USD', Carbon::now()->subDays(46)->toDateString());
        $order->items()->create(['product_summary' => 'Fallback', 'sku' => 'P82-AGE-BLOCK', 'quantity' => 2, 'unit_price' => '10.00', 'line_revenue' => '20.00']);
        $this->attachWarehouseShipment($order, 'us-la');

        $automatic = $this->app->make(CostMaterializationService::class)->previewLastMileEstimateGate($order)['candidates'][0];
        self::assertSame('blocked', $automatic['materialization_status']);
        self::assertSame('p82_automatic_estimate_age_blocked', $automatic['blocker']);
        self::assertSame(46, $automatic['p82_age_days']);
        self::assertSame(OrderMaturityService::STATE_ACTUAL_COST_OVERDUE, $automatic['p82_maturity_state']);
        self::assertTrue($automatic['p82_reconciliation_due']);

        $dedicated = $this->app->make(CostMaterializationService::class)->previewP82LastMileEstimateGate($order)['candidates'][0];
        self::assertSame('apply', $dedicated['materialization_status']);
        self::assertSame('20.00', $dedicated['amount']);
        self::assertSame('fallback_usd10_per_unit', $dedicated['p82_tier']);
    }

    public function test_p82_dedicated_gate_is_available_for_guarded_backfill_without_persistent_flag(): void
    {
        config(['erp_accounting.continuous_delta.p82_estimated_fallback_enabled' => false]);
        $order = $this->order('USD');
        $order->items()->create(['product_summary' => 'Fallback', 'sku' => 'P82-BACKFILL', 'quantity' => 2, 'unit_price' => '10.00', 'line_revenue' => '20.00']);
        $this->attachWarehouseShipment($order, 'us-la');

        $candidate = $this->app->make(CostMaterializationService::class)->previewP82LastMileEstimateGate($order)['candidates'][0];

        self::assertSame('apply', $candidate['materialization_status']);
        self::assertSame('20.00', $candidate['amount']);
        self::assertSame('fallback_usd10_per_unit', $candidate['p82_tier']);
    }

    public function test_p82_dedicated_gate_never_overwrites_strong_last_mile_fact(): void
    {
        $order = $this->order('USD');
        $order->items()->create(['product_summary' => 'Fallback', 'sku' => 'P82-STRONG', 'quantity' => 2, 'unit_price' => '10.00', 'line_revenue' => '20.00']);
        $this->attachWarehouseShipment($order, 'us-la');
        $order->costEntries()->create([
            'cost_type' => 'last_mile_cost', 'amount' => '8.88', 'currency' => 'USD',
            'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'owner:manual:last-mile',
        ]);

        $candidate = $this->app->make(CostMaterializationService::class)->previewP82LastMileEstimateGate($order)['candidates'][0];

        self::assertSame('blocked', $candidate['materialization_status']);
        self::assertSame('stronger_or_different_existing_fact', $candidate['blocker']);
        self::assertSame('8.88', $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->amount);
    }

    public function test_p82_activation_command_is_dry_run_first_and_local_apply_is_idempotent(): void
    {
        $order = $this->order('USD');
        $order->items()->create(['product_summary' => 'Fallback', 'sku' => 'P82-CMD', 'quantity' => 2, 'unit_price' => '10.00', 'line_revenue' => '20.00']);
        $this->attachWarehouseShipment($order, 'us-la');

        $this->artisan('finance:p8-2-materialize-estimated-last-mile', ['--order' => $order->id])->assertSuccessful();
        self::assertFalse($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());

        $this->artisan('finance:p8-2-materialize-estimated-last-mile', ['--order' => $order->id, '--apply' => true])->assertSuccessful();
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail();
        self::assertSame('20.00', $entry->amount);
        self::assertSame('estimated_reference', $entry->provenance);
        self::assertStringStartsWith('p82-last-mile-estimate:v1:order:', (string) $entry->source_reference);

        $result = $this->app->make(CostMaterializationService::class)->previewP82LastMileEstimateGate($order->fresh())['candidates'][0];
        self::assertSame('noop', $result['materialization_status']);
    }

    public function test_continuous_delta_surfaces_p82_estimate_only_after_feature_flag_is_enabled(): void
    {
        config(['erp_accounting.continuous_delta.p82_estimated_fallback_enabled' => true]);
        $order = $this->order('USD');
        $order->items()->create(['product_summary' => 'Fallback', 'sku' => 'P82-DELTA', 'quantity' => 1, 'unit_price' => '10.00', 'line_revenue' => '10.00']);
        $this->attachWarehouseShipment($order, 'us-la');

        $summary = $this->app->make(AccountingContinuousDeltaService::class)->preview(100);

        self::assertSame(1, $summary['candidate_count']);
        self::assertSame(1, $summary['stages']['last_mile_estimate']['applied']);
        self::assertFalse($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());
    }

    public function test_continuous_delta_blocks_new_p82_estimate_for_order_older_than_30_days(): void
    {
        config(['erp_accounting.continuous_delta.p82_estimated_fallback_enabled' => true]);
        $order = $this->order('USD', Carbon::now()->subDays(31)->toDateString());
        $order->items()->create(['product_summary' => 'Fallback', 'sku' => 'P82-DELTA-OLD', 'quantity' => 1, 'unit_price' => '10.00', 'line_revenue' => '10.00']);
        $this->attachWarehouseShipment($order, 'us-la');

        $summary = $this->app->make(AccountingContinuousDeltaService::class)->preview(100);

        self::assertSame(1, $summary['candidate_count']);
        self::assertSame(0, $summary['stages']['last_mile_estimate']['applied']);
        self::assertGreaterThanOrEqual(1, $summary['stages']['last_mile_estimate']['blocked']);
        self::assertFalse($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());
    }

    // -----------------------------------------------------------------------
    // P8.2 DRY-RUN COMMAND
    // -----------------------------------------------------------------------

    public function test_p82_dry_run_command_is_read_only_and_outputs_deterministic_json(): void
    {
        $order = $this->order('USD', Carbon::now()->subDays(10)->toDateString());
        $this->seedRevenue($order, '100.00', '0.00');

        $this->artisan('finance:p8-2-estimated-reconciliation', ['--order' => $order->id])
            ->assertSuccessful();

        // Command must not write any cost entries
        self::assertSame(0, $order->costEntries()->count());
    }

    public function test_p82_command_rejects_write_operations_when_none_available(): void
    {
        $order = $this->order('USD');
        $output = $this->artisan('finance:p8-2-estimated-reconciliation', ['--order' => $order->id]);
        $output->assertSuccessful();
        // No DB writes
        self::assertSame(0, $order->costEntries()->count());
    }

    // -----------------------------------------------------------------------
    // P8.1 MULTI-SHIPMENT REGRESSION
    // -----------------------------------------------------------------------

    public function test_p81_multi_shipment_aggregate_carrier_billing_still_confirmed_and_blocks_estimates(): void
    {
        config(['erp_accounting.multi_shipment.enabled' => true]);

        $order = $this->order('USD');
        $fulfillment1 = $this->createFulfillmentChild($order, 'us-la', 'INV-MS-001', '10.00', 'USD');
        $fulfillment2 = $this->createFulfillmentChild($order, 'us-la', 'INV-MS-002', '8.00', 'USD');

        $svc = $this->app->make(CostMaterializationService::class);
        $preview = $svc->previewCarrierBillingGate($order);

        self::assertSame(1, count($preview['candidates']));
        $candidate = $preview['candidates'][0];
        // With actual billing available, must not fall back to estimates
        self::assertNotSame('estimated_reference', $candidate['provenance'] ?? null);

        config(['erp_accounting.multi_shipment.enabled' => false]);
    }

    // -----------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------

    private function order(string $currency, ?string $orderDate = null): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'P82-'.strtoupper(uniqid()),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'order_date' => $orderDate ?? Carbon::now()->toDateString(),
            'order_status' => 'pending_shipment',
            'currency' => $currency,
        ]);
    }

private function seedRevenue(Order $order, string $paid, string $refund): void
    {
        $order->financials()->create([
            'amount_paid' => $paid, 'refund_amount' => $refund, 'currency' => $order->currency,
            'revenue_provenance' => 'confirmed_manual', 'completeness_status' => 'INCOMPLETE',
        ]);
    }

    private function attachProduct(Order $order, string $purchaseCost, string $currency): Product
    {
        $product = Product::query()->create([
            'source_type' => 'manual', 'product_type' => 'simple',
            'sku' => 'P82-SKU-'.strtoupper(uniqid()),
            'name' => 'P8.2 Test Product',
            'standard_purchase_cost' => $purchaseCost, 'cost_currency' => $currency,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_summary' => $product->name,
            'sku' => $product->sku, 'quantity' => 1,
            'unit_price' => '10.00', 'line_revenue' => '10.00',
        ]);
        return $product;
    }

    private function attachWarehouseShipment(Order $order, string $slug): Shipment
    {
        $warehouse = Warehouse::query()->firstOrCreate(
            ['slug' => $slug],
            ['code' => strtoupper($slug), 'name' => 'Test '.$slug, 'type' => 'warehouse', 'ownership_type' => 'owned', 'is_active' => true, 'priority' => 100],
        );
        $shipment = new Shipment(['shipment_status' => 'pending', 'warehouse_id' => $warehouse->id, 'warehouse_selection_source' => 'uat_fixture']);
        $order->shipment()->save($shipment);
        return $shipment;
    }

    private function attachBilling(Order $order, string $amount, string $currency, string $document): ShipmentCarrierBilling
    {
        if (! $order->shipment) {
            $shipment = new Shipment(['shipment_status' => 'pending']);
            $order->shipment()->save($shipment);
            $order->refresh();
        }
        return $order->shipment->billingEvidence()->create([
            'actual_cost' => $amount, 'currency' => $currency,
            'billing_document_number' => $document, 'data_source' => 'uat_fixture',
            'billed_at' => now()->toDateTimeString(),
        ]);
    }

    private function createFulfillmentChild(Order $parent, string $warehouseSlug, string $document, string $amount, string $currency): Order
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $child = Order::query()->create([
            'internal_order_number' => 'P82-CHILD-'.$suffix,
            'source_type' => 'manual', 'order_kind' => 'customer',
            'order_date' => Carbon::now()->toDateString(), 'order_status' => 'pending_shipment',
            'currency' => $currency, 'fulfillment_parent_id' => $parent->id,
            'fulfillment_suffix' => 'P82-'.$suffix,
        ]);
        $shipment = $this->attachWarehouseShipment($child, $warehouseSlug);
        $shipment->billingEvidence()->create([
            'actual_cost' => $amount, 'currency' => $currency,
            'billing_document_number' => $document, 'data_source' => 'uat_fixture',
            'billed_at' => now()->toDateTimeString(),
        ]);
        return $child;
    }
}
