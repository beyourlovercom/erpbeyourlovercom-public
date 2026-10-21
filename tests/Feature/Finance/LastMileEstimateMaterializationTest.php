<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Contracts\LastMileEstimateEvidenceReader;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LastMileEstimateMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_estimate_is_routing_only_and_marks_finance_estimated(): void
    {
        $this->manualFx();
        $order = $this->order('USD', 'US');
        $product = $this->product($order, '0.5000', packagingCost: '99.00');
        $this->shippingWeight($product, '0.5000');
        $this->shipment($order, 'FACTORY');
        $this->seedOtherConfirmedFacts($order);

        $preview = app(CostMaterializationService::class)->previewLastMileEstimateGate($order);
        $candidate = $preview['candidates'][0];
        $this->assertSame('apply', $candidate['materialization_status']);
        $this->assertSame('estimated_reference', $candidate['provenance']);
        $this->assertSame('estimated', $candidate['status']);
        $this->assertSame('11.53', $candidate['amount']);
        $this->assertSame('86.50', $candidate['source_amount']);

        app(CostMaterializationService::class)->applyLastMileEstimateGate($order);
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail();
        $this->assertSame('11.53', $entry->amount);
        $this->assertSame('estimated_reference', $entry->provenance);
        $this->assertSame('ESTIMATED', $order->financials()->firstOrFail()->fresh()->completeness_status);
        $this->assertStringStartsWith('last-mile-estimate:v1:order:', (string) $entry->source_reference);
    }

    public function test_de_estimate_uses_approved_packaging_measurement_and_excludes_packaging_cost(): void
    {
        $this->manualFx();
        $order = $this->order('CNY', 'FR');
        $product = $this->product($order, null, packagingCost: '88.00');
        $this->approvedPackaging($product, '0.454');
        $this->shipment($order, 'DE');

        $evidence = app(LastMileEstimateEvidenceReader::class)->read($order->id);
        $this->assertSame('76.4400', $evidence['amount']);
        $this->assertSame('CNY', $evidence['currency']);
        $components = collect($evidence['estimate']['components']);
        $this->assertFalse($components->contains(fn (array $row): bool => str_contains((string) ($row['key'] ?? ''), 'packaging')));
    }

    public function test_estimate_is_idempotent_refreshable_and_never_overwrites_strong_fact(): void
    {
        $this->manualFx();
        $order = $this->order('USD', 'US');
        $product = $this->product($order, '0.5000');
        $this->shippingWeight($product, '0.5000');
        $this->shipment($order, 'FACTORY');
        $service = app(CostMaterializationService::class);

        $service->applyLastMileEstimateGate($order);
        $second = $service->applyLastMileEstimateGate($order->fresh());
        $this->assertCount(1, $second['noop']);

        $this->shippingWeight($product, '0.6000');
        $changed = $service->previewLastMileEstimateGate($order->fresh());
        $this->assertSame('apply', $changed['candidates'][0]['materialization_status']);
        $service->applyLastMileEstimateGate($order->fresh());
        $this->assertNotSame('11.53', $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->amount);

        $order->costEntries()->where('cost_type', 'last_mile_cost')->update([
            'amount' => '3.00', 'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'owner:manual',
        ]);
        $blocked = $service->previewLastMileEstimateGate($order->fresh())['candidates'][0];
        $this->assertSame('blocked', $blocked['materialization_status']);
        $this->assertSame('stronger_or_different_existing_fact', $blocked['blocker']);
    }

    public function test_complete_actual_carrier_billing_blocks_estimate_before_materialization(): void
    {
        $this->manualFx();
        $order = $this->order('USD', 'US');
        $product = $this->product($order, '0.5000');
        $this->shippingWeight($product, '0.5000');
        $shipment = $this->shipment($order, 'FACTORY');
        $shipment->billingEvidence()->create([
            'actual_cost' => '12.3400', 'currency' => 'USD', 'billing_document_number' => 'BILL-P6A-1',
            'data_source' => 'carrier_bill', 'billed_at' => now(),
        ]);

        $candidate = app(CostMaterializationService::class)->previewLastMileEstimateGate($order)['candidates'][0];
        $this->assertSame('blocked', $candidate['materialization_status']);
        $this->assertSame('actual_carrier_billing_available', $candidate['blocker']);
        $this->assertFalse($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());
    }

    public function test_split_selected_fulfillment_aggregates_supported_segments_and_blocks_unsupported_segment(): void
    {
        $this->manualFx();
        $order = $this->order('CNY', 'FR');
        $factory = $this->product($order, '0.5000');
        $this->shippingWeight($factory, '0.5000');
        $de = $this->product($order, null);
        $this->approvedPackaging($de, '0.454');

        $planKey = 'manual:p6a-split';
        $allocation = FulfillmentAllocation::query()->create([
            'order_id' => $order->id, 'status' => 'submitted', 'decision_source' => 'manual',
            'selected_plan_key' => $planKey, 'submitted_at' => now(),
            'plan_snapshot' => [
                'plan_key' => $planKey,
                'segments' => [
                    ['source_type' => 'factory', 'source_code' => 'FACTORY', 'items' => [['product_id' => $factory->id, 'quantity' => 1]]],
                    ['source_type' => 'warehouse', 'source_code' => 'DE', 'items' => [['product_id' => $de->id, 'quantity' => 1]]],
                ],
            ],
        ]);

        $evidence = app(LastMileEstimateEvidenceReader::class)->read($order->id);
        $this->assertSame('186.9400', $evidence['amount']);
        $this->assertCount(2, $evidence['estimate']['segments']);

        $plan = $allocation->plan_snapshot;
        $plan['segments'][1]['source_code'] = 'US-NY';
        $allocation->forceFill(['plan_snapshot' => $plan])->save();
        $blocked = app(LastMileEstimateEvidenceReader::class)->read($order->id);
        $this->assertSame('routing_rate_unavailable', $blocked['blocked']);
    }

    public function test_command_is_dry_run_by_default_and_production_apply_is_count_guarded(): void
    {
        $this->manualFx();
        $order = $this->order('EUR', 'US');
        $order->financials()->create(['currency' => 'EUR']);
        $product = $this->product($order, '0.5000');
        $this->shippingWeight($product, '0.5000');
        $this->shipment($order, 'FACTORY');

        $this->artisan('finance:materialize-last-mile-estimates', ['--order' => $order->id])->assertSuccessful();
        $this->assertFalse($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());
        $this->assertNull($order->financials()->firstOrFail()->fx_rate);

        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_ACCOUNTING_LAST_MILE_ESTIMATE_PRODUCTION_APPLY_ENABLED');
        try {
            $base = [
                '--order' => $order->id, '--apply' => true,
                '--expect-seen' => '1', '--expect-apply' => '1', '--expect-blocked' => '0', '--expect-fx-freeze' => '1',
            ];
            $this->artisan('finance:materialize-last-mile-estimates', $base)->assertFailed();
            $this->assertFalse($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());

            putenv('ERP_ACCOUNTING_LAST_MILE_ESTIMATE_PRODUCTION_APPLY_ENABLED=true');
            $wrong = $base;
            $wrong['--expect-apply'] = '2';
            $this->artisan('finance:materialize-last-mile-estimates', $wrong)->assertFailed();
            $this->assertFalse($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());

            $this->artisan('finance:materialize-last-mile-estimates', $base)->assertSuccessful();
            $this->assertTrue($order->costEntries()->where('cost_type', 'last_mile_cost')->exists());
            $this->assertNotNull($order->financials()->firstOrFail()->fresh()->fx_rate);
        } finally {
            putenv('ERP_ACCOUNTING_LAST_MILE_ESTIMATE_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $previousEnv;
        }
    }

    private function manualFx(): void
    {
        app(FxReferenceSettingsService::class)->save(
            ['USD' => 7.5, 'EUR' => 8.0],
            ['USD' => false, 'EUR' => false],
            null,
        );
    }

    private function order(string $currency, string $country, string $kind = 'customer'): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'P6A-'.strtoupper(uniqid()),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'order_date' => '2026-09-11',
            'order_status' => 'pending_shipment',
            'currency' => $currency,
        ]);
        $order->address()->create([
            'recipient_name' => 'P6A Test', 'country' => $country,
            'province_state' => 'CA', 'city' => 'Test City', 'postal_code' => '94105',
            'address_line_1' => '1 Test Street',
        ]);
        return $order;
    }

    private function product(Order $order, ?string $shippingWeight, string $packagingCost = '0.00'): Product
    {
        $product = Product::query()->create([
            'source_type' => 'manual', 'product_type' => 'simple',
            'sku' => 'P6A-SKU-'.strtoupper(uniqid()), 'name' => 'P6A Product',
            'shipping_weight_kg' => $shippingWeight,
            'standard_packaging_cost' => $packagingCost, 'cost_currency' => 'USD', 'is_active' => true,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_summary' => $product->name, 'sku' => $product->sku,
            'quantity' => 1, 'unit_price' => '20.00', 'line_revenue' => '20.00',
        ]);
        return $product;
    }

    private function shippingWeight(Product $product, string $weight): void
    {
        $evidence = ProductWeightEvidence::query()->create([
            'product_id' => $product->id,
            'weight_kind' => 'shipping_reference_weight',
            'observed_weight_kg' => $weight,
            'raw_sku' => $product->sku,
            'source_type' => 'logistics_scan',
            'source_reference' => 'P6A-'.strtoupper(uniqid()),
            'status' => 'adopted',
            'reviewed_at' => now(),
        ]);
        $product->forceFill([
            'shipping_weight_kg' => $weight,
            'shipping_weight_evidence_id' => $evidence->id,
        ])->save();
    }

    private function approvedPackaging(Product $product, string $weight): void
    {
        $user = User::factory()->create();
        $measurement = PackagingMeasurementRecord::query()->create([
            'product_id' => $product->id, 'status' => 'approved', 'packaging_method_key' => 'custom',
            'packaging_length_cm' => '10.00', 'packaging_width_cm' => '10.00', 'packaging_height_cm' => '10.00',
            'packaged_weight_kg' => $weight, 'measured_at' => now(), 'created_by' => $user->id,
            'reviewed_by' => $user->id, 'reviewed_at' => now(),
        ]);
        ProductPackagingProfile::query()->create([
            'product_id' => $product->id, 'current_measurement_record_id' => $measurement->id,
            'preset_key' => null, 'custom_method' => 'P6A Test Packaging', 'is_active' => true,
        ]);
    }

    private function shipment(Order $order, string $code): Shipment
    {
        $warehouse = Warehouse::query()->firstOrCreate(
            ['slug' => strtolower($code)],
            [
                'code' => $code, 'name' => 'P6A '.$code, 'type' => 'warehouse',
                'ownership_type' => 'owned', 'is_active' => true, 'priority' => 100,
            ],
        );
        $shipment = new Shipment([
            'shipment_status' => 'pending', 'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'allocation',
        ]);
        $order->shipment()->save($shipment);
        return $shipment;
    }

    private function seedOtherConfirmedFacts(Order $order): void
    {
        $order->financials()->create([
            'amount_paid' => '100.00', 'refund_amount' => '0.00', 'currency' => $order->currency,
            'revenue_provenance' => 'confirmed_manual', 'completeness_status' => 'INCOMPLETE',
        ]);
        foreach ([
            'product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost',
            'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
        ] as $type) {
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => '0.00', 'currency' => $order->currency,
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'fixture:'.$type,
            ]);
        }
    }
}
