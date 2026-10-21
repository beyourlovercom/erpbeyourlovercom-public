<?php

namespace Tests\Feature\Search;

use App\Models\Permission;
use App\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GlobalSearchV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_use_global_search(): void
    {
        $this->getJson('/search/global?q=VA617A')->assertRedirect('/login');
    }

    public function test_search_respects_provider_permissions(): void
    {
        $product = Product::query()->create(['sku' => 'VA617A', 'name' => 'VA617A Product', 'is_active' => true]);
        $order = Order::query()->create([
            'erp_order_id' => '007-0909-c40405', 'internal_order_number' => 'CUS-20260909-ONE001', 'external_order_number' => '40405',
            'source_type' => 'woocommerce', 'order_kind' => 'customer', 'order_status' => 'shipped',
            'order_date' => '2026-09-09', 'currency' => 'USD',
        ]);

        $viewer = $this->userWithPermissions(['products.view']);
        $response = $this->actingAs($viewer)->getJson('/search/global?q=VA617A')->assertOk();

        $response->assertJsonPath('query', 'VA617A')
            ->assertJsonPath('groups.0.key', 'products')
            ->assertJsonPath('groups.0.items.0.href', '/products/'.$product->id);

        self::assertSame(['products'], collect($response->json('groups'))->pluck('key')->all());

        $orderViewer = $this->userWithPermissions(['orders.view']);
        $this->actingAs($orderViewer)->getJson('/search/global?q=40405')
            ->assertOk()
            ->assertJsonPath('groups.0.key', 'orders')
            ->assertJsonPath('groups.0.items.0.href', '/orders/'.$order->id);
    }

    public function test_product_exact_alias_order_identity_tracking_and_inventory_context_are_searchable(): void
    {
        $product = Product::query()->create([
            'sku' => 'VA617A', 'name' => 'Main Product', 'gtin' => '0123456789012',
            'woocommerce_product_id' => 9876, 'is_active' => true,
        ]);
        ProductSkuAlias::query()->create([
            'product_id' => $product->id, 'alias_sku' => 'VA617-OLD', 'match_key' => 'VA617OLD',
            'source_type' => 'manual',
        ]);

        $warehouse = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        WarehouseInventory::query()->create([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'quantity_on_hand' => 12, 'quantity_reserved' => 2,
        ]);

        $order = Order::query()->create([
            'erp_order_id' => '007-0909-c40405', 'internal_order_number' => 'CUS-20260909-ABC123',
            'external_order_number' => '40405', 'source_type' => 'woocommerce',
            'order_kind' => 'customer', 'order_status' => 'shipped', 'order_date' => '2026-09-09', 'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'www',
            'reference' => '40405-MF414A',
        ]);
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped', 'carrier' => 'USPS', 'tracking_number' => '9400111899223856928499',
        ]);

        $user = $this->userWithPermissions(['products.view', 'orders.view', 'inventory.view']);

        $alias = $this->actingAs($user)->getJson('/search/global?q=VA617-OLD')->assertOk();
        self::assertTrue(collect($alias->json('groups'))->firstWhere('key', 'products')['items'][0]['href'] === '/products/'.$product->id);

        $tracking = $this->actingAs($user)->getJson('/search/global?q=9400111899223856928499')->assertOk();
        self::assertSame('/orders/'.$order->id, collect($tracking->json('groups'))->firstWhere('key', 'orders')['items'][0]['href']);

        $inventory = $this->actingAs($user)->getJson('/search/global?q=VA617A')->assertOk();
        $inventoryGroup = collect($inventory->json('groups'))->firstWhere('key', 'inventory');
        self::assertStringContainsString('/inventory/products?search=VA617A', $inventoryGroup['items'][0]['href']);
        self::assertStringContainsString('可用 10', $inventoryGroup['items'][0]['subtitle']);

        $dbid = $this->actingAs($user)->getJson('/search/global?q=dbid:'.$order->id)->assertOk();
        self::assertSame('/orders/'.$order->id, collect($dbid->json('groups'))->firstWhere('key', 'orders')['items'][0]['href']);

        $scopedOrders = app(OrderService::class)->list(['search' => '9400111899223856928499'], 20);
        self::assertTrue($scopedOrders->getCollection()->contains(fn (Order $row): bool => $row->id === $order->id));

        $scopedProducts = app(ProductService::class)->list(['search' => 'VA617-OLD'], 20);
        self::assertTrue($scopedProducts->getCollection()->contains(fn ($row): bool => $row->id === $product->id));

        $scopedInventory = app(InventoryService::class)->productInventory(['search' => 'VA617-OLD']);
        self::assertTrue($scopedInventory->getCollection()->contains(fn ($row): bool => $row->product_id === $product->id));
    }

    public function test_short_queries_are_bounded_without_provider_results(): void
    {
        $user = $this->userWithPermissions(['products.view', 'orders.view', 'inventory.view']);

        $this->actingAs($user)->getJson('/search/global?q=V')
            ->assertOk()
            ->assertJsonPath('min_query_length', 2)
            ->assertJsonCount(0, 'groups');
    }

    private function userWithPermissions(array $keys): User
    {
        $user = User::factory()->create(['permission_mode' => 'role']);
        $role = Role::query()->create(['key' => 'search-test-'.uniqid(), 'label' => 'Search Test']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }
}
