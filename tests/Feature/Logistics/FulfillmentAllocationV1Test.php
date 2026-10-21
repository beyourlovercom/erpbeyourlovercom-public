<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FulfillmentAllocationV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_wait_stock_page_is_vita_assisted_allocation_surface_and_excludes_007(): void
    {
        [$user, $order] = $this->scenario(1);
        self::assertTrue($user->fresh('roles.permissions')->can('logistics.view'));

        $this->actingAs($user)->get('/logistics/wait-stock')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.allocation_decision.status', 'manual_review')
                ->where('orders.data.0.allocation_decision.policy.system_is_advisory', true)
                ->where('orders.data.0.allocation_decision.policy.human_confirmation_required', true)
                ->where('warehouses', function ($rows): bool {
                    $codes = collect($rows)->pluck('code')->all();

                    return ! in_array('007', $codes, true)
                        && in_array('FACTORY', $codes, true)
                        && collect($codes)->diff(['FACTORY', 'US-NY', 'US-LA', 'US-SM', 'DE', 'CA'])->isEmpty();
                })
        );
    }

    public function test_manual_split_materializes_a_b_derived_orders_and_downstream_logistics_only_sees_children(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();

        $this->actingAs($user)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual', [
            'assignments' => [
                ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
                ['order_item_id' => $items[1]->id, 'source_code' => 'FACTORY'],
            ],
            'decision_note' => '人工确认拆分更适合本单',
        ])->assertOk()->assertJsonPath('decision_source', 'manual');

        $allocation = FulfillmentAllocation::query()->where('order_id', $order->id)->firstOrFail();
        self::assertSame('submitted', $allocation->status);
        self::assertSame('manual', $allocation->decision_source);
        self::assertTrue((bool) ($allocation->plan_snapshot['is_split'] ?? false));
        self::assertSame(2, $allocation->plan_snapshot['shipment_count']);
        self::assertSame(['FACTORY', 'US-LA'], collect($allocation->plan_snapshot['segments'])->pluck('source_code')->sort()->values()->all());
        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);

        $children = $order->fresh()->fulfillmentChildren()->with(['items', 'shipment.warehouse'])->orderBy('fulfillment_suffix')->get();
        self::assertCount(2, $children);
        self::assertSame([$order->erp_order_id.'A', $order->erp_order_id.'B'], $children->pluck('erp_order_id')->all());
        self::assertSame(['A', 'B'], $children->pluck('fulfillment_suffix')->all());
        self::assertSame(['fulfillment_derived', 'fulfillment_derived'], $children->map(fn ($child) => $child->order_kind?->value)->all());
        self::assertSame(['US-LA', 'FACTORY'], $children->map(fn ($child) => $child->shipment?->warehouse?->code)->all());
        self::assertSame(['USPS', '4PX'], $children->map(fn ($child) => $child->shipment?->carrier)->all());
        self::assertSame(['automatic', 'automatic'], $children->map(fn ($child) => $child->shipment?->carrier_selection_source)->all());
        self::assertSame([$items[0]->sku, $items[1]->sku], $children->map(fn ($child) => $child->items->first()?->sku)->all());
        self::assertSame([$order->erp_order_id.'A', $order->erp_order_id.'B'], collect($allocation->fresh()->plan_snapshot['derived_orders'])->pluck('erp_order_id')->all());

        $this->actingAs($user)->get('/logistics/pending')->assertRedirect('/logistics/wait-stock');
    }

    public function test_split_children_inherit_woo_line_item_identity_from_parent_items(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();
        $items[0]->forceFill(['woocommerce_line_item_id' => 91001])->save();
        $items[1]->forceFill(['woocommerce_line_item_id' => 91002])->save();

        $this->actingAs($user)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual', [
            'assignments' => [
                ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
                ['order_item_id' => $items[1]->id, 'source_code' => 'FACTORY'],
            ],
            'decision_note' => '验证 Woo line item identity 继承',
        ])->assertOk();

        $children = $order->fresh()->fulfillmentChildren()->with('items')->orderBy('fulfillment_suffix')->get();
        self::assertCount(2, $children);
        self::assertSame([91001, 91002], $children->map(fn ($child) => $child->items->first()?->woocommerce_line_item_id)->all());
    }

    public function test_manual_multi_source_materializes_a_b_c_from_three_overseas_sources(): void
    {
        [$user, $order] = $this->scenario(3);
        $items = $order->items()->orderBy('id')->get();

        $allocation = app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
            ['order_item_id' => $items[1]->id, 'source_code' => 'US-NY'],
            ['order_item_id' => $items[2]->id, 'source_code' => 'US-SM'],
        ], 'ABC multi-warehouse fulfillment', $user);

        self::assertSame(3, $allocation->plan_snapshot['shipment_count']);
        self::assertTrue((bool) $allocation->plan_snapshot['is_split']);
        self::assertSame(['US-LA', 'US-NY', 'US-SM'], collect($allocation->plan_snapshot['segments'])->pluck('source_code')->all());

        $children = $order->fresh()->originalFulfillmentChildren()->with(['items', 'shipment.warehouse'])->orderBy('fulfillment_suffix')->get();
        self::assertCount(3, $children);
        self::assertSame(['A', 'B', 'C'], $children->pluck('fulfillment_suffix')->all());
        self::assertSame([
            $order->erp_order_id.'A',
            $order->erp_order_id.'B',
            $order->erp_order_id.'C',
        ], $children->pluck('erp_order_id')->all());
        self::assertSame(['US-LA', 'US-NY', 'US-SM'], $children->map(fn ($child) => $child->shipment?->warehouse?->code)->all());
        self::assertSame(['USPS', 'USPS', 'USPS'], $children->map(fn ($child) => $child->shipment?->carrier)->all());
        self::assertSame(['A', 'B', 'C'], collect($allocation->fresh()->plan_snapshot['derived_orders'])->pluck('suffix')->all());
    }

    public function test_manual_v1_requires_every_order_item_and_forbids_duplicate_item_split(): void
    {
        [$user, $order] = $this->scenario(2);
        $items = $order->items()->orderBy('id')->get();

        $service = app(FulfillmentAllocationSubmissionService::class);
        try {
            $service->submitManual($order, [
                ['order_item_id' => $items[0]->id, 'source_code' => 'FACTORY'],
                ['order_item_id' => $items[0]->id, 'source_code' => 'US-LA'],
            ], null, $user);
            self::fail('Expected duplicate order item allocation to be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('同一个订单商品不能拆数量到多个仓库', $exception->getMessage());
        }

        try {
            $service->submitManual($order, [
                ['order_item_id' => $items[0]->id, 'source_code' => 'FACTORY'],
            ], null, $user);
            self::fail('Expected incomplete order item allocation to be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('必须为订单中的每个 SKU 完成分仓', $exception->getMessage());
        }
    }

    public function test_view_only_user_cannot_submit_allocation(): void
    {
        [, $order] = $this->scenario(1);
        $viewer = $this->userWith(['logistics.view']);
        $item = $order->items()->firstOrFail();

        $this->actingAs($viewer)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual', [
            'assignments' => [['order_item_id' => $item->id, 'source_code' => 'FACTORY']],
        ])->assertForbidden();
    }

    /** @return array{User,Order} */
    private function scenario(int $itemCount): array
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $order = Order::query()->create([
            'internal_order_number' => 'ALLOC-'.uniqid(),
            'erp_order_id' => '007-0911-'.random_int(10000, 99999),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'EXT-'.uniqid(),
            'order_date' => '2026-09-11',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'Vita Allocation UAT',
            'phone' => '10000000000',
            'country' => 'US',
            'province_state' => 'CA',
            'city' => 'Los Angeles',
            'address_line_1' => '123 Test Ave',
            'address_line_2' => null,
            'postal_code' => '90001',
        ]);
        for ($i = 0; $i < $itemCount; $i++) {
            $product = Product::query()->create([
                'sku' => 'ALLOC-SKU-'.$i.'-'.uniqid(),
                'name' => 'Allocation Product '.$i,
                'product_type' => 'simple',
                'source_type' => 'manual',
                'is_active' => true,
            ]);
            $order->items()->create([
                'product_id' => $product->id,
                'product_summary' => $product->name,
                'sku' => $product->sku,
                'quantity' => 1,
                'unit_price' => '19.99',
                'line_revenue' => '19.99',
            ]);
        }

        return [$user, $order->fresh(['items', 'address'])];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'allocation-'.uniqid(), 'label' => 'Allocation Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
