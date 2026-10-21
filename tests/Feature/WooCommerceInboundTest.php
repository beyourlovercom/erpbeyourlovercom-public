<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuReconciliation;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceInboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop-a',
            'services.woocommerce.timeout' => 5,
        ]);
    }

    public function test_single_import_creates_canonical_order_and_reimport_is_idempotent(): void
    {
        $user = $this->userWith(['sync.view', 'sync.operate']);
        $product = Product::create([
            'woocommerce_product_id' => 5001, 'sku' => 'WOO-5001', 'name' => 'Canonical Widget', 'product_type' => 'simple',
        ]);
        Http::fakeSequence('https://shop.test/wp-json/wc/v3/orders/101')
            ->push($this->wooOrder(101, 'WC-101', 'processing', '19.95', 5001, 'WOO-5001'), 200)
            ->push($this->wooOrder(101, 'WC-101', 'processing', '21.50', 5001, 'WOO-5001', note: 'Updated'), 200);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 101])->assertSessionHasNoErrors();

        $order = Order::with(['address', 'items', 'externalReferences'])->firstOrFail();
        $this->assertSame('woocommerce', $order->source_type->value);
        $this->assertSame('customer', $order->order_kind->value);
        $this->assertSame('WC-101', $order->external_order_number);
        $this->assertSame('007erp-0827-c101', $order->erp_order_id);
        $firstErpOrderId = $order->erp_order_id;
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('DE', $order->address->country);
        $this->assertSame($product->id, $order->items->first()->product_id);
        $this->assertSame(100101, $order->items->first()->woocommerce_line_item_id);
        $this->assertSame('19.95', $order->items->first()->unit_price);
        $this->assertDatabaseHas('order_external_references', [
            'order_id' => $order->id, 'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '101', 'provider_modified_at' => '2026-08-27 01:05:03',
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.inbound_created']);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 101])->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderExternalReference::count());
        $order->refresh()->load('items');
        $this->assertSame('Updated', $order->order_note);
        $this->assertSame($firstErpOrderId, $order->erp_order_id);
        $this->assertSame(100101, $order->items->first()->woocommerce_line_item_id);
        $this->assertSame('21.50', $order->items->first()->unit_price);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.inbound_updated']);
    }

    public function test_empty_shipping_phone_falls_back_to_billing_phone(): void
    {
        $user = $this->userWith(['sync.operate']);
        $product = Product::create([
            'woocommerce_product_id' => 5003, 'sku' => 'WOO-5003', 'name' => 'Phone Fallback Widget', 'product_type' => 'simple',
        ]);
        $payload = $this->wooOrder(103, 'WC-103', 'processing', '19.95', 5003, 'WOO-5003');
        $payload['shipping']['phone'] = '';
        $payload['shipping']['email'] = '';
        $payload['shipping']['company'] = '';
        $payload['billing']['phone'] = '+49123456';
        $payload['billing']['email'] = 'ada.billing@example.test';
        $payload['billing']['company'] = 'Analytical Engines Ltd';
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/103' => Http::response($payload, 200)]);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 103])->assertSessionHasNoErrors();

        $order = Order::with('address')->firstOrFail();
        $this->assertSame($product->id, $order->items()->firstOrFail()->product_id);
        $this->assertSame('+49123456', $order->address->phone);
        $this->assertSame('ada.billing@example.test', $order->address->email);
        $this->assertSame('Analytical Engines Ltd', $order->address->company);
    }

    public function test_single_import_preserves_long_woo_address_line_2_snapshot(): void
    {
        $user = $this->userWith(['sync.operate']);
        $product = Product::create([
            'woocommerce_product_id' => 5002, 'sku' => 'WOO-5002', 'name' => 'Long Address Widget', 'product_type' => 'simple',
        ]);
        $addressLine2 = str_repeat('A', 300);
        $payload = $this->wooOrder(102, 'WC-102', 'processing', '19.95', 5002, 'WOO-5002');
        $payload['shipping']['address_2'] = $addressLine2;
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/102' => Http::response($payload, 200)]);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 102])->assertSessionHasNoErrors();

        $order = Order::with('address')->firstOrFail();
        $this->assertSame($product->id, $order->items()->firstOrFail()->product_id);
        $this->assertSame(300, strlen((string) $order->address->address_line_2));
        $this->assertSame($addressLine2, $order->address->address_line_2);
    }

    public function test_unmatched_woo_product_does_not_create_product_master_record(): void
    {
        $user = $this->userWith(['sync.operate']);
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/202' => Http::response(
            $this->wooOrder(202, 'WC-202', 'processing', '9.00', 999999, 'UNKNOWN-SKU'), 200
        )]);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 202])->assertSessionHasNoErrors();
        $this->assertSame(0, Product::count());
        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame('UNKNOWN-SKU', $item->sku);
    }

    public function test_same_provider_reference_can_exist_on_two_connection_keys(): void
    {
        $user = $this->userWith(['sync.operate']);
        Http::fakeSequence('https://shop.test/wp-json/wc/v3/orders/303')
            ->push($this->wooOrder(303, '303'), 200)
            ->push($this->wooOrder(303, '1303'), 200);
        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 303])->assertSessionHasNoErrors();

        config(['services.woocommerce.connection_key' => 'shop-b']);
        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 303])->assertSessionHasNoErrors();

        $this->assertSame(2, Order::count());
        $this->assertSame(2, OrderExternalReference::where('reference', '303')->count());
        $erpIds = Order::query()->orderBy('id')->pluck('erp_order_id')->all();
        $this->assertSame(['007erp-0827-c303', '007erp-0827-c1303'], $erpIds);
        $this->assertCount(2, array_unique($erpIds));
        $this->assertDatabaseHas('order_external_references', ['provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '303']);
        $this->assertDatabaseHas('order_external_references', ['provider' => 'woocommerce', 'connection_key' => 'shop-b', 'reference' => '303']);
    }

    public function test_manual_sync_imports_only_today_from_midnight_without_cursor(): void
    {
        CarbonImmutable::setTestNow('2026-08-27T15:30:00+00:00');
        try {
            $user = $this->userWith(['sync.operate']);
            Http::fake(function (Request $request) {
                if (str_contains($request->url(), '/orders?')) {
                    $valid = $this->wooOrder(405, 'WC-405');
                    $shell = $this->wooOrder(406, 'WC-406');
                    $shell['created_via'] = 'admin';
                    $shell['line_items'] = [];

                    return Http::response([$valid, $shell], 200, ['X-WP-TotalPages' => '1']);
                }

                return Http::response([], 404);
            });

            $response = $this->actingAs($user)->post('/sync/woocommerce/incremental');
            $response->assertSessionHasNoErrors();
            $response->assertSessionHas('woocommerce_import_summary', fn (array $summary): bool => $summary['seen'] === 2
                && $summary['created'] === 1
                && $summary['updated'] === 0
                && $summary['skipped'] === 1
                && $summary['failed'] === 0
            );
            $this->assertSame(1, Order::count());
            $this->assertTrue(OrderExternalReference::where('reference', '405')->exists());
            $this->assertFalse(OrderExternalReference::where('reference', '406')->exists());

            $run = SyncRun::query()->where('run_type', 'woocommerce_order_manual_today')->firstOrFail();
            $this->assertSame($user->id, $run->initiated_by);
            $this->assertSame('partial', $run->status->value);
            $this->assertSame(2, $run->requested_items);
            $this->assertSame(1, $run->succeeded_items);
            $this->assertSame(1, $run->skipped_items);
            $this->assertSame(0, $run->failed_items);
            $this->assertSame('orders', $run->context['domain']);
            $this->assertSame('manual_today', $run->context['mode']);
            $this->assertCount(2, $run->items()->get());

            Http::assertSent(function (Request $request): bool {
                if (! str_contains($request->url(), '/orders?')) {
                    return false;
                }
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return ($query['after'] ?? null) === '2026-08-27T00:00:00+00:00'
                    && ($query['before'] ?? null) === '2026-08-27T15:30:00+00:00'
                    && ($query['status'] ?? null) === 'any'
                    && ($query['orderby'] ?? null) === 'date'
                    && ($query['order'] ?? null) === 'asc'
                    && ($query['per_page'] ?? null) === '100'
                    && ! array_key_exists('modified_after', $query);
            });
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_historical_import_paginates_explicit_date_range(): void
    {
        $user = $this->userWith(['sync.operate']);
        Http::fake(function (Request $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);

            return Http::response(
                [$this->wooOrder($page === 1 ? 501 : 502, $page === 1 ? 'WC-501' : 'WC-502')],
                200,
                ['X-WP-TotalPages' => '2'],
            );
        });

        $this->actingAs($user)->post('/sync/woocommerce/historical', ['from' => '2026-08-01', 'to' => '2026-08-27'])->assertSessionHasNoErrors();
        $this->assertSame(2, Order::count());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after=') && str_contains($request->url(), 'before=') && str_contains($request->url(), 'page=2'));
    }

    // ── Finding 4: Woo inbound M1.2 SKU resolver integration ─────────────────

    public function test_woo_inbound_alias_sku_stores_canonical_product_id_and_sku(): void
    {
        $user = $this->userWith(['sync.operate']);

        $product = Product::create([
            'source_type' => 'manual', 'woocommerce_product_id' => null,
            'sku' => 'VM467A', 'name' => 'Canonical Product', 'product_type' => 'simple',
        ]);
        $aliasService = app(SkuAliasService::class);
        $aliasService->createAlias($product->id, 'VM467A DARK GREEN');

        // Woo order with no woocommerce_product_id (=0) but SKU = alias
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/601' => Http::response(
            $this->wooOrder(601, 'WC-601', 'processing', '19.00', 0, 'VM467A DARK GREEN'), 200
        )]);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 601])->assertSessionHasNoErrors();

        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertSame($product->id, $item->product_id, 'must store canonical product_id');
        $this->assertSame('VM467A', $item->sku, 'must store canonical sku, not alias');
    }

    public function test_woo_inbound_unknown_sku_stays_unresolved_and_creates_reconciliation_with_reference(): void
    {
        $user = $this->userWith(['sync.operate']);

        Http::fake(['https://shop.test/wp-json/wc/v3/orders/602' => Http::response(
            $this->wooOrder(602, 'WC-602', 'processing', '9.00', 0, 'BRAND-NEW-UNKNOWN'), 200
        )]);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 602])->assertSessionHasNoErrors();

        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertNull($item->product_id, 'product_id must remain null for unresolved');
        $this->assertSame('BRAND-NEW-UNKNOWN', $item->sku, 'raw sku preserved');

        $reconciliation = ProductSkuReconciliation::where('raw_sku', 'BRAND-NEW-UNKNOWN')->first();
        $this->assertNotNull($reconciliation, 'reconciliation observation must be created');
        $this->assertSame('open', $reconciliation->status);
        $this->assertSame('woocommerce_order', $reconciliation->last_source_type);
        $this->assertStringContainsString('602', (string) $reconciliation->last_source_reference);
    }

    public function test_woo_inbound_strong_product_id_takes_precedence_over_alias_sku(): void
    {
        $user = $this->userWith(['sync.operate']);

        // Product with Woo Product ID 5001 and canonical SKU CANON-5001
        $product = Product::create([
            'source_type' => 'woocommerce', 'woocommerce_product_id' => 5001,
            'sku' => 'CANON-5001', 'name' => 'Strong Identity Product', 'product_type' => 'simple',
        ]);

        // Another product that has 'ALIAS-SKU' as an alias
        $otherProduct = Product::create([
            'source_type' => 'manual', 'woocommerce_product_id' => null,
            'sku' => 'OTHER-PRODUCT', 'name' => 'Other Product', 'product_type' => 'simple',
        ]);
        $aliasService = app(SkuAliasService::class);
        $aliasService->createAlias($otherProduct->id, 'ALIAS-SKU');

        // Woo order with strong product_id=5001 but line item SKU = ALIAS-SKU (an alias for other product)
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/603' => Http::response(
            $this->wooOrder(603, 'WC-603', 'processing', '15.00', 5001, 'ALIAS-SKU'), 200
        )]);

        $this->actingAs($user)->post('/sync/woocommerce/single', ['woo_order_id' => 603])->assertSessionHasNoErrors();

        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertSame($product->id, $item->product_id, 'strong Woo product_id must win');
        $this->assertSame('CANON-5001', $item->sku, 'canonical sku of strong-identity product must be used');
    }

    public function test_inbound_page_and_connection_probe_are_operator_accessible(): void
    {
        $user = $this->userWith(['sync.view', 'sync.operate']);
        $this->actingAs($user)->get('/sync/woocommerce')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Sync/WooCommerceInbound')
            ->where('connection.configured', true)
            ->where('connection.connection_key', 'shop-a'));

        Http::fake(['https://shop.test/wp-json/wc/v3/orders*' => Http::response([], 200)]);
        $this->actingAs($user)->post('/sync/woocommerce/probe')->assertSessionHas('success');
        Http::assertSent(fn (Request $request) => str_starts_with((string) $request->header('User-Agent')[0], 'Mozilla/5.0')
            && str_contains((string) $request->header('User-Agent')[0], 'BYL-ERP-WooCommerce-Inbound/1.0'));
    }

    private function wooOrder(
        int $id,
        string $number,
        string $status = 'processing',
        string $price = '12.34',
        int $productId = 0,
        string $sku = '',
        ?string $note = null,
    ): array {
        return [
            'id' => $id,
            'number' => $number,
            'status' => $status,
            'currency' => 'EUR',
            'date_created_gmt' => '2026-08-27T01:02:03',
            'date_modified_gmt' => '2026-08-27T01:05:03',
            'customer_note' => $note ?? '',
            'shipping' => [
                'first_name' => 'Ada', 'last_name' => 'Lovelace', 'company' => '',
                'address_1' => '1 Test Str', 'address_2' => '', 'city' => 'Berlin', 'state' => 'BE', 'postcode' => '10115', 'country' => 'DE',
            ],
            'billing' => ['phone' => '+49123456'],
            'line_items' => [[
                'id' => 100000 + $id,
                'name' => 'Imported Product', 'product_id' => $productId, 'variation_id' => 0,
                'sku' => $sku, 'quantity' => 1, 'price' => $price, 'total' => $price,
            ]],
        ];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'woo-inbound-'.uniqid(), 'label' => 'Woo Inbound']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
