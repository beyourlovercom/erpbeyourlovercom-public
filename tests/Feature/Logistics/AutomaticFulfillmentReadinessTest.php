<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\AutomaticFulfillmentReadinessService;
use App\Modules\Logistics\Infrastructure\Models\ShipmentItemPackagingSnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AutomaticFulfillmentReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_order_is_excluded_before_other_readiness_checks(): void
    {
        [$order] = $this->scenario();
        $order->externalReferences()->create([
            'provider' => 'historical_logistics',
            'connection_key' => 'historical:test',
            'reference' => 'KOL-HIST-1',
        ]);

        $result = app(AutomaticFulfillmentReadinessService::class)->assess($order->fresh());

        self::assertSame('excluded', $result['status']);
        self::assertSame('excluded', $result['stage']);
        self::assertSame(['historical_excluded'], $result['blockers']);
        self::assertTrue($result['facts']['historical']);
        self::assertFalse($result['ready_to_dispatch']);
    }

    public function test_readiness_reports_order_and_allocation_blockers_without_writing(): void
    {
        [$order] = $this->scenario(resolvedProduct: false);
        $order->update(['order_status' => 'cancelled']);
        $before = $this->writeCounts();

        $result = app(AutomaticFulfillmentReadinessService::class)->assess($order->fresh());

        self::assertSame('blocked', $result['status']);
        self::assertSame('order', $result['stage']);
        self::assertSame(
            ['order_not_pending', 'unresolved_product', 'shipment_draft_missing'],
            $result['blockers'],
        );
        self::assertSame($before, $this->writeCounts());
    }

    public function test_readiness_detects_reservation_packaging_and_carrier_gaps(): void
    {
        [$order, $product, $warehouse] = $this->scenario();
        $shipment = $order->shipment()->create([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'recommended',
        ]);

        $withoutReservation = app(AutomaticFulfillmentReadinessService::class)->assess($order->fresh());
        self::assertSame('allocation', $withoutReservation['stage']);
        self::assertSame(
            ['active_reservation_missing', 'packaging_snapshot_missing', 'carrier_decision_missing'],
            $withoutReservation['blockers'],
        );

        $other = Warehouse::query()->create([
            'slug' => 'other-'.uniqid(), 'code' => 'OTH'.random_int(100, 999), 'name' => 'Other Warehouse',
            'is_active' => true, 'priority' => 90,
        ]);
        InventoryReservation::query()->create([
            'reservation_key' => 'rsv-'.uniqid(), 'payload_hash' => str_repeat('a', 64),
            'order_id' => $order->id, 'warehouse_id' => $other->id, 'status' => 'reserved',
        ]);

        $mismatch = app(AutomaticFulfillmentReadinessService::class)->assess($order->fresh());
        self::assertSame('allocation', $mismatch['stage']);
        self::assertContains('reservation_warehouse_mismatch', $mismatch['blockers']);
        self::assertContains('packaging_snapshot_missing', $mismatch['blockers']);
        self::assertContains('carrier_decision_missing', $mismatch['blockers']);

        InventoryReservation::query()->where('order_id', $order->id)->update(['warehouse_id' => $warehouse->id]);
        ShipmentItemPackagingSnapshot::query()->create([
            'shipment_id' => $shipment->id,
            'order_item_id' => $order->items()->firstOrFail()->id,
            'product_id' => $product->id,
            'packaging_method_key' => 'test',
            'packaging_method_label' => 'Test',
            'packaging_length_cm' => '10.00',
            'packaging_width_cm' => '8.00',
            'packaging_height_cm' => '6.00',
            'packaged_weight_kg' => '0.500',
            'captured_at' => now(),
        ]);

        $carrierMissing = app(AutomaticFulfillmentReadinessService::class)->assess($order->fresh());
        self::assertSame('carrier', $carrierMissing['stage']);
        self::assertSame(['carrier_decision_missing'], $carrierMissing['blockers']);
    }

    public function test_fully_populated_current_facts_still_stop_at_dispatch_boundary(): void
    {
        [$order, $product, $warehouse] = $this->scenario();
        $shipment = $order->shipment()->create([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'recommended',
            'carrier' => 'UPS',
            'service' => 'Ground',
            'carrier_selection_source' => 'manual',
        ]);
        InventoryReservation::query()->create([
            'reservation_key' => 'rsv-'.uniqid(), 'payload_hash' => str_repeat('b', 64),
            'order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'status' => 'reserved',
        ]);
        $item = $order->items()->firstOrFail();
        ShipmentItemPackagingSnapshot::query()->create([
            'shipment_id' => $shipment->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'packaging_method_key' => 'test',
            'packaging_method_label' => 'Test',
            'packaging_length_cm' => '10.00',
            'packaging_width_cm' => '8.00',
            'packaging_height_cm' => '6.00',
            'packaged_weight_kg' => '0.500',
            'captured_at' => now(),
        ]);
        $before = $this->writeCounts();

        $result = app(AutomaticFulfillmentReadinessService::class)->assess($order->fresh());

        self::assertSame('blocked', $result['status']);
        self::assertSame('dispatch', $result['stage']);
        self::assertSame(['automatic_dispatch_unavailable'], $result['blockers']);
        self::assertFalse($result['facts']['automatic_dispatch_supported']);
        self::assertFalse($result['ready_to_dispatch']);
        self::assertSame($before, $this->writeCounts());
    }

    /** @return array{0:Order,1:?Product,2:Warehouse} */
    private function scenario(bool $resolvedProduct = true): array
    {
        $product = $resolvedProduct ? Product::query()->create([
            'source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'AUTO-'.uniqid(),
            'name' => 'Automatic Fulfillment Test', 'is_active' => true,
        ]) : null;
        $warehouse = Warehouse::query()->create([
            'slug' => 'auto-'.uniqid(), 'code' => 'AUT'.random_int(100, 999), 'name' => 'Auto Warehouse',
            'is_active' => true, 'priority' => 10,
        ]);
        $order = Order::query()->create([
            'internal_order_number' => 'INT-AUTO-'.uniqid(),
            'erp_order_id' => '007erp-auto-'.uniqid(),
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'AUTO-'.uniqid(),
            'order_date' => '2026-09-06',
            'currency' => 'USD',
            'order_status' => 'pending_shipment',
        ]);
        $order->items()->create([
            'product_id' => $product?->id,
            'product_summary' => $product?->name ?? 'Unresolved Item',
            'sku' => $product?->sku ?? '',
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);

        return [$order, $product, $warehouse];
    }

    /** @return array<string,int> */
    private function writeCounts(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'shipments' => DB::table('shipments')->count(),
            'reservations' => DB::table('inventory_reservations')->count(),
            'packaging_snapshots' => DB::table('shipment_item_packaging_snapshots')->count(),
            'adjustments' => DB::table('inventory_adjustments')->count(),
            'audits' => DB::table('audit_records')->count(),
        ];
    }
}
