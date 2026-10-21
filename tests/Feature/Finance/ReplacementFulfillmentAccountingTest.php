<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\OrderProfitExplanationService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageCostImportBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageCostImportRow;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReplacementFulfillmentAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_replacement_is_posted_only_to_replacement_loss_and_not_original_logistics(): void
    {
        [$parent, $product] = $this->parentWithProduct();
        $original = $this->fulfillment($parent, $product, 'A', 'original', 'us-la', 'US-LA', 'ORIGINAL-A', 1);
        $replacement = $this->fulfillment($parent, $product, 'R1', 'replacement', 'factory', 'FACTORY', 'REPL-R1', 1, 'damaged');
        $this->bill($original, '5.00', 'USD', 'ORIGINAL-BILL');
        $this->bill($replacement, '12.00', 'USD', 'REPL-BILL');

        $service = app(CostMaterializationService::class);
        $originalGate = $service->previewMultiShipmentGate($parent->fresh());
        $this->assertSame(1, $originalGate['shipment_count']);
        $last = collect($originalGate['candidates'])->firstWhere('cost_type', 'last_mile_cost');
        $this->assertSame('5.00', $last['amount']);
        $this->assertCount(1, $last['shipment_breakdown']);
        $this->assertSame('A', $last['shipment_breakdown'][0]['fulfillment_suffix']);

        $replacementGate = $service->previewReplacementLossGate($parent->fresh());
        $this->assertSame(1, $replacementGate['replacement_count']);
        $candidate = $replacementGate['candidates'][0];
        $this->assertSame('22.00', $candidate['amount']);
        $this->assertSame('USD', $candidate['currency']);
        $this->assertSame('apply', $candidate['materialization_status']);
        $this->assertSame('10.00', $candidate['replacement_breakdown'][0]['components']['product_cost']['amount']);
        $this->assertSame('0.00', $candidate['replacement_breakdown'][0]['components']['first_mile_cost']['amount']);
        $this->assertSame('0.00', $candidate['replacement_breakdown'][0]['components']['overseas_handling_cost']['amount']);
        $this->assertSame('0.00', $candidate['replacement_breakdown'][0]['components']['warehouse_cost']['amount']);
        $this->assertSame('12.00', $candidate['replacement_breakdown'][0]['components']['last_mile_cost']['amount']);

        $applied = $service->applyReplacementLossGate($parent->fresh());
        $this->assertCount(1, $applied['applied']);
        $this->assertSame('22.00', $parent->costEntries()->where('cost_type', 'refund_replacement_loss')->value('amount'));
        $this->assertCount(1, $service->applyReplacementLossGate($parent->fresh())['noop']);

        $explanation = app(OrderProfitExplanationService::class)->explain($parent->fresh());
        $this->assertSame(1, $explanation['order']['original_shipment_count']);
        $this->assertSame(1, $explanation['order']['replacement_shipment_count']);
        $this->assertSame(2, $explanation['order']['shipment_count']);
        $this->assertSame('22.00', $explanation['replacement_loss']['amount']);
        $this->assertSame('confirmed', $explanation['replacement_loss']['state']);
        $this->assertSame('replacement_shipments_excluded_from_original_product_and_logistics_costs', $explanation['replacement_loss']['double_count_guard']);
    }

    public function test_overseas_replacement_includes_product_first_handling_warehouse_and_last_mile(): void
    {
        [$parent, $product] = $this->parentWithProduct();
        $original = $this->fulfillment($parent, $product, 'A', 'original', 'factory', 'FACTORY', 'ORIGINAL-CN', 1);
        $replacement = $this->fulfillment($parent, $product, 'R1', 'replacement', 'us-la', 'US-LA', 'REPL-US', 2, 'lost');
        $this->bill($original, '8.00', 'USD', 'ORIGINAL-CN-BILL');
        $this->bill($replacement, '9.50', 'USD', 'REPL-US-BILL');
        $this->warehouseCost($replacement, '3.25', 2);

        $gate = app(CostMaterializationService::class)->previewReplacementLossGate($parent->fresh());
        $candidate = $gate['candidates'][0];
        $this->assertSame('36.75', $candidate['amount']);
        $components = $candidate['replacement_breakdown'][0]['components'];
        $this->assertSame('20.00', $components['product_cost']['amount']);
        $this->assertSame('2.00', $components['first_mile_cost']['amount']);
        $this->assertSame('2.00', $components['overseas_handling_cost']['amount']);
        $this->assertSame('3.25', $components['warehouse_cost']['amount']);
        $this->assertSame('9.50', $components['last_mile_cost']['amount']);
    }

    public function test_replacement_loss_fails_closed_until_actual_last_mile_and_warehouse_evidence_exist(): void
    {
        [$parent, $product] = $this->parentWithProduct();
        $this->fulfillment($parent, $product, 'A', 'original', 'factory', 'FACTORY', 'ORIGINAL', 1);
        $replacement = $this->fulfillment($parent, $product, 'R1', 'replacement', 'us-la', 'US-LA', 'REPL-PENDING-COST', 1, 'maintenance');

        $gate = app(CostMaterializationService::class)->previewReplacementLossGate($parent->fresh());
        $this->assertSame('blocked', $gate['candidates'][0]['materialization_status']);
        $this->assertSame('replacement_warehouse_cost_allocation_missing', $gate['candidates'][0]['blocker']);

        $this->warehouseCost($replacement, '2.00', 1);
        $gate = app(CostMaterializationService::class)->previewReplacementLossGate($parent->fresh());
        $this->assertSame('blocked', $gate['candidates'][0]['materialization_status']);
        $this->assertSame('replacement_last_mile_billing_missing', $gate['candidates'][0]['blocker']);
    }

    private function parentWithProduct(): array
    {
        $product = Product::query()->create([
            'source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'REPL-FIN-SKU-'.uniqid(),
            'name' => 'Replacement Finance Product', 'cost_currency' => 'USD', 'is_active' => true,
        ]);
        ProductCostSource::query()->create([
            'product_id' => $product->id, 'source_key' => 'replacement-finance', 'unit_cost' => '10.00',
            'currency' => 'USD', 'provenance' => 'confirmed_source_file', 'status' => 'confirmed',
            'source_type' => 'inventory_cost_book', 'source_reference' => 'replacement-cost-book.xlsx',
            'effective_from' => '2026-09-01', 'source_at' => '2026-09-19 00:00:00',
            'confirmed_at' => '2026-09-19 00:00:00', 'source_fingerprint' => hash('sha256', 'replacement-finance-'.$product->id),
        ]);
        $parent = Order::query()->create([
            'internal_order_number' => 'REPL-FIN-'.uniqid(), 'erp_order_id' => '007-0919-cRF'.random_int(100,999),
            'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => '2026-09-19',
            'order_status' => 'shipped', 'currency' => 'USD',
        ]);
        $parent->address()->create(['recipient_name' => 'Replacement Finance', 'country' => 'US', 'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => '1 Finance Way', 'postal_code' => '90001']);
        $parent->items()->create([
            'product_id' => $product->id, 'product_summary' => $product->name, 'sku' => $product->sku,
            'quantity' => 1, 'unit_price' => '50.00', 'line_revenue' => '50.00',
        ]);
        return [$parent, $product];
    }

    private function fulfillment(Order $parent, Product $product, string $suffix, string $role, string $slug, string $code, string $tracking, int $quantity, ?string $reason = null)
    {
        $warehouse = Warehouse::query()->updateOrCreate(['code' => $code], [
            'slug' => $slug, 'name' => $code, 'country_code' => $code === 'FACTORY' ? 'CN' : 'US', 'is_active' => true,
        ]);
        $child = Order::query()->create([
            'fulfillment_parent_id' => $parent->id, 'fulfillment_suffix' => $suffix, 'fulfillment_role' => $role,
            'fulfillment_reason' => $reason, 'internal_order_number' => 'REPL-FIN-'.$parent->id.'-'.$suffix,
            'erp_order_id' => $parent->erp_order_id.$suffix, 'source_type' => 'manual_other', 'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-09-19', 'order_status' => 'shipped', 'currency' => 'USD',
        ]);
        $child->items()->create([
            'product_id' => $product->id, 'product_summary' => $product->name, 'sku' => $product->sku,
            'source_sku' => $product->sku, 'resolved_fulfillment_sku' => $product->sku,
            'quantity' => $quantity, 'unit_price' => '0.00', 'line_revenue' => '0.00',
        ]);
        return $child->shipment()->create([
            'warehouse_id' => $warehouse->id, 'warehouse_selection_source' => $role,
            'shipment_status' => 'shipped', 'tracking_number' => $tracking, 'carrier' => $code === 'FACTORY' ? '4PX' : 'USPS', 'shipped_at' => '2026-09-19 12:00:00',
        ]);
    }

    private function bill($shipment, string $amount, string $currency, string $document): void
    {
        $shipment->billingEvidence()->create([
            'actual_cost' => $amount, 'currency' => $currency, 'billing_document_number' => $document,
            'data_source' => 'replacement_accounting_test', 'billed_at' => '2026-09-19 13:00:00',
        ]);
    }

    private function warehouseCost($shipment, string $amount, int $units): void
    {
        $batch = WarehouseStorageCostImportBatch::query()->create([
            'provider' => 'replacement-test-'.uniqid(), 'warehouse_id' => $shipment->warehouse_id,
            'billing_period_start' => '2026-09-01', 'billing_period_end' => '2026-09-30', 'original_filename' => 'replacement-test.xlsx',
            'source_checksum' => hash('sha256', uniqid('source', true)), 'parser_version' => 'test-v1', 'plan_checksum' => hash('sha256', uniqid('plan', true)),
            'status' => 'applied', 'storage_pool_amount' => $amount, 'currency' => 'USD', 'allocation_rate_per_unit' => bcdiv($amount, (string) $units, 8),
            'total_rows' => 1, 'ready_count' => 1, 'excluded_count' => 0, 'review_count' => 0, 'total_outbound_units' => $units,
            'ready_customer_units' => $units, 'excluded_units' => 0, 'review_units' => 0, 'ready_customer_source_amount' => $amount,
            'excluded_source_amount' => '0.00', 'review_source_amount' => '0.00', 'applied_at' => '2026-09-19 14:00:00',
        ]);
        WarehouseStorageCostImportRow::query()->create([
            'batch_id' => $batch->id, 'source_row_number' => 1, 'reference_number' => 'REPL-WH-'.$shipment->id,
            'tracking_number' => $shipment->tracking_number, 'outbound_units' => $units, 'shipment_id' => $shipment->id,
            'order_id' => $shipment->order_id, 'decision' => 'ready', 'reason_code' => 'matched', 'allocated_source_amount' => $amount,
            'row_hash' => hash('sha256', 'row-'.$shipment->id.'-'.$amount), 'raw_data' => [], 'normalized_data' => [],
        ]);
    }
}
