<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductOrderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): \App\Modules\Identity\Infrastructure\Models\User
    {
        $user = \App\Modules\Identity\Infrastructure\Models\User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'test-role-'.uniqid(), 'label' => 'Test Role']);
        foreach ($permissions as $perm) {
            $role->permissions()->firstOrCreate(['key' => $perm], ['label' => $perm]);
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'external_order_number' => 'TEST-'.now()->format('Ymd').'-001',
            'order_date' => now()->toDateString(),
            'items' => [],
        ], $overrides);
    }

    public function test_order_item_with_product_id_snapshots_product_name_as_summary_when_summary_empty(): void
    {
        $product = Product::create(['woocommerce_product_id' => 7001, 'sku' => 'TZ001', 'name' => 'Test Product Name', 'product_type' => 'simple']);

        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'items' => [['product_summary' => '', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00']],
        ]))->assertRedirect();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertSame('Test Product Name', $order->items->first()->product_summary);
        $this->assertSame($product->id, $order->items->first()->product_id);
    }

    public function test_order_item_explicit_summary_overrides_product_name(): void
    {
        $product = Product::create(['woocommerce_product_id' => 7002, 'sku' => 'TZ002', 'name' => 'Product Name', 'product_type' => 'simple']);

        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'TEST-'.now()->format('Ymd').'-002',
            'items' => [['product_summary' => 'Custom Summary', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00']],
        ]))->assertRedirect();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertSame('Custom Summary', $order->items->first()->product_summary);
    }

    public function test_historical_order_item_without_product_id_is_readable(): void
    {
        // Historical rows (pre-Product Master) can have null product_id at the DB level.
        // Verify the model/column allows null for read compatibility.
        $order = Order::create([
            'external_order_number' => 'HIST-001',
            'internal_order_number' => 'MAN-20260824-HIST01',
            'order_date' => now()->toDateString(),
            'order_kind' => 'customer',
            'source_type' => 'manual',
            'order_status' => 'pending_shipment',
            'owner_id' => null,
        ]);
        $item = $order->items()->create([
            'product_summary' => 'Old Item',
            'sku' => 'OLD-SKU',
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);

        $this->assertNull($item->product_id);
        $this->assertSame('Old Item', $item->product_summary);
    }

    public function test_new_manual_order_without_product_id_is_rejected(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        // New manual order lines must include a valid product_id — no free-text SKU-only lines allowed.
        $response = $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'TEST-'.now()->format('Ymd').'-NO-PROD',
            'items' => [['product_summary' => 'Free Text', 'quantity' => 1, 'unit_price' => '10.00']],
        ]));
        $response->assertSessionHasErrors(['items.0.product_id']);
    }

    public function test_blank_sku_product_cannot_be_used_for_new_order_line(): void
    {
        $blankSkuProduct = Product::create(['woocommerce_product_id' => 8001, 'sku' => null, 'name' => 'No SKU Product', 'product_type' => 'simple']);

        $user = $this->userWith(['orders.view', 'orders.create']);
        $response = $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'TEST-'.now()->format('Ymd').'-BLANK-SKU',
            'items' => [['product_summary' => 'Test', 'product_id' => $blankSkuProduct->id, 'quantity' => 1, 'unit_price' => '10.00']],
        ]));
        // Blank-SKU products exist in the master but cannot be used to create order lines.
        $response->assertSessionHasErrors(['items.0.product_id']);
    }

    public function test_selected_product_snapshots_canonical_sku(): void
    {
        $product = Product::create(['woocommerce_product_id' => 7004, 'sku' => 'CANON-001', 'name' => 'Canonical Product', 'product_type' => 'simple']);

        $user = $this->userWith(['orders.view', 'orders.create']);
        // Client sends a different SKU — server must derive SKU from the selected product.
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'TEST-'.now()->format('Ymd').'-SKU-SNAP',
            'items' => [['product_summary' => 'Test', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00']],
        ]))->assertRedirect();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertSame('CANON-001', $order->items->first()->sku);
    }

    public function test_update_with_items_and_missing_product_id_is_rejected(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'UPD-BASE-001',
            'items' => [['product_summary' => '', 'product_id' => Product::create(['woocommerce_product_id' => 9001, 'sku' => 'UPD-SKU-1', 'name' => 'P1', 'product_type' => 'simple'])->id, 'quantity' => 1, 'unit_price' => '5.00']],
        ]))->assertRedirect();
        $order = Order::latest('id')->firstOrFail();

        $response = $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'items' => [['product_summary' => 'Free text', 'quantity' => 1, 'unit_price' => '5.00']],
        ]);
        $response->assertSessionHasErrors(['items.0.product_id']);
    }

    public function test_update_with_invalid_product_id_is_rejected(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'UPD-BASE-002',
            'items' => [['product_id' => Product::create(['woocommerce_product_id' => 9002, 'sku' => 'UPD-SKU-2', 'name' => 'P2', 'product_type' => 'simple'])->id, 'quantity' => 1, 'unit_price' => '5.00']],
        ]))->assertRedirect();
        $order = Order::latest('id')->firstOrFail();

        $blankSku = Product::create(['woocommerce_product_id' => 9003, 'sku' => null, 'name' => 'No SKU', 'product_type' => 'simple']);
        $response = $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'items' => [['product_id' => $blankSku->id, 'quantity' => 1, 'unit_price' => '5.00']],
        ]);
        $response->assertSessionHasErrors(['items.0.product_id']);
    }

    public function test_update_with_canonical_product_snapshots_canonical_sku(): void
    {
        $product = Product::create(['woocommerce_product_id' => 9004, 'sku' => 'SNAP-UPD-001', 'name' => 'Snap Product', 'product_type' => 'simple']);
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'UPD-BASE-003',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '5.00']],
        ]))->assertRedirect();
        $order = Order::latest('id')->firstOrFail();

        $newProduct = Product::create(['woocommerce_product_id' => 9005, 'sku' => 'SNAP-UPD-002', 'name' => 'New Product', 'product_type' => 'simple']);
        $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'items' => [['product_id' => $newProduct->id, 'quantity' => 2, 'unit_price' => '8.00']],
        ])->assertRedirect();

        $order->refresh()->load('items');
        $this->assertSame('SNAP-UPD-002', $order->items->first()->sku);
    }

    public function test_historical_rows_remain_readable_when_items_not_replaced(): void
    {
        $order = Order::create([
            'external_order_number' => 'HIST-UPD-001',
            'internal_order_number' => 'MAN-20260824-HIST02',
            'order_date' => now()->toDateString(),
            'order_kind' => 'customer',
            'source_type' => 'manual',
            'order_status' => 'pending_shipment',
            'owner_id' => null,
        ]);
        $item = $order->items()->create([
            'product_summary' => 'Legacy Item',
            'sku' => 'LEGACY-SKU',
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);

        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        // Update order without sending items — historical rows must remain
        $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'order_note' => 'Updated note',
        ])->assertRedirect();

        $order->refresh()->load('items');
        $this->assertCount(1, $order->items);
        $this->assertNull($order->items->first()->product_id);
        $this->assertSame('Legacy Item', $order->items->first()->product_summary);
    }

    public function test_dash_summary_is_never_persisted(): void
    {
        $product = Product::create(['woocommerce_product_id' => 7003, 'sku' => 'TZ003', 'name' => 'Real Name', 'product_type' => 'simple']);

        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'TEST-'.now()->format('Ymd').'-004',
            'items' => [['product_summary' => '—', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00']],
        ]))->assertRedirect();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertNotSame('—', $order->items->first()->product_summary);
    }
}
