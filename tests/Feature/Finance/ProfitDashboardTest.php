<?php

namespace Tests\Feature\Finance;

use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ProfitDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_usd_kpis_exclude_incomplete_estimated_historical_and_fx_pending_orders(): void
    {
        $user = $this->userWith(['accounting.view', 'analysis.view', 'orders.create']);
        $usd = $this->createOrder($user, '2026-09-01', 'DASH-USD', [['sku' => 'USD-1', 'price' => '100.00']]);
        $this->confirm($user, $usd, '100.00', '1.00', 'USD');

        $this->createOrder($user, '2026-09-02', 'DASH-INCOMPLETE', [['sku' => 'INC-1', 'price' => '20.00']]);

        $estimated = $this->createOrder($user, '2026-09-03', 'DASH-ESTIMATED', [['sku' => 'EST-1', 'price' => '50.00']]);
        $this->confirm($user, $estimated, '50.00', '1.00', 'USD');
        $estimated->costEntries()->where('cost_type', 'product_cost')->update(['status' => 'estimated', 'provenance' => 'estimated_reference']);

        $historical = $this->createOrder($user, '2026-09-04', 'DASH-HISTORICAL', [['sku' => 'HIST-1', 'price' => '60.00']]);
        $this->confirm($user, $historical, '60.00', '1.00', 'USD');
        $historical->externalReferences()->create(['provider' => 'historical_logistics', 'reference' => 'HIST-1']);

        $eurPending = $this->createOrder($user, '2026-09-05', 'DASH-EUR-PENDING', [['sku' => 'EUR-P', 'price' => '100.00']], currency: 'EUR');
        $this->confirm($user, $eurPending, '100.00', '1.00', 'EUR');

        $eurReady = $this->createOrder($user, '2026-09-06', 'DASH-EUR-READY', [['sku' => 'EUR-R', 'price' => '100.00']], currency: 'EUR');
        $this->confirm($user, $eurReady, '100.00', '1.00', 'EUR');
        $eurReady->financials()->update(['fx_rate' => '1.10000000', 'fx_rate_date' => '2026-09-06', 'fx_rate_source' => 'transaction_test']);

        $this->actingAs($user)->get('/accounting?date_from=2026-09-01&date_to=2026-09-30')->assertInertia(fn (Assert $page) => $page
            ->component('Finance/ProfitDashboard')
            ->where('data_health.eligible_operational_orders', 5)
            ->where('data_health.confirmed_profit_orders', 3)
            ->where('data_health.incomplete_orders', 2)
            ->where('data_health.fx_pending_orders', 1)
            ->where('kpis.confirmed_revenue', '210.00')
            ->where('kpis.confirmed_cost', '21.00')
            ->where('kpis.confirmed_profit', '189.00')
            ->where('kpis.confirmed_loss_orders', 0)
            ->where('trend', fn ($rows): bool => count($rows) === 2));
    }

    public function test_filters_and_aggregate_rows_drill_into_exact_confirmed_fx_ready_detail_scope(): void
    {
        $user = $this->userWith(['accounting.view', 'analysis.view', 'orders.create']);
        $alpha = $this->createOrder($user, '2026-09-01', 'DASH-ALPHA', [['sku' => 'ALPHA', 'name' => 'Alpha Product', 'price' => '50.00']], country: 'US', source: 'website');
        $this->confirm($user, $alpha, '50.00', '1.00', 'USD');
        $beta = $this->createOrder($user, '2026-09-02', 'DASH-BETA', [['sku' => 'BETA', 'name' => 'Beta Product', 'price' => '50.00']], country: 'CA');
        $this->confirm($user, $beta, '50.00', '1.00', 'USD');

        $response = $this->actingAs($user)->get('/accounting?date_from=2026-09-01&date_to=2026-09-30&country=US&source_type=website&product=ALPHA');
        $drilldown = null;
        $response->assertInertia(function (Assert $page) use (&$drilldown): void {
            $page->where('data_health.eligible_operational_orders', 1)
                ->where('tables.country.0.label', 'US')
                ->where('tables.source.0.label', 'website')
                ->where('tables.product.0.label', 'ALPHA')
                ->where('tables.product.0.drilldown_url', function (string $url) use (&$drilldown): bool {
                    $drilldown = $url;
                    return str_contains($url, 'product=ALPHA')
                        && str_contains($url, 'profit_status=confirmed')
                        && str_contains($url, 'fx_status=aggregate_ready');
                });
        });

        self::assertNotNull($drilldown);
        $this->actingAs($user)->get($drilldown)->assertInertia(fn (Assert $page) => $page
            ->component('Finance/OrderAnalysis')
            ->has('orders', 1)
            ->where('orders.0.external_order_number', 'DASH-ALPHA')
            ->where('filters.product', 'ALPHA')
            ->where('filters.profit_status', 'confirmed')
            ->where('filters.fx_status', 'aggregate_ready'));
    }

    public function test_order_profit_list_separates_strict_final_completeness_from_p82_required_cost_readiness(): void
    {
        $user = $this->userWith(['accounting.view', 'analysis.view', 'orders.create']);
        $order = $this->createOrder($user, '2026-09-19', 'SEMANTICS-P82', [['sku' => 'SEM-P82', 'price' => '100.00']]);
        $required = array_fill_keys([
            'product_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'payment_fee', 'customs_tax',
        ], '5.00');

        $this->actingAs($user)->put('/accounting/order-costs/'.$order->id, [
            'amount_paid' => '100.00',
            'refund_amount' => '0.00',
            'currency' => 'USD',
            ...$required,
        ])->assertRedirect();

        self::assertSame('INCOMPLETE', $order->financials()->firstOrFail()->fresh()->completeness_status);
        self::assertFalse($order->costEntries()->where('cost_type', 'warehouse_cost')->exists());
        self::assertFalse($order->costEntries()->where('cost_type', 'kol_commission')->exists());
        self::assertFalse($order->costEntries()->where('cost_type', 'refund_replacement_loss')->exists());
        self::assertFalse($order->costEntries()->where('cost_type', 'other_variable_cost')->exists());

        $this->actingAs($user)->get('/accounting/order-costs?date_from=2026-09-19&date_to=2026-09-19')->assertInertia(fn (Assert $page) => $page
            ->component('Finance/OrderAnalysis')
            ->has('orders', 1)
            ->where('orders.0.external_order_number', 'SEMANTICS-P82')
            ->where('orders.0.summary.finance_status', 'INCOMPLETE')
            ->where('orders.0.summary.p82_estimated_profit_base_ready', true)
            ->where('orders.0.summary.p82_required_cost_count', 6)
            ->where('orders.0.summary.p82_required_cost_covered_count', 6)
            ->where('orders.0.summary.p82_missing_required_costs', [])
            ->where('orders.0.summary.canonical_estimated_ready', true)
            ->where('orders.0.summary.canonical_estimated_total_cost', '30.00')
            ->where('orders.0.summary.canonical_estimated_cost_breakdown.product_cost', '5.00')
            ->where('orders.0.summary.canonical_estimated_cost_breakdown.logistics_cost', '15.00')
            ->where('orders.0.summary.canonical_estimated_cost_breakdown.other_cost', '10.00')
            ->where('orders.0.summary.canonical_estimated_profit', '70.00')
            ->where('orders.0.summary.fallback_estimated_cost_count', 4)
            ->where('kpis.eligible_orders', 1)
            ->where('kpis.revenue_ready_orders', 1)
            ->where('kpis.estimated_profit_orders', 1)
            ->where('kpis.estimated_profit_percent', 100)
            ->where('kpis.p82_required_cost_ready_orders', 1)
            ->where('kpis.p82_required_cost_percent', 100)
            ->where('kpis.confirmed_profit_orders', 0)
            ->where('kpis.final_profit_percent_of_revenue_ready', 0));
    }

    public function test_confirmed_loss_orders_are_exposed_from_finance_order_profit_only(): void
    {
        $user = $this->userWith(['accounting.view', 'analysis.view', 'orders.create']);
        $loss = $this->createOrder($user, '2026-09-01', 'DASH-LOSS', [['sku' => 'LOSS-1', 'price' => '5.00']]);
        $this->confirm($user, $loss, '5.00', '1.00', 'USD');
        $profit = $this->createOrder($user, '2026-09-02', 'DASH-PROFIT', [['sku' => 'PROFIT-1', 'price' => '100.00']]);
        $this->confirm($user, $profit, '100.00', '1.00', 'USD');

        $this->actingAs($user)->get('/accounting/order-costs?profit_status=loss')->assertInertia(fn (Assert $page) => $page
            ->component('Finance/OrderAnalysis')
            ->has('orders', 1)
            ->where('orders.0.external_order_number', 'DASH-LOSS')
            ->where('filters.profit_status', 'loss'));
    }

    public function test_confirmed_loss_kpi_delegates_to_confirmed_loss_reader_contract(): void
    {
        $user = $this->userWith(['accounting.view', 'analysis.view', 'orders.create']);
        $order = $this->createOrder($user, '2026-09-01', 'DASH-LOSS-AUTHORITY', [['sku' => 'LOSS-AUTH', 'price' => '100.00']]);
        $this->confirm($user, $order, '100.00', '1.00', 'USD');

        $reader = new class implements ConfirmedLossReader {
            public int $calls = 0;
            public function applyConfirmedLossScope(Builder $query): Builder { return $query; }
            public function isConfirmedLoss(Model $order): bool { $this->calls++; return true; }
        };
        $this->app->instance(ConfirmedLossReader::class, $reader);

        $this->actingAs($user)->get('/accounting?date_from=2026-09-01&date_to=2026-09-30')->assertInertia(fn (Assert $page) => $page
            ->where('kpis.confirmed_loss_orders', 1));
        self::assertSame(1, $reader->calls);
    }

    public function test_product_allocation_uses_line_revenue_weights_and_integer_cent_conservation_with_equal_fallback(): void
    {
        $user = $this->userWith(['accounting.view', 'analysis.view', 'orders.create']);
        $weighted = $this->createOrder($user, '2026-09-01', 'DASH-WEIGHTED', [
            ['sku' => 'WEIGHT-A', 'price' => '1.00'],
            ['sku' => 'WEIGHT-B', 'price' => '2.00'],
        ]);
        $this->confirm($user, $weighted, '100.00', '1.00', 'USD');

        $this->actingAs($user)->get('/accounting?date_from=2026-09-01&date_to=2026-09-01')->assertInertia(fn (Assert $page) => $page
            ->where('tables.product', function ($rows): bool {
                $byLabel = collect($rows)->keyBy('label');
                return $byLabel['WEIGHT-A']['revenue_usd'] === '33.33'
                    && $byLabel['WEIGHT-B']['revenue_usd'] === '66.67'
                    && $byLabel['WEIGHT-A']['cost_usd'] === '3.33'
                    && $byLabel['WEIGHT-B']['cost_usd'] === '6.67'
                    && bcadd($byLabel['WEIGHT-A']['profit_usd'], $byLabel['WEIGHT-B']['profit_usd'], 2) === '90.00';
            }));

        $fallback = $this->createOrder($user, '2026-09-02', 'DASH-FALLBACK', [
            ['sku' => 'ZERO-A', 'price' => '0.00'],
            ['sku' => 'ZERO-B', 'price' => '0.00'],
        ]);
        $this->confirm($user, $fallback, '0.01', '0.00', 'USD');

        $this->actingAs($user)->get('/accounting?date_from=2026-09-02&date_to=2026-09-02&product=ZERO')->assertInertia(fn (Assert $page) => $page
            ->where('tables.product', function ($rows): bool {
                $byLabel = collect($rows)->keyBy('label');
                return $byLabel['ZERO-A']['revenue_usd'] === '0.01'
                    && $byLabel['ZERO-B']['revenue_usd'] === '0.00'
                    && bcadd($byLabel['ZERO-A']['revenue_usd'], $byLabel['ZERO-B']['revenue_usd'], 2) === '0.01';
            }));
    }

    private function createOrder(
        User $user,
        string $date,
        string $external,
        array $items,
        string $country = 'US',
        string $currency = 'USD',
        string $source = 'manual',
    ): Order {
        $payloadItems = [];
        foreach ($items as $item) {
            $product = Product::firstOrCreate(
                ['sku' => $item['sku']],
                ['name' => $item['name'] ?? $item['sku'], 'product_type' => 'simple'],
            );
            $payloadItems[] = [
                'product_id' => $product->id,
                'product_summary' => $item['name'] ?? $item['sku'],
                'quantity' => 1,
                'unit_price' => $item['price'],
            ];
        }

        $this->actingAs($user)->post('/customers/orders', [
            'external_order_number' => $external,
            'order_date' => $date,
            'currency' => $currency,
            'recipient_name' => 'Dashboard Test',
            'phone' => '1',
            'country' => $country,
            'province_state' => 'CA',
            'city' => 'Los Angeles',
            'address_line_1' => '1 Test Way',
            'postal_code' => '90001',
            'items' => $payloadItems,
        ])->assertRedirect();

        $order = Order::query()->where('external_order_number', $external)->firstOrFail();
        if ($source !== 'manual') {
            $order->forceFill(['source_type' => $source])->save();
        }
        return $order->fresh(['address', 'financials', 'costEntries', 'items']);
    }

    private function confirm(User $user, Order $order, string $revenue, string $costAmount, string $currency): void
    {
        $costs = array_fill_keys([
            'product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost',
            'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
        ], $costAmount);
        $this->actingAs($user)->put('/accounting/order-costs/'.$order->id, [
            'amount_paid' => $revenue,
            'refund_amount' => '0.00',
            'currency' => $currency,
            ...$costs,
        ])->assertRedirect();
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'dashboard-'.uniqid(), 'label' => 'Dashboard Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->create(['key' => $permission, 'label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
