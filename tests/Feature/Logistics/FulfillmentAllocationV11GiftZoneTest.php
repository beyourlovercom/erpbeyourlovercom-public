<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Infrastructure\Models\WarehouseGiftSkuMapping;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FulfillmentAllocationV11GiftZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_wait_stock_exposes_shipping_address_physical_zone_and_gift_options(): void
    {
        [$user, $order, $giftItem, , $mapping] = $this->giftScenario(8);

        $this->actingAs($user)->get('/logistics/wait-stock')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.postal_code', '90001')
                ->where('orders.data.0.province_state', 'CA')
                ->where('orders.data.0.address_line_1', '123 West Test Ave')
                ->where('orders.data.0.us_fulfillment_zone.zone', 'west')
                ->where('orders.data.0.us_fulfillment_zone.zone_label', '美西区')
                ->where('orders.data.0.us_fulfillment_zone.warehouse_priority.0', 'US-SM')
                ->where('orders.data.0.us_fulfillment_zone.warehouse_priority.1', 'US-LA')
                ->has('warehouses', 6)
                ->where('warehouses.2.code', 'US-SM')
                ->where('warehouses.2.name', '美国3世迈仓')
                ->where('orders.data.0.carrier_routes.US-SM.carrier', 'USPS')
                ->where('orders.data.0.carrier_routes.US-SM.automatic', true)
                ->where('orders.data.0.items.0.id', $giftItem->id)
                ->where('orders.data.0.items.0.is_gift', true)
                ->where("orders.data.0.items.0.gift_options_by_warehouse.US-SM.0.mapping_id", $mapping->id)
                ->where("orders.data.0.items.0.gift_options_by_warehouse.US-SM.0.can_fulfill", true)
        );
    }

    public function test_legacy_us_sj_submission_is_normalized_to_us_sm(): void
    {
        [$user, $order, $giftItem, , $mapping] = $this->giftScenario(8);

        $allocation = app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $giftItem->id, 'source_code' => 'US-SJ', 'gift_mapping_id' => $mapping->id],
        ], 'legacy stale browser payload', $user);

        self::assertSame('US-SM', $allocation->manual_assignments[0]['source_code']);
        self::assertSame('US-SM', $order->fresh()->shipment?->warehouse?->code);
    }

    public function test_gift_requires_actual_fulfillment_sku_selection(): void
    {
        [$user, $order, $giftItem] = $this->giftScenario(8);

        $this->expectGiftError('gift 必须选择实际履约礼品 SKU', function () use ($user, $order, $giftItem): void {
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
                ['order_item_id' => $giftItem->id, 'source_code' => 'US-SM'],
            ], null, $user);
        });
    }

    public function test_gift_rejects_mapping_from_another_warehouse(): void
    {
        [$user, $order, $giftItem, $actualGift] = $this->giftScenario(8);
        $morePlus = $this->warehouse('US-LA', '美西 MorePlus');
        WarehouseInventory::query()->create([
            'warehouse_id' => $morePlus->id, 'product_id' => $actualGift->id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0,
        ]);
        $wrong = WarehouseGiftSkuMapping::query()->create([
            'warehouse_id' => $morePlus->id, 'source_sku' => 'gift',
            'fulfillment_product_id' => $actualGift->id, 'priority' => 1, 'is_active' => true,
        ]);

        $this->expectGiftError('所选礼品 SKU 不属于当前发货仓', function () use ($user, $order, $giftItem, $wrong): void {
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
                ['order_item_id' => $giftItem->id, 'source_code' => 'US-SM', 'gift_mapping_id' => $wrong->id],
            ], null, $user);
        });
    }

    public function test_gift_rejects_insufficient_real_sku_inventory(): void
    {
        [$user, $order, $giftItem, , $mapping] = $this->giftScenario(0);

        $this->expectGiftError('所选实际礼品 SKU 在该仓库存不足或库存未知', function () use ($user, $order, $giftItem, $mapping): void {
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
                ['order_item_id' => $giftItem->id, 'source_code' => 'US-SM', 'gift_mapping_id' => $mapping->id],
            ], null, $user);
        });
    }

    public function test_split_derived_order_uses_resolved_gift_sku_while_parent_keeps_source_fact(): void
    {
        [$user, $order, $giftItem, $actualGift, $mapping] = $this->giftScenario(8, true);
        $regular = $order->items()->where('sku', 'MF414A')->firstOrFail();

        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $giftItem->id, 'source_code' => 'US-SM', 'gift_mapping_id' => $mapping->id],
            ['order_item_id' => $regular->id, 'source_code' => 'FACTORY'],
        ], 'gift resolved by Vita', $user);

        $parentGift = $giftItem->fresh();
        self::assertSame('gift', $parentGift->sku);
        self::assertSame('gift', $parentGift->source_sku);
        self::assertSame($actualGift->sku, $parentGift->resolved_fulfillment_sku);

        $children = $order->fresh()->fulfillmentChildren()->with(['items', 'shipment.warehouse'])->get();
        self::assertCount(2, $children);
        $giftChild = $children->first(fn (Order $child): bool => $child->shipment?->warehouse?->code === 'US-SM');
        self::assertNotNull($giftChild);
        $childGift = $giftChild->items->firstOrFail();
        self::assertSame($actualGift->id, $childGift->product_id);
        self::assertSame($actualGift->sku, $childGift->sku);
        self::assertSame('gift', $childGift->source_sku);
        self::assertSame($actualGift->sku, $childGift->resolved_fulfillment_sku);
        self::assertSame('USPS', $giftChild->shipment?->carrier);
        self::assertSame('automatic', $giftChild->shipment?->carrier_selection_source);
        $factoryChild = $children->first(fn (Order $child): bool => $child->shipment?->warehouse?->code === 'FACTORY');
        self::assertNotNull($factoryChild);
        self::assertSame('4PX', $factoryChild->shipment?->carrier);
        self::assertSame('automatic', $factoryChild->shipment?->carrier_selection_source);
    }

    /** @return array{User,Order,\App\Modules\Orders\Infrastructure\Models\OrderItem,Product,WarehouseGiftSkuMapping} */
    private function giftScenario(int $giftStock, bool $withRegular = false): array
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $factory = $this->warehouse('FACTORY', '中国工厂');
        $sm = $this->warehouse('US-SM', '美国3世迈仓');
        $this->warehouse('US-NY', '美东纽约仓');

        $order = Order::query()->create([
            'internal_order_number' => 'GIFT-'.uniqid(), 'erp_order_id' => '007-0914-'.random_int(10000, 99999),
            'source_type' => 'manual', 'order_kind' => 'customer', 'external_order_number' => 'EXT-'.uniqid(),
            'order_date' => '2026-09-14', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'George Gil-Machuca', 'phone' => '10000000000', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => '123 West Test Ave',
            'address_line_2' => 'Apt 2', 'postal_code' => '90001',
        ]);
        $giftSource = Product::query()->create([
            'sku' => 'gift-source-'.uniqid(), 'name' => 'Website Gift Placeholder',
            'product_type' => 'simple', 'source_type' => 'manual', 'is_active' => true,
        ]);
        $giftItem = $order->items()->create([
            'product_id' => $giftSource->id, 'product_summary' => 'Gift', 'sku' => 'gift',
            'quantity' => 1, 'unit_price' => '0.00', 'line_revenue' => '0.00',
        ]);
        if ($withRegular) {
            $regular = Product::query()->create([
                'sku' => 'MF414A', 'name' => 'MF414A', 'product_type' => 'simple', 'source_type' => 'manual', 'is_active' => true,
            ]);
            $order->items()->create([
                'product_id' => $regular->id, 'product_summary' => 'MF414A', 'sku' => 'MF414A',
                'quantity' => 1, 'unit_price' => '19.99', 'line_revenue' => '19.99',
            ]);
        }
        $actualGift = Product::query()->create([
            'sku' => 'GIFT-SM-01-'.uniqid(), 'name' => '世迈实际礼品',
            'product_type' => 'simple', 'source_type' => 'manual', 'is_active' => true,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $sm->id, 'product_id' => $actualGift->id,
            'quantity_on_hand' => $giftStock, 'quantity_reserved' => 0,
        ]);
        $mapping = WarehouseGiftSkuMapping::query()->create([
            'warehouse_id' => $sm->id, 'source_sku' => 'gift', 'fulfillment_product_id' => $actualGift->id,
            'priority' => 1, 'is_active' => true, 'note' => 'V1.1 test mapping',
        ]);
        unset($factory);

        return [$user, $order->fresh(['items', 'address']), $giftItem->fresh(), $actualGift, $mapping];
    }

    private function warehouse(string $code, string $name): Warehouse
    {
        return Warehouse::query()->firstOrCreate(['code' => $code], [
            'slug' => strtolower(str_replace('_', '-', $code)).'-'.uniqid(),
            'name' => $name, 'country_code' => $code === 'FACTORY' ? 'CN' : 'US',
            'is_active' => true, 'priority' => 10,
        ]);
    }

    private function expectGiftError(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected gift resolution to be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function userWith(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'gift-zone-'.uniqid(), 'label' => 'Gift Zone Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
