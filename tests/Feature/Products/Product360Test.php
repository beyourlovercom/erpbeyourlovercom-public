<?php

namespace Tests\Feature\Products;

use App\Modules\Audit\Application\ProductActivityProjection;
use App\Modules\Kol\Application\ProductKolProjection;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Kol\Infrastructure\Models\KolCollaborationProduct;
use App\Modules\Logistics\Application\ProductShipmentProjection;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Application\ProductOrderProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductExternalReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Product360Test extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'product360-'.uniqid(), 'label' => 'Product360']);
        foreach ($permissions as $permission) $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_view_permission_opens_read_only_product360_and_operator_gets_edit_action(): void
    {
        $product = Product::create(['source_type' => 'manual', 'sku' => 'P360-001', 'name' => 'Product 360', 'product_type' => 'simple']);
        $viewer = $this->userWith(['products.view']);
        $response = $this->actingAs($viewer)->get("/products/{$product->id}");
        $response->assertOk()->assertInertia(fn ($page) => $page->component('Products/Show')->where('product.id', $product->id)->where('canOperate', false)->where('kolShipping.lifetime_units', 0)->where('kolShipping.lifetime_shipment_count', 0));

        $operator = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($operator)->get("/products/{$product->id}")->assertInertia(fn ($page) => $page->where('canOperate', true));
    }

    public function test_product_order_projection_preserves_windows_and_currency_groups(): void
    {
        $product = Product::create(['sku' => 'P360-SALES', 'name' => 'Sales Product', 'product_type' => 'simple']);
        foreach ([['USD', 2, 12.00, now()->subDays(5)], ['EUR', 3, 18.00, now()->subDays(45)], ['USD', 4, 24.00, now()->subDays(120)]] as [$currency, $quantity, $revenue, $date]) {
            $order = Order::create(['internal_order_number' => 'P360-'.uniqid(), 'erp_order_id' => '007-'.uniqid(), 'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => $date->toDateString(), 'order_status' => 'pending_shipment', 'currency' => $currency]);
            DB::table('order_items')->insert(['order_id' => $order->id, 'product_id' => $product->id, 'product_summary' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'unit_price' => $revenue / $quantity, 'line_revenue' => $revenue, 'created_at' => now(), 'updated_at' => now()]);
        }
        $sales = app(ProductOrderProjection::class)->forProduct($product->id);
        $this->assertSame(2, $sales['summary']['units_30d']);
        $this->assertSame(5, $sales['summary']['units_90d']);
        $this->assertSame(9, $sales['summary']['lifetime_units']);
        $this->assertSame(['EUR', 'USD'], array_column($sales['revenue_by_currency'], 'currency'));
        $this->assertTrue(collect($sales['recent_orders'])->every(fn (array $row): bool => str_starts_with((string) $row['erp_order_id'], '007-')));
    }

    public function test_product360_uses_external_reference_projection_without_new_storage(): void
    {
        $product = Product::create(['sku' => 'P360-WOO', 'name' => 'Woo Product', 'product_type' => 'simple']);
        ProductExternalReference::create(['product_id' => $product->id, 'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '101', 'reference_type' => 'product']);
        ProductExternalReference::create(['product_id' => $product->id, 'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '102', 'reference_type' => 'variation']);
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get("/products/{$product->id}")->assertInertia(fn ($page) => $page->has('externalReferences', 2)->where('externalReferences.1.reference_type', 'variation'));
        $this->assertFalse(\Schema::hasTable('product360'));
    }

    public function test_inventory_projection_exposes_canonical_expected_restock_quantity_and_unknown(): void
    {
        $product = Product::create(['sku' => 'P360-STOCK', 'name' => 'Stock Product', 'product_type' => 'simple']);
        $warehouse = Warehouse::create(['slug' => 'p360-stock', 'code' => 'P360-STOCK', 'name' => 'P360 Stock', 'is_active' => true, 'priority' => 1]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'expected_restock_qty' => 7]);

        $rows = app(InventoryAvailabilityReader::class)->forProductIds([$product->id])[$product->id];
        $row = collect($rows)->firstWhere('warehouse_id', $warehouse->id);
        $this->assertSame(7, $row['expected_restock_qty']);
        $this->assertSame(0, $row['quantity_available']);

        $other = Product::create(['sku' => 'P360-UNKNOWN', 'name' => 'Unknown Stock Product', 'product_type' => 'simple']);
        $unknown = collect(app(InventoryAvailabilityReader::class)->forProductIds([$other->id])[$other->id])->firstWhere('warehouse_id', $warehouse->id);
        $this->assertNull($unknown['expected_restock_qty']);
    }
    public function test_shipment_projection_reads_canonical_logistics_for_product_orders(): void
    {
        $product = Product::create(['sku' => 'P360-SHIP', 'name' => 'Shipment Product', 'product_type' => 'simple']);
        $order = Order::create(['internal_order_number' => 'P360-SHIP-ORDER', 'erp_order_id' => '007-0908-cship', 'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => now()->toDateString(), 'order_status' => 'shipped', 'currency' => 'USD']);
        DB::table('order_items')->insert(['order_id' => $order->id, 'product_id' => $product->id, 'product_summary' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 10, 'line_revenue' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipments')->insert(['order_id' => $order->id, 'shipment_status' => 'shipped', 'carrier' => 'UPS', 'tracking_number' => 'P360-TRACK', 'shipped_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $rows = app(ProductShipmentProjection::class)->forProduct($product->id);
        $this->assertCount(1, $rows);
        $this->assertSame($order->id, $rows[0]['order_id']);
        $this->assertSame('007-0908-cship', $rows[0]['erp_order_id']);
        $this->assertSame('P360-TRACK', $rows[0]['tracking_number']);
    }

    public function test_kol_projection_reads_canonical_collaboration_product_relation(): void
    {
        $product = Product::create(['sku' => 'P360-KOL', 'name' => 'KOL Product', 'product_type' => 'simple']);
        $kol = Kol::create(['display_name' => 'P360 Creator', 'status' => 'active', 'relationship_stage' => 'cooperating']);
        $collaboration = KolCollaboration::create(['kol_id' => $kol->id, 'title' => 'P360 Collaboration', 'status' => 'confirmed', 'currency' => 'USD']);
        KolCollaborationProduct::create(['collaboration_id' => $collaboration->id, 'product_id' => $product->id, 'sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name, 'quantity' => 2]);

        $rows = app(ProductKolProjection::class)->forProduct($product->id);
        $this->assertCount(1, $rows);
        $this->assertSame('P360 Creator', $rows[0]['kol_name']);
        $this->assertSame(2, $rows[0]['quantity']);
    }

    public function test_kol_shipping_summary_counts_only_actual_shipped_kol_fulfillment_by_shipped_at(): void
    {
        $product = Product::create(['sku' => 'P360-KOL-SHIP', 'name' => 'KOL Shipping Product', 'product_type' => 'simple']);
        foreach ([
            ['kol_fulfillment', 'kol', 'shipped', 5, 2],
            ['kol_fulfillment', 'kol', 'shipped', 45, 3],
            ['kol_fulfillment', 'kol', 'shipped', 120, 4],
            ['customer', 'manual', 'shipped', 5, 99],
            ['kol_fulfillment', 'kol', 'pending', 5, 88],
        ] as [$kind, $source, $shipmentStatus, $daysAgo, $quantity]) {
            $order = Order::create(['internal_order_number' => 'P360-KOL-SHIP-'.uniqid(), 'source_type' => $source, 'order_kind' => $kind, 'order_date' => now()->subDays($daysAgo)->toDateString(), 'order_status' => $shipmentStatus === 'shipped' ? 'shipped' : 'pending_shipment', 'currency' => 'USD']);
            DB::table('order_items')->insert(['order_id' => $order->id, 'product_id' => $product->id, 'product_summary' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'unit_price' => 0, 'line_revenue' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('shipments')->insert(['order_id' => $order->id, 'shipment_status' => $shipmentStatus, 'shipped_at' => $shipmentStatus === 'shipped' ? now()->subDays($daysAgo) : null, 'created_at' => now(), 'updated_at' => now()]);
        }

        $summary = app(ProductKolProjection::class)->shippingSummaryForProduct($product->id);
        $this->assertSame(2, $summary['units_30d']);
        $this->assertSame(1, $summary['shipment_count_30d']);
        $this->assertSame(5, $summary['units_90d']);
        $this->assertSame(2, $summary['shipment_count_90d']);
        $this->assertSame(9, $summary['lifetime_units']);
        $this->assertSame(3, $summary['lifetime_shipment_count']);
    }

    public function test_activity_projection_labels_only_evidence_based_system_actor(): void
    {
        $product = Product::create(['sku' => 'P360-AUDIT', 'name' => 'Audit Product', 'product_type' => 'simple']);
        DB::table('audit_records')->insert([
            ['action' => 'product.updated', 'entity_type' => 'product', 'entity_id' => (string) $product->id, 'source' => 'system', 'created_at' => now()->subSecond()],
            ['action' => 'product.updated', 'entity_type' => 'product', 'entity_id' => (string) $product->id, 'source' => 'web', 'created_at' => now()],
        ]);

        $rows = app(ProductActivityProjection::class)->forProduct($product->id);
        $this->assertCount(2, $rows);
        $web = collect($rows)->firstWhere('source', 'web');
        $system = collect($rows)->firstWhere('source', 'system');
        $this->assertNull($web['actor_label']);
        $this->assertSame('系统', $system['actor_label']);
    }

}
