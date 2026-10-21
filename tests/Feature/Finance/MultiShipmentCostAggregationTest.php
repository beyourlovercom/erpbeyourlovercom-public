<?php

namespace Tests\Feature\Finance;

use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Finance\Application\OrderProfitExplanationService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MultiShipmentCostAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_overseas_shipments_aggregate_to_parent_and_replay_noops(): void
    {
        $parent = $this->parent('USD');
        $a = $this->leg($parent, 'A', 'us-la', 2, 'LA-1');
        $b = $this->leg($parent, 'B', 'us-ny', 1, 'NY-1');
        $this->bill($a, '8.40', 'USD', 'USPS-840');
        $this->bill($b, '6.20', 'USD', 'DHL-620');

        $service = app(CostMaterializationService::class);
        $preview = $service->previewMultiShipmentGate($parent);
        $this->assertSame(2, $preview['shipment_count']);
        $this->assertCandidate($preview, 'first_mile_cost', '3.00', 'apply');
        $this->assertCandidate($preview, 'overseas_handling_cost', '3.00', 'apply');
        $this->assertCandidate($preview, 'last_mile_cost', '14.60', 'apply');

        $result = $service->applyMultiShipmentGate($parent);
        $this->assertCount(3, $result['applied']);
        $this->assertSame('3.00', $parent->costEntries()->where('cost_type', 'first_mile_cost')->value('amount'));
        $this->assertSame('3.00', $parent->costEntries()->where('cost_type', 'overseas_handling_cost')->value('amount'));
        $this->assertSame('14.60', $parent->costEntries()->where('cost_type', 'last_mile_cost')->value('amount'));
        $this->assertCount(3, $service->applyMultiShipmentGate($parent->fresh())['noop']);
        $this->assertSame(3, $parent->costEntries()->whereIn('cost_type', ['first_mile_cost','overseas_handling_cost','last_mile_cost'])->count());
    }

    public function test_replay_keeps_identity_fx_posting_snapshot_frozen_across_days(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');
        try {
            $this->manualFx();
            $parent = $this->parent('EUR', true);
            $leg = $this->leg($parent, 'A', 'us-la', 1, 'IDENTITY-FX');
            $this->bill($leg, '11.00', 'USD', 'IDENTITY-FX-BILL');

            $service = app(CostMaterializationService::class);
            $applied = $service->applyMultiShipmentGate($parent);
            $this->assertCount(3, $applied['applied']);

            Carbon::setTestNow('2026-09-18 12:00:00');
            $preview = $service->previewMultiShipmentGate($parent->fresh());
            foreach (['first_mile_cost', 'overseas_handling_cost', 'last_mile_cost'] as $type) {
                $candidate = $this->candidate($preview, $type);
                $this->assertSame('noop', $candidate['materialization_status'], $type);
                $this->assertSame('0.00', $candidate['delta_amount'], $type);
                $this->assertTrue($candidate['posting_snapshot_frozen'] ?? false, $type);
            }
            $this->assertCount(3, $service->applyMultiShipmentGate($parent->fresh())['noop']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_replay_does_not_revalue_same_cross_currency_carrier_bill_but_changed_bill_reapplies(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');
        try {
            $this->manualFx();
            $parent = $this->parent('USD', true);
            $leg = $this->leg($parent, 'A', 'us-la', 1, 'SOURCE-FX');
            $this->bill($leg, '11.84', 'EUR', 'SOURCE-FX-BILL');

            $service = app(CostMaterializationService::class);
            $service->applyMultiShipmentGate($parent);
            $entry = $parent->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail();
            $frozenAmount = (string) $entry->amount;
            $frozenSource = (string) $entry->source_reference;

            app(FxReferenceSettingsService::class)->save(
                ['USD' => 7.5, 'EUR' => 8.7, 'CNY' => 1.0],
                ['USD' => false, 'EUR' => false, 'CNY' => false], null,
            );
            Carbon::setTestNow('2026-09-18 12:00:00');

            $sameEvidence = $this->candidate($service->previewMultiShipmentGate($parent->fresh()), 'last_mile_cost');
            $this->assertSame($frozenSource, $sameEvidence['source_reference']);
            $this->assertSame('noop', $sameEvidence['materialization_status']);
            $this->assertSame($frozenAmount, $sameEvidence['amount']);
            $this->assertSame('0.00', $sameEvidence['delta_amount']);
            $this->assertTrue($sameEvidence['posting_snapshot_frozen'] ?? false);

            $leg->billingEvidence()->update(['actual_cost' => '12.00']);
            $changedEvidence = $this->candidate($service->previewMultiShipmentGate($parent->fresh()), 'last_mile_cost');
            $this->assertNotSame($frozenSource, $changedEvidence['source_reference']);
            $this->assertSame('apply', $changedEvidence['materialization_status']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_overseas_plus_china_uses_hybrid_formula_and_missing_bill_fails_closed(): void
    {
        $parent = $this->parent('USD');
        $overseas = $this->leg($parent, 'A', 'us-la', 2, 'LA-HYBRID');
        $china = $this->leg($parent, 'B', 'factory', 1, 'CN-HYBRID');
        $this->bill($overseas, '8.40', 'USD', 'USPS-HYBRID');

        $preview = app(CostMaterializationService::class)->previewMultiShipmentGate($parent);
        $this->assertCandidate($preview, 'first_mile_cost', '2.00', 'apply');
        $this->assertCandidate($preview, 'overseas_handling_cost', '2.00', 'apply');
        $last = $this->candidate($preview, 'last_mile_cost');
        $this->assertSame('blocked', $last['materialization_status']);
        $this->assertSame('carrier_billing_missing_on_shipment', $last['blocker']);
        $this->assertSame($china->id, $last['shipment_id']);

        $this->bill($china, '12.50', 'USD', 'CN-PARCEL-1250');
        $fixed = app(CostMaterializationService::class)->previewMultiShipmentGate($parent->fresh());
        $this->assertCandidate($fixed, 'last_mile_cost', '20.90', 'apply');
    }

    public function test_three_shipment_finance_model_supports_n_legs_even_when_execution_policy_is_narrower(): void
    {
        $parent = $this->parent('USD');
        $a = $this->leg($parent, 'A', 'us-la', 2, 'LA-3');
        $b = $this->leg($parent, 'B', 'us-ny', 1, 'NY-3');
        $c = $this->leg($parent, 'C', 'factory', 1, 'CN-3');
        $this->bill($a, '8.40', 'USD', 'A-3');
        $this->bill($b, '6.20', 'USD', 'B-3');
        $this->bill($c, '12.50', 'USD', 'C-3');

        $preview = app(CostMaterializationService::class)->previewMultiShipmentGate($parent);
        $this->assertSame(3, $preview['shipment_count']);
        $this->assertCandidate($preview, 'first_mile_cost', '3.00', 'apply');
        $this->assertCandidate($preview, 'overseas_handling_cost', '3.00', 'apply');
        $last = $this->candidate($preview, 'last_mile_cost');
        $this->assertSame('27.10', $last['amount']);
        $this->assertCount(3, $last['shipment_breakdown']);
        $this->assertSame([2,1,1], array_column($last['shipment_breakdown'], 'shipped_units'));
    }

    public function test_mixed_currency_carrier_bills_convert_per_leg_before_parent_sum(): void
    {
        $this->manualFx();
        $parent = $this->parent('EUR', true);
        $a = $this->leg($parent, 'A', 'us-la', 1, 'LA-FX');
        $b = $this->leg($parent, 'B', 'factory', 1, 'CN-FX');
        $this->bill($a, '11.00', 'USD', 'USD-11');
        $this->bill($b, '80.00', 'CNY', 'CNY-80');

        $last = $this->candidate(app(CostMaterializationService::class)->previewMultiShipmentGate($parent), 'last_mile_cost');
        $this->assertSame('apply', $last['materialization_status']);
        $this->assertSame('20.31', $last['amount']);
        $this->assertSame('EUR', $last['currency']);
        $this->assertCount(2, $last['shipment_breakdown']);
        $this->assertSame('10.31', $last['shipment_breakdown'][0]['posted_amount']);
        $this->assertSame('10.00', $last['shipment_breakdown'][1]['posted_amount']);
    }

    public function test_strong_manual_fact_is_review_not_silently_overwritten(): void
    {
        $parent = $this->parent('USD');
        $a = $this->leg($parent, 'A', 'us-la', 2, 'STRONG');
        $this->bill($a, '8.40', 'USD', 'STRONG-BILL');
        $parent->costEntries()->create([
            'cost_type' => 'overseas_handling_cost', 'amount' => '9.99', 'currency' => 'USD',
            'provenance' => 'provider_import', 'status' => 'confirmed', 'source_reference' => 'provider:handling:strong',
        ]);

        $preview = app(CostMaterializationService::class)->previewMultiShipmentGate($parent);
        $handling = $this->candidate($preview, 'overseas_handling_cost');
        $this->assertSame('blocked', $handling['materialization_status']);
        $this->assertSame('stronger_or_different_existing_fact', $handling['blocker']);
        $this->assertSame('9.99', $parent->costEntries()->where('cost_type','overseas_handling_cost')->value('amount'));
    }

    public function test_dedicated_command_is_dry_run_first_and_exact_plan_guarded(): void
    {
        $parent = $this->parent('USD');
        $a = $this->leg($parent, 'A', 'us-la', 1, 'CMD');
        $this->bill($a, '5.00', 'USD', 'CMD-BILL');
        $exit = \Illuminate\Support\Facades\Artisan::call('finance:materialize-multi-shipment-costs', ['--order' => $parent->id]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('"orders_seen":1', $output, $output);
        $this->assertStringContainsString('"shipments_seen":1', $output, $output);
        $this->assertStringContainsString('"apply":3', $output, $output);
        $this->assertSame(0, $parent->costEntries()->count());
    }

    public function test_formula_explanation_marks_shipments_and_parent_logistics_incomplete_when_last_mile_is_missing(): void
    {
        $parent = $this->parent('USD');
        $this->leg($parent, 'A', 'factory', 1, 'STATUS-CN');
        $this->leg($parent, 'B', 'us-la', 2, 'STATUS-US');

        $explanation = app(OrderProfitExplanationService::class)->explain($parent->fresh());

        $this->assertSame('incomplete', $explanation['logistics']['state']);
        $this->assertSame(0, $explanation['logistics']['confirmed_shipment_count']);
        $this->assertSame(2, $explanation['logistics']['shipment_count']);
        foreach ($explanation['shipments'] as $shipment) {
            $this->assertSame('incomplete', $shipment['state']);
            $this->assertContains('Last Mile', $shipment['missing_components']);
            $this->assertNull($shipment['shipment_total']);
        }
    }

    public function test_formula_explanation_reuses_canonical_product_and_shipment_evidence(): void
    {
        $parent = $this->parent('USD', true);
        $product = Product::query()->create([
            'source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'P81-PARENT',
            'name' => 'Parent Product', 'cost_currency' => 'USD',
        ]);
        $parent->items()->create([
            'product_id' => $product->id, 'product_summary' => 'Parent Product', 'sku' => 'P81-PARENT',
            'quantity' => 3, 'unit_price' => '40.00', 'line_revenue' => '120.00',
        ]);
        ProductCostSource::query()->create([
            'product_id' => $product->id, 'source_key' => 'p81-fixture', 'unit_cost' => '10.00',
            'currency' => 'USD', 'provenance' => 'confirmed_source_file', 'status' => 'confirmed',
            'source_type' => 'inventory_cost_book', 'source_reference' => 'p81-cost.xlsx',
            'effective_from' => '2026-09-01', 'source_at' => '2026-09-17 00:00:00',
            'confirmed_at' => '2026-09-17 00:00:00', 'source_fingerprint' => hash('sha256', 'p81-fixture'),
        ]);
        $a = $this->leg($parent, 'A', 'us-la', 2, 'EXPLAIN-A');
        $b = $this->leg($parent, 'B', 'factory', 1, 'EXPLAIN-B');
        $this->bill($a, '8.40', 'USD', 'EXPLAIN-A-BILL');
        $this->bill($b, '12.50', 'USD', 'EXPLAIN-B-BILL');

        $explanation = app(OrderProfitExplanationService::class)->explain($parent->fresh());
        $this->assertSame('30.00', $explanation['product']['amount']);
        $this->assertFalse($explanation['summary']['canonical_final_ready']);
        $this->assertNull($explanation['summary']['canonical_contribution_profit']);
        $this->assertSame('P81-PARENT', $explanation['product']['lines'][0]['sku']);
        $this->assertSame(2, $explanation['order']['shipment_count']);
        $this->assertCount(2, $explanation['shipments']);
        $this->assertSame('2.00', $explanation['shipments'][0]['first_mile']['amount']);
        $this->assertSame('12.40', $explanation['shipments'][0]['shipment_total']);
        $this->assertSame('12.50', $explanation['shipments'][1]['shipment_total']);
        $lastMile = collect($explanation['formula'])->firstWhere('cost_type', 'last_mile_cost');
        $this->assertSame('20.90', $lastMile['amount']);
        $this->assertSame('Σ actual carrier / international parcel billing per Shipment', $lastMile['formula']);
    }

    public function test_order_profit_formula_route_renders_canonical_explanation_page(): void
    {
        $role = Role::create(['key' => 'p81-analysis-'.uniqid(), 'label' => 'P8.1 Analysis']);
        $role->permissions()->create(['key' => 'analysis.view', 'label' => 'analysis.view']);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        $parent = $this->parent('USD');
        $leg = $this->leg($parent, 'A', 'us-la', 1, 'ROUTE');
        $this->bill($leg, '5.00', 'USD', 'ROUTE-BILL');

        $this->actingAs($user)->get('/accounting/order-costs/'.$parent->id)->assertInertia(fn (Assert $page) => $page
            ->component('Finance/OrderProfitShow')
            ->where('explanation.order.id', $parent->id)
            ->where('explanation.order.shipment_count', 1)
            ->where('explanation.shipments.0.first_mile.amount', '1.00')
            ->where('explanation.shipments.0.last_mile.amount', '5.00'));
    }

    private function parent(string $currency, bool $withFinancials = false): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'P81-'.strtoupper(uniqid()), 'source_type' => 'manual',
            'order_kind' => 'customer', 'order_date' => '2026-09-17',
            'order_status' => 'shipped', 'currency' => $currency,
        ]);
        if ($withFinancials) $order->financials()->create(['currency' => $currency]);
        return $order;
    }

    private function leg(Order $parent, string $suffix, string $warehouseSlug, int $quantity, string $tracking): Shipment
    {
        $warehouse = Warehouse::query()->firstOrCreate(
            ['slug' => $warehouseSlug],
            ['code' => strtoupper($warehouseSlug), 'name' => 'P8.1 '.$warehouseSlug, 'country_code' => str_starts_with($warehouseSlug, 'us-') ? 'US' : 'CN',
                'type' => 'warehouse', 'ownership_type' => 'owned', 'is_active' => true, 'priority' => 100],
        );
        $child = Order::query()->create([
            'customer_id' => $parent->customer_id, 'fulfillment_parent_id' => $parent->id, 'fulfillment_suffix' => $suffix,
            'internal_order_number' => 'P81-LEG-'.$parent->id.'-'.$suffix, 'source_type' => 'manual',
            'order_kind' => 'fulfillment_derived', 'order_date' => $parent->order_date,
            'order_status' => 'shipped', 'currency' => $parent->currency,
        ]);
        $child->items()->create([
            'product_summary' => 'P8.1 Item '.$suffix, 'sku' => 'P81-'.$suffix,
            'quantity' => $quantity, 'unit_price' => '10.00', 'line_revenue' => number_format(10 * $quantity, 2, '.', ''),
        ]);
        return $child->shipment()->create([
            'warehouse_id' => $warehouse->id, 'warehouse_selection_source' => 'p8_1_fixture',
            'shipment_status' => 'shipped', 'tracking_number' => $tracking,
            'carrier' => str_starts_with($warehouseSlug, 'us-') ? 'USPS' : '4PX',
        ]);
    }

    private function bill(Shipment $shipment, string $amount, string $currency, string $document): void
    {
        $shipment->billingEvidence()->create([
            'actual_cost' => $amount, 'currency' => $currency,
            'billing_document_number' => $document, 'data_source' => 'p8_1_fixture',
            'billed_at' => '2026-09-17 00:00:00',
        ]);
    }

    private function manualFx(): void
    {
        app(FxReferenceSettingsService::class)->save(
            ['USD' => 7.5, 'EUR' => 8.0, 'CNY' => 1.0],
            ['USD' => false, 'EUR' => false, 'CNY' => false], null,
        );
    }

    private function candidate(array $payload, string $type): array
    {
        foreach ($payload['candidates'] as $candidate) if (($candidate['cost_type'] ?? null) === $type) return $candidate;
        self::fail('Missing candidate '.$type);
    }

    private function assertCandidate(array $payload, string $type, string $amount, string $status): void
    {
        $candidate = $this->candidate($payload, $type);
        $this->assertSame($amount, $candidate['amount']);
        $this->assertSame($status, $candidate['materialization_status']);
    }
}
