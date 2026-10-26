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

    public function test_noop_item_payload_preserves_existing_item_ids_on_note_update(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $first = Product::create(['woocommerce_product_id' => 9010, 'sku' => 'NOOP-A', 'name' => 'Noop A', 'product_type' => 'simple']);
        $second = Product::create(['woocommerce_product_id' => 9011, 'sku' => 'NOOP-B', 'name' => 'Noop B', 'product_type' => 'simple']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'NOOP-001', 'items' => [
                ['product_id' => $first->id, 'quantity' => 1, 'unit_price' => '5.00'],
                ['product_id' => $second->id, 'quantity' => 2, 'unit_price' => '0.00'],
            ],
        ]))->assertRedirect();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $beforeIds = $order->items->pluck('id')->sort()->values()->all();
        $sameRows = $order->items->map(fn ($item) => [
            'id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $item->quantity,
            'product_summary' => $item->product_summary, 'unit_price' => $item->unit_price,
        ])->all();
        $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'order_note' => 'Changed note only', 'items' => $sameRows,
        ])->assertRedirect();
        $this->assertSame($beforeIds, $order->fresh()->items->pluck('id')->sort()->values()->all());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_note' => 'Changed note only']);
    }

    public function test_amending_unallocated_items_preserves_surviving_ids_and_adds_zero_price_gift(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $original = Product::create(['woocommerce_product_id' => 9012, 'sku' => 'COLOR-RED', 'name' => 'Red', 'product_type' => 'simple']);
        $replacement = Product::create(['woocommerce_product_id' => 9013, 'sku' => 'COLOR-BLUE', 'name' => 'Blue', 'product_type' => 'simple']);
        $gift = Product::create(['woocommerce_product_id' => 9014, 'sku' => 'GIFT-A', 'name' => 'Gift', 'product_type' => 'simple']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'AMEND-001',
            'items' => [['product_id' => $original->id, 'quantity' => 1, 'unit_price' => '79.00']],
        ]))->assertRedirect();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $oldItemId = $order->items->first()->id;

        $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'items' => [
                ['id' => $oldItemId, 'product_id' => $replacement->id, 'quantity' => 2, 'unit_price' => '79.00'],
                ['id' => null, 'product_id' => $gift->id, 'quantity' => 1, 'unit_price' => '0.00'],
            ],
        ])->assertRedirect();
        $this->assertDatabaseHas('order_items', [
            'id' => $oldItemId, 'order_id' => $order->id, 'sku' => 'COLOR-BLUE', 'quantity' => 2, 'line_revenue' => '158.00',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'sku' => 'GIFT-A', 'quantity' => 1, 'line_revenue' => '0.00',
        ]);
        $this->assertCount(2, $order->fresh()->items);
    }

    public function test_item_change_after_allocation_is_rejected_but_note_only_save_keeps_id(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $product = Product::create(['woocommerce_product_id' => 9015, 'sku' => 'LOCKED-A', 'name' => 'Locked', 'product_type' => 'simple']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'LOCKED-001',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '19.00']],
        ]))->assertRedirect();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $line = $order->items->first();
        $order->fulfillmentAllocation()->create(['status' => 'submitted', 'decision_source' => 'manual', 'plan_snapshot' => []]);

        $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'items' => [['id' => $line->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => '19.00']],
        ])->assertSessionHasErrors(['items']);
        $this->assertDatabaseHas('order_items', ['id' => $line->id, 'quantity' => 1]);

        $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'order_note' => 'Allowed note', 'items' => [[
                'id' => $line->id, 'product_id' => $product->id,
                'quantity' => 1, 'product_summary' => $line->product_summary, 'unit_price' => '19.00',
            ]],
        ])->assertRedirect();
        $this->assertDatabaseHas('order_items', ['id' => $line->id, 'quantity' => 1]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_note' => 'Allowed note']);
    }

    public function test_item_amendment_preview_explains_sku_quantity_and_amount_without_writing(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $original = Product::create(['woocommerce_product_id' => 9031, 'sku' => 'PREVIEW-RED', 'name' => 'Red', 'product_type' => 'simple']);
        $blue = Product::create(['woocommerce_product_id' => 9032, 'sku' => 'PREVIEW-BLUE', 'name' => 'Blue', 'product_type' => 'simple']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'PREVIEW-UNALLOCATED',
            'items' => [['product_id' => $original->id, 'quantity' => 1, 'unit_price' => '79.00']],
        ]))->assertRedirect();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $originalLine = $order->items->first();

        $this->actingAs($user)->postJson("/customers/orders/{$order->id}/items/preview", [
            'items' => [
                ['id' => $originalLine->id, 'product_id' => $blue->id, 'quantity' => 2, 'unit_price' => '79.00'],
            ],
        ])->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('can_save_direct', true)
            ->assertJsonPath('requires_replan', false)
            ->assertJsonPath('rows.0.before.sku', 'PREVIEW-RED')
            ->assertJsonPath('rows.0.after.sku', 'PREVIEW-BLUE')
            ->assertJsonPath('rows.0.after.quantity', 2)
            ->assertJsonPath('old_items_gross', '79.00')
            ->assertJsonPath('new_items_gross', '158.00')
            ->assertJsonPath('gross_delta', '79.00')
            ->assertJsonPath('no_writes', true);
        $this->assertDatabaseHas('order_items', [
            'id' => $originalLine->id, 'product_id' => $original->id, 'quantity' => 1,
        ]);
    }

    public function test_allocated_order_item_preview_marks_required_replan_and_never_edits_data(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $product = Product::create(['woocommerce_product_id' => 9033, 'sku' => 'ALLOCATED-PREV', 'name' => 'A', 'product_type' => 'simple']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'PREVIEW-ALLOCATED',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '19.00']],
        ]))->assertRedirect();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $line = $order->items->first();
        $order->fulfillmentAllocation()->create(['status' => 'submitted', 'decision_source' => 'manual', 'plan_snapshot' => []]);

        $this->actingAs($user)->postJson("/customers/orders/{$order->id}/items/preview", [
            'items' => [['id' => $line->id, 'product_id' => $product->id, 'quantity' => 3, 'unit_price' => '19.00']],
        ])->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('requires_replan', true)
            ->assertJsonPath('can_save_direct', false)
            ->assertJsonCount(1, 'issues');
        $this->assertDatabaseHas('order_items', ['id' => $line->id, 'quantity' => 1]);
    }

    public function test_shipped_order_amendment_preview_does_not_authorize_historical_rewrite(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $product = Product::create(['woocommerce_product_id' => 9034, 'sku' => 'SHIP-PREV', 'name' => 'S', 'product_type' => 'simple']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'PREVIEW-SHIPPED',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '19.00']],
        ]))->assertRedirect();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $line = $order->items->first();
        $order->forceFill(['order_status' => 'shipped'])->save();
        $this->actingAs($user)->postJson("/customers/orders/{$order->id}/items/preview", [
            'items' => [['id' => $line->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => '19.00']],
        ])->assertOk()
            ->assertJsonPath('can_save_direct', false)
            ->assertJsonPath('requires_replan', false);
        $this->assertDatabaseHas('order_items', ['id' => $line->id, 'quantity' => 1]);
    }

    public function test_amendment_preview_requires_edit_permission_and_cannot_address_foreign_item(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $restricted = $this->userWith(['orders.view']);
        $product = Product::create(['woocommerce_product_id' => 9035, 'sku' => 'FOREIGN-PREV', 'name' => 'F', 'product_type' => 'simple']);
        foreach (['PRV1', 'PRV2'] as $key) {
            $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
                'external_order_number' => $key,
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '5.00']],
            ]))->assertRedirect();
        }
        $orders = Order::with('items')->latest('id')->limit(2)->get();
        $order = $orders[0];
        $foreignLine = $orders[1]->items->first();
        $body = ['items' => [['id' => $foreignLine->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => '5.00']]];
        $this->actingAs($restricted)->postJson("/customers/orders/{$order->id}/items/preview", $body)->assertForbidden();
        $response = $this->actingAs($user)->postJson("/customers/orders/{$order->id}/items/preview", $body);
        $this->assertSame(422, $response->getStatusCode(), 'Response: '.substr($response->getContent(), 0, 900));
        $this->assertStringContainsString('items.0.id', $response->getContent());
    }

    public function test_manual_item_amendment_cannot_change_financially_posted_order(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $original = Product::create(['woocommerce_product_id' => 9411, 'sku' => 'POSTED-A', 'name' => 'A', 'product_type' => 'simple']);
        $replacement = Product::create(['woocommerce_product_id' => 9412, 'sku' => 'POSTED-B', 'name' => 'B', 'product_type' => 'simple']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'FINANCE-GUARD-001',
            'items' => [['product_id' => $original->id, 'quantity' => 1, 'unit_price' => '79.00']],
        ]))->assertRedirect();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $line = $order->items->first();
        $order->financials()->create([
            'currency' => 'USD', 'merchandise_list_amount' => '79.00',
            'amount_paid' => '79.00', 'revenue_provenance' => 'confirmed_manual',
        ]);
        $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'items' => [['id' => $line->id, 'product_id' => $replacement->id,
                'quantity' => 1, 'unit_price' => '79.00']],
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseHas('order_items', [
            'id' => $line->id, 'product_id' => $original->id, 'sku' => 'POSTED-A',
        ]);
        $this->assertDatabaseHas('order_financials', [
            'order_id' => $order->id, 'amount_paid' => '79.00',
        ]);
    }

    public function test_manual_order_with_woocommerce_reference_cannot_bypass_external_guard(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $original = Product::create(['woocommerce_product_id' => 9413, 'sku' => 'WLINK-A', 'name' => 'A', 'product_type' => 'simple']);
        $replacement = Product::create(['woocommerce_product_id' => 9414, 'sku' => 'WLINK-B', 'name' => 'B', 'product_type' => 'simple']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload([
            'external_order_number' => 'WLINK-GUARD-001',
            'items' => [['product_id' => $original->id, 'quantity' => 1, 'unit_price' => '79.00']],
        ]))->assertRedirect();
        $order = Order::with('items')->latest('id')->firstOrFail();
        $line = $order->items->first();
        $order->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'primary', 'reference' => 'woo-9413',
        ]);
        $this->actingAs($user)->patch("/customers/orders/{$order->id}", [
            'items' => [['id' => $line->id, 'product_id' => $replacement->id,
                'quantity' => 2, 'unit_price' => '79.00']],
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseHas('order_items', [
            'id' => $line->id, 'product_id' => $original->id, 'quantity' => 1,
        ]);
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
