<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\InventoryAdjustment;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservationItem;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\ProductionUatFulfillmentCorrectionService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductionUatFulfillmentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(WooCommerceTrackingGateway::class, new ProductionUatCorrectionFakeGateway);
    }

    public function test_preview_is_read_only_and_uses_remote_tracking_truth_without_guessing_warehouse(): void
    {
        $scenario = $this->scenario();

        $preview = app(ProductionUatFulfillmentCorrectionService::class)->preview($scenario['order']->erp_order_id);

        $this->assertSame('change', $preview['decision']);
        $this->assertSame('USPS', $preview['remote']['carrier']);
        $this->assertSame('9235990424546100226911', $preview['remote']['tracking_number']);
        $this->assertSame('2026-09-10', $preview['remote']['ship_date']);
        $this->assertSame(47, $scenario['inventory']->fresh()->quantity_on_hand);
        $this->assertSame(2, $scenario['inventory']->fresh()->quantity_reserved);
        $this->assertDatabaseCount('shipment_attempts', 0);
        $this->assertDatabaseCount('inventory_adjustments', 1);
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_apply_requires_exact_fingerprint_and_does_not_mutate_on_mismatch(): void
    {
        $scenario = $this->scenario();

        $this->artisan('logistics:repair-production-uat-fulfillment', [
            '--erp-order-id' => $scenario['order']->erp_order_id,
            '--apply' => true,
            '--expect-fingerprint' => str_repeat('0', 64),
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertSame(47, $scenario['inventory']->fresh()->quantity_on_hand);
        $this->assertSame('4PX', $scenario['shipment']->fresh()->carrier);
        $this->assertDatabaseCount('shipment_attempts', 0);
        $this->assertDatabaseCount('inventory_adjustments', 1);
    }

    public function test_apply_restores_inventory_snapshots_uat_evidence_and_keeps_unknown_warehouse_null(): void
    {
        $scenario = $this->scenario();
        $service = app(ProductionUatFulfillmentCorrectionService::class);
        $preview = $service->preview($scenario['order']->erp_order_id);

        $result = $service->apply($scenario['order']->erp_order_id, $preview['fingerprint']);

        $this->assertSame('applied', $result['gate']);
        $inventory = $scenario['inventory']->fresh();
        $this->assertSame(48, $inventory->quantity_on_hand);
        $this->assertSame(2, $inventory->quantity_reserved);
        $this->assertDatabaseHas('inventory_adjustments', [
            'warehouse_inventory_id' => $inventory->id,
            'quantity_before' => 47,
            'quantity_after' => 48,
            'reserved_before' => 2,
            'reserved_after' => 2,
            'reason' => 'uat_fulfillment_correction_reversal_reservation_'.$scenario['reservation']->id,
        ]);

        $shipment = $scenario['shipment']->fresh();
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertNull($shipment->warehouse_id);
        $this->assertNull($shipment->warehouse_selection_source);
        $this->assertSame('USPS', $shipment->carrier);
        $this->assertNull($shipment->service);
        $this->assertSame('external_woocommerce', $shipment->carrier_selection_source);
        $this->assertSame('9235990424546100226911', $shipment->tracking_number);
        $this->assertNull($shipment->shipped_at);
        $this->assertStringContainsString('Woo ship date=2026-09-10', (string) $shipment->logistics_note);

        $attempt = $scenario['order']->shipmentAttempts()->sole();
        $this->assertSame('shipped', $attempt->shipment_status);
        $this->assertSame($scenario['warehouse']->id, $attempt->warehouse_id);
        $this->assertSame('4PX', $attempt->carrier);
        $this->assertSame('SF0210597711527 王晓峰收 请重新打包后发出', $attempt->tracking_number);
        $this->assertSame('9235990424546100226911', $attempt->evidence_snapshot['correction']['remote']['tracking_number']);
        $this->assertSame('2026-09-10', $attempt->evidence_snapshot['correction']['remote']['ship_date']);

        $this->assertDatabaseHas('audit_records', ['action' => 'inventory.uat_fulfillment_effect_reversed']);
        $this->assertDatabaseHas('audit_records', ['action' => 'shipment.uat_fulfillment_corrected']);
        $this->assertSame('shipped', $scenario['order']->fresh()->order_status->value);
    }

    public function test_post_apply_preview_converges_to_noop(): void
    {
        $scenario = $this->scenario();
        $service = app(ProductionUatFulfillmentCorrectionService::class);
        $preview = $service->preview($scenario['order']->erp_order_id);
        $service->apply($scenario['order']->erp_order_id, $preview['fingerprint']);

        $post = $service->preview($scenario['order']->erp_order_id);

        $this->assertSame('noop', $post['decision']);
        $this->assertSame('already_reconciled', $post['reason']);
        $this->assertDatabaseCount('shipment_attempts', 1);
        $this->assertDatabaseCount('inventory_adjustments', 2);
    }

    /** @return array{order:Order,shipment:Shipment,warehouse:Warehouse,inventory:WarehouseInventory,reservation:InventoryReservation} */
    private function scenario(): array
    {
        $warehouse = Warehouse::create([
            'slug' => 'de-uat-test',
            'code' => 'DE-UAT',
            'name' => '德国仓 UAT',
            'is_active' => true,
        ]);
        $product = Product::create([
            'sku' => 'VA617A-GREEN-UAT',
            'name' => 'VA617A GREEN UAT',
            'is_active' => true,
        ]);
        $order = Order::create([
            'internal_order_number' => 'UAT-CORRECTION-40377',
            'erp_order_id' => '007-0909-c40377',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => '40377-VA617A-GREEN',
            'order_date' => '2026-09-09',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => '40377',
        ]);
        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
            'shipment_status' => 'shipped',
            'carrier' => '4PX',
            'service' => '1',
            'carrier_selection_source' => 'manual',
            'tracking_number' => 'SF0210597711527 王晓峰收 请重新打包后发出',
            'shipped_at' => '2026-09-09 08:09:00',
        ]);
        $inventory = WarehouseInventory::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 47,
            'quantity_reserved' => 2,
        ]);
        $reservation = InventoryReservation::create([
            'reservation_key' => 'uat-40377-reservation',
            'payload_hash' => str_repeat('a', 64),
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'committed',
            'committed_at' => '2026-09-09 08:11:09',
        ]);
        InventoryReservationItem::create([
            'inventory_reservation_id' => $reservation->id,
            'warehouse_inventory_id' => $inventory->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        InventoryAdjustment::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'warehouse_inventory_id' => $inventory->id,
            'quantity_before' => 48,
            'quantity_after' => 47,
            'reserved_before' => 3,
            'reserved_after' => 2,
            'reason' => 'reservation_committed',
        ]);

        return compact('order', 'shipment', 'warehouse', 'inventory', 'reservation');
    }
}

final class ProductionUatCorrectionFakeGateway implements WooCommerceTrackingGateway
{
    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a'];
    }

    public function inspectTracking(int $providerOrderId): array
    {
        return [
            'tracking_number' => '9235990424546100226911',
            'provider' => 'usps',
            'ship_date' => '2026-09-10',
            'has_tracking' => true,
        ];
    }

    public function syncTracking(int $providerOrderId, string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems = []): array
    {
        return ['written' => false, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
