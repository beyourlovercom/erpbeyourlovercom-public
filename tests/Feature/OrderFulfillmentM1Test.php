<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Application\OrderKindBackfill;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrderFulfillmentM1Test extends TestCase
{
    use RefreshDatabase;

    public function test_customer_create_defaults_and_preserves_the_business_order_number(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->get('/customers/orders/create')->assertInertia(
            fn ($page) => $page->where('defaultOrderKind', 'customer')
        );

        $this->actingAs($user)->post('/customers/orders', $this->payload('  BIZ-0001  '))->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame('customer', $order->order_kind->value);
        $this->assertSame('007erp-'.$order->order_date->format('md').'-c1', $order->erp_order_id);
        $this->assertSame('BIZ-0001', $order->external_order_number, 'operator business number must be preserved exactly, only trimmed');
        $this->assertNotSame($order->external_order_number, $order->internal_order_number, 'the ERP technical number must never overwrite the business number');
    }

    public function test_kol_create_defaults_to_kol_fulfillment(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->get('/kol/orders/create')->assertInertia(
            fn ($page) => $page->where('defaultOrderKind', 'kol_fulfillment')
        );

        $this->actingAs($user)->post('/kol/orders', $this->payload('KOL-BIZ-0001', 'kol_fulfillment'))->assertRedirect('/kol/orders/1');
        $order = Order::query()->firstOrFail();
        $this->assertSame('kol_fulfillment', $order->order_kind->value);
    }

    public function test_duplicate_business_order_number_is_rejected(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('DUP-001'))->assertRedirect();
        $this->actingAs($user)->post('/customers/orders', $this->payload('DUP-001'))
            ->assertSessionHasErrors('external_order_number');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_generic_order_detail_url_redirects_to_canonical_customer_and_kol_surfaces(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('CUS-GENERIC'))->assertRedirect();
        $this->actingAs($user)->post('/kol/orders', $this->payload('KOL-GENERIC', 'kol_fulfillment'))->assertRedirect();

        $customerOrder = Order::query()->where('order_kind', 'customer')->firstOrFail();
        $kolOrder = Order::query()->where('order_kind', 'kol_fulfillment')->firstOrFail();

        $this->actingAs($user)->get('/orders/'.$customerOrder->id)
            ->assertRedirect('/customers/orders/'.$customerOrder->id);
        $this->actingAs($user)->get('/orders/'.$kolOrder->id)
            ->assertRedirect('/kol/orders/'.$kolOrder->id);
    }

    public function test_generic_order_detail_url_for_fulfillment_child_redirects_to_root_order(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('CUS-SPLIT'))->assertRedirect();
        $root = Order::query()->firstOrFail();
        $child = Order::query()->create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'A',
            'internal_order_number' => 'FULF-'.$root->id.'-A',
            'erp_order_id' => $root->erp_order_id.'A',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'external_order_number' => null,
            'order_date' => $root->order_date,
            'owner_id' => $root->owner_id,
            'order_status' => 'pending_shipment',
            'currency' => $root->currency,
        ]);

        $this->actingAs($user)->get('/orders/'.$child->id)
            ->assertRedirect('/customers/orders/'.$root->id);
    }

    public function test_customer_and_kol_lists_are_scoped_server_side_with_no_cross_leak(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('CUS-1'))->assertRedirect();
        $this->actingAs($user)->post('/kol/orders', $this->payload('KOL-1', 'kol_fulfillment'))->assertRedirect();

        $customerOrder = Order::query()->where('order_kind', 'customer')->firstOrFail();
        $kolOrder = Order::query()->where('order_kind', 'kol_fulfillment')->firstOrFail();

        $this->actingAs($user)->get('/customers/orders')->assertInertia(
            fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.external_order_number', 'CUS-1')
        );
        $this->actingAs($user)->get('/kol/orders')->assertInertia(
            fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.external_order_number', 'KOL-1')
        );

        // Direct URL manipulation must not leak the other kind's order detail.
        $this->actingAs($user)->get('/customers/orders/'.$kolOrder->id)->assertNotFound();
        $this->actingAs($user)->get('/kol/orders/'.$customerOrder->id)->assertNotFound();
    }

    public function test_kol_orders_surface_requires_orders_permission_not_the_prototype_placeholder(): void
    {
        $prototypeOnly = $this->userWith(['dashboard.view', 'prototype.view'], 'prototype-only');
        $this->actingAs($prototypeOnly)->get('/kol/orders')->assertForbidden();
        $this->actingAs($prototypeOnly)->post('/kol/orders', $this->payload('X', 'kol_fulfillment'))->assertForbidden();
    }

    public function test_wait_stock_queue_is_shared_across_both_order_kinds_and_labelled(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'logistics.view', 'logistics.operate']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('CUS-2'))->assertRedirect();
        $this->actingAs($user)->post('/kol/orders', $this->payload('KOL-2', 'kol_fulfillment'))->assertRedirect();

        $this->actingAs($user)->get('/logistics/wait-stock')->assertInertia(
            fn ($page) => $page->where('orders.total', 2)
        );
    }

    public function test_order_list_is_paginated_server_side(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        for ($i = 0; $i < 55; $i++) {
            $this->actingAs($user)->post('/customers/orders', $this->payload('PAG-'.$i))->assertRedirect();
        }

        $this->actingAs($user)->get('/customers/orders')->assertInertia(
            fn ($page) => $page->where('orders.total', 55)->where('orders.per_page', 50)->has('orders.data', 50)
        );
    }

    public function test_create_page_pre_generates_a_formatted_external_order_number(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->get('/customers/orders/create')->assertInertia(
            fn ($page) => $page->where('generatedExternalOrderNumber', fn ($value) => (bool) preg_match('/^CUS\d{8}[A-Z0-9]{4}007$/', $value))
        );
        $this->actingAs($user)->get('/kol/orders/create')->assertInertia(
            fn ($page) => $page->where('generatedExternalOrderNumber', fn ($value) => (bool) preg_match('/^KOL\d{8}[A-Z0-9]{4}007$/', $value))
        );
    }

    public function test_operator_can_override_the_pre_generated_number_before_first_save(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('OPERATOR-CHOSEN-001'))->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame('OPERATOR-CHOSEN-001', $order->external_order_number);
    }

    public function test_route_context_forces_order_kind_and_source_type_regardless_of_client_payload(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);

        $payload = $this->payload('FORCED-001', 'kol_fulfillment');
        $payload['source_type'] = 'website';
        $this->actingAs($user)->post('/customers/orders', $payload)->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame('customer', $order->order_kind->value, 'client-supplied order_kind must not cross-create');
        $this->assertSame('manual', $order->source_type->value, 'new manual orders must be stamped source_type=manual');
    }

    public function test_owner_is_forced_to_the_creating_actor_and_cannot_be_reassigned_on_create(): void
    {
        $owner = $this->userWith(['orders.view', 'orders.create']);
        $other = $this->userWith(['orders.view', 'orders.create'], 'other-user');

        $payload = $this->payload('OWNER-001');
        $payload['owner_id'] = $other->id;
        $this->actingAs($owner)->post('/customers/orders', $payload)->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame($owner->id, $order->owner_id, 'owner must be the creator, not a client-supplied value');
    }

    public function test_external_order_number_and_owner_are_immutable_via_the_generic_update_path(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $other = $this->userWith(['orders.view', 'orders.create'], 'other-user-2');
        $this->actingAs($user)->post('/customers/orders', $this->payload('IMMUTABLE-001'))->assertRedirect();
        $order = Order::query()->firstOrFail();

        $this->actingAs($user)->patch('/customers/orders/'.$order->id, [
            'external_order_number' => 'TRIED-TO-CHANGE',
            'owner_id' => $other->id,
            'order_note' => 'updated note',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('IMMUTABLE-001', $order->external_order_number, 'external_order_number must not be editable via the generic update path');
        $this->assertSame($user->id, $order->owner_id, 'owner_id must not be reassignable via the generic update path');
        $this->assertSame('updated note', $order->order_note);
    }

    public function test_non_critical_snapshot_fields_are_optional_and_stored_as_null_not_a_dash(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $product = Product::firstOrCreate(['sku' => 'SYN-1'], ['name' => 'Synthetic Product', 'product_type' => 'simple', 'woocommerce_product_id' => 8001]);
        $payload = [
            'external_order_number' => 'NO-ADDRESS-001',
            'order_date' => '2026-08-24',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Synthetic Product', 'sku' => null, 'quantity' => 1, 'unit_price' => '9.99']],
        ];

        $this->actingAs($user)->post('/customers/orders', $payload)->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertNotSame('—', $order->address?->recipient_name);
        $this->assertNull($order->address?->recipient_name);
    }

    public function test_legacy_manual_other_rows_are_not_guessed_during_order_kind_backfill(): void
    {
        DB::table('users')->insert(['id' => 1, 'name' => 'Legacy Owner', 'email' => 'legacy@example.test', 'password' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $websiteId = DB::table('orders')->insertGetId(['internal_order_number' => 'LEG-WEB-1', 'source_type' => 'website', 'external_order_number' => 'LEG-WEB-1', 'order_date' => now(), 'owner_id' => 1, 'order_status' => 'pending_shipment', 'created_at' => now(), 'updated_at' => now()]);
        $kolId = DB::table('orders')->insertGetId(['internal_order_number' => 'LEG-KOL-1', 'source_type' => 'kol', 'external_order_number' => 'LEG-KOL-1', 'order_date' => now(), 'owner_id' => 1, 'order_status' => 'pending_shipment', 'created_at' => now(), 'updated_at' => now()]);
        $manualId = DB::table('orders')->insertGetId(['internal_order_number' => 'LEG-MAN-1', 'source_type' => 'manual_other', 'external_order_number' => 'LEG-MAN-1', 'order_date' => now(), 'owner_id' => 1, 'order_status' => 'pending_shipment', 'created_at' => now(), 'updated_at' => now()]);

        $result = OrderKindBackfill::apply();

        $this->assertSame('customer', DB::table('orders')->where('id', $websiteId)->value('order_kind'));
        $this->assertSame('kol_fulfillment', DB::table('orders')->where('id', $kolId)->value('order_kind'));
        $this->assertNull(DB::table('orders')->where('id', $manualId)->value('order_kind'), 'ambiguous manual_other legacy rows must not be guessed');
        $this->assertContains($manualId, $result['unclassified']);
    }

    public function test_customer_order_detail_projects_split_fulfillments_and_parent_aggregate_status(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);
        $this->actingAs($user)->post('/customers/orders', $this->payload('SPLIT-PROJECTION-001'))->assertRedirect();
        $root = Order::query()->with(['items', 'address'])->firstOrFail();
        $sourceItem = $root->items->firstOrFail();

        $west = Warehouse::query()->create(['slug' => 'uat-west', 'code' => 'UAT-WEST', 'name' => '美西 MorePlus 仓', 'country_code' => 'US']);
        $factory = Warehouse::query()->create(['slug' => 'uat-factory', 'code' => 'UAT-FACTORY', 'name' => '国内工厂', 'country_code' => 'CN']);

        $makeChild = function (string $suffix, Warehouse $warehouse, string $shipmentStatus, ?string $tracking, string $carrier) use ($root, $sourceItem): Order {
            $child = Order::query()->create([
                'customer_id' => $root->customer_id,
                'fulfillment_parent_id' => $root->id,
                'fulfillment_suffix' => $suffix,
                'internal_order_number' => 'FULF-'.$root->id.'-'.$suffix,
                'erp_order_id' => $root->erp_order_id.$suffix,
                'source_type' => 'manual_other',
                'order_kind' => 'fulfillment_derived',
                'external_order_number' => null,
                'order_date' => $root->order_date,
                'order_note' => $root->order_note,
                'owner_id' => $root->owner_id,
                'order_status' => 'pending_shipment',
                'currency' => $root->currency,
            ]);
            $child->items()->create([
                'product_id' => $sourceItem->product_id,
                'product_summary' => $sourceItem->product_summary,
                'sku' => $sourceItem->sku.'-'.$suffix,
                'source_sku' => $sourceItem->sku,
                'resolved_fulfillment_sku' => $sourceItem->sku.'-'.$suffix,
                'quantity' => 1,
                'unit_price' => $sourceItem->unit_price,
                'line_revenue' => $sourceItem->line_revenue,
            ]);
            $child->shipment()->create([
                'warehouse_id' => $warehouse->id,
                'shipment_status' => $shipmentStatus,
                'carrier' => $carrier,
                'service' => 'standard',
                'tracking_number' => $tracking,
                'shipped_at' => $tracking ? now() : null,
            ]);

            return $child;
        };

        $makeChild('A', $west, 'shipped', 'TRACK-A', 'USPS');
        $makeChild('B', $factory, 'pending', null, '4PX');

        $this->actingAs($user)->get('/customers/orders/'.$root->id)->assertInertia(
            fn ($page) => $page
                ->component('Orders/Show')
                ->where('order.fulfillment.is_split', true)
                ->where('order.fulfillment.aggregate_status', 'partially_shipped')
                ->where('order.fulfillment.aggregate_status_label', '部分发货')
                ->where('order.fulfillment.segment_count', 2)
                ->where('order.fulfillment.fulfilled_count', 1)
                ->where('order.fulfillment.segments.0.suffix', 'A')
                ->where('order.fulfillment.segments.0.shipment.warehouse_name', '美西 MorePlus 仓')
                ->where('order.fulfillment.segments.0.shipment.tracking_number', 'TRACK-A')
                ->where('order.fulfillment.segments.1.suffix', 'B')
                ->where('order.fulfillment.segments.1.shipment.warehouse_name', '国内工厂')
                ->where('order.fulfillment.segments.1.shipment.status_label', '待发货')
                ->where('allocationUi.status', 'submitted')
                ->where('allocationUi.can_operate', false)
        );
    }

    private function payload(string $externalOrderNumber, string $orderKind = 'customer'): array
    {
        $product = Product::firstOrCreate(['sku' => 'SYN-1'], ['name' => 'Synthetic Product', 'product_type' => 'simple', 'woocommerce_product_id' => 9001]);

        return [
            'order_kind' => $orderKind,
            'source_type' => 'website', 'external_order_number' => $externalOrderNumber, 'order_date' => '2026-08-24',
            'recipient_name' => 'Synthetic Recipient', 'phone' => '+1 555 0100', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => '100 Synthetic Street',
            'postal_code' => '90001', 'items' => [
                ['product_id' => $product->id, 'product_summary' => 'Synthetic Product', 'sku' => 'SYN-1', 'quantity' => 1, 'unit_price' => '19.99'],
            ],
        ];
    }

    private function userWith(array $permissions, ?string $roleKey = null): User
    {
        $role = Role::create(['key' => $roleKey ?? 'm1-'.uniqid(), 'label' => 'M1 Test Role']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
