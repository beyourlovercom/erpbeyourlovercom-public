<?php

namespace Tests\Feature\ProductionStability;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LogisticsWaitStockQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_SIZE = 50;

    private const PRODUCTION_QUERY_BUDGET = 100;

    public function test_authenticated_wait_stock_page_stays_within_production_query_budget_at_full_page_size(): void
    {
        $user = $this->logisticsViewer();
        $product = Product::query()->create([
            'woocommerce_product_id' => 990001,
            'source_type' => 'woocommerce',
            'product_type' => 'simple',
            'sku' => 'STABILITY-P4-SKU',
            'name' => 'Stability P4 Product',
            'is_active' => true,
            'weight_kg' => '0.1000',
            'shipping_weight_kg' => '0.1000',
            'length_cm' => '10.00',
            'width_cm' => '10.00',
            'height_cm' => '10.00',
        ]);

        for ($index = 1; $index <= self::PAGE_SIZE; $index++) {
            $order = Order::query()->create([
                'internal_order_number' => sprintf('P4-%03d', $index),
                'erp_order_id' => sprintf('007P4%04d', $index),
                'source_type' => 'manual',
                'order_kind' => 'customer',
                'external_order_number' => sprintf('EXT-P4-%03d', $index),
                'order_date' => '2026-09-08',
                'owner_id' => $user->id,
                'order_status' => 'pending_shipment',
                'currency' => 'USD',
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'product_summary' => $product->name,
                'sku' => $product->sku,
                'quantity' => 1,
                'unit_price' => '10.00',
                'line_revenue' => '10.00',
            ]);
        }

        $this->withoutVite();
        $this->actingAs($user);
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->get('/logistics/wait-stock');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Logistics/FulfillmentAllocation', false)
            ->where('orders.per_page', self::PAGE_SIZE)
            ->has('orders.data', self::PAGE_SIZE));
        $this->assertLessThanOrEqual(
            self::PRODUCTION_QUERY_BUDGET,
            $queryCount,
            "Authenticated /logistics/wait-stock exceeded the Production query budget: {$queryCount}",
        );
    }

    private function logisticsViewer(): User
    {
        $role = Role::query()->create(['key' => 'stability-p4-logistics', 'label' => 'Stability P4 Logistics']);
        foreach (['logistics.view', 'orders.view'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
