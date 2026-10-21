<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\WooCommerceProductInboundService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductExternalReference;
use App\Modules\Products\Infrastructure\Models\ProductMedia;
use App\Modules\Products\Infrastructure\Models\ProductProviderSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WooCommerceProductInboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop.test',
            'services.woocommerce.media_allowed_hosts' => '',
        ]);
        Storage::fake('local');
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WlE8cYAAAAASUVORK5CYII=');
    }

    /** @return array<string,mixed> */
    private function payload(int $id, string $sku, array $images = [], array $overrides = []): array
    {
        return [
            'id' => $id,
            'sku' => $sku,
            'type' => 'simple',
            'name' => 'Woo '.$sku,
            'status' => 'publish',
            'sale_price' => '29.99',
            'regular_price' => '39.99',
            'permalink' => "https://shop.test/product/{$sku}",
            'description' => '<p>Long description</p>',
            'short_description' => '<p>Short description</p>',
            'categories' => [['id' => 1, 'name' => 'Category A']],
            'variations' => [],
            'date_created' => '2026-08-01T10:00:00',
            'date_modified' => '2026-09-03T08:00:00',
            'images' => $images,
            ...$overrides,
        ];
    }

    private function fakeSingle(array $payload, array $imageBodies = []): void
    {
        $routes = [
            'https://shop.test/wp-json/wc/v3/products/'.$payload['id'] => Http::response($payload, 200),
        ];
        foreach ($imageBodies as $url => $body) {
            $routes[$url] = Http::response($body, 200, ['Content-Type' => 'image/png']);
        }
        Http::fake($routes);
    }

    public function test_existing_product_sync_updates_allowlist_preserves_weight_and_is_idempotent(): void
    {
        $product = Product::create([
            'source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'SYNC-001',
            'name' => 'Old name', 'weight_kg' => 0.42, 'shipping_weight_kg' => 0.58,
        ]);
        $payload = $this->payload(101, 'SYNC-001', [['id' => 501, 'src' => 'https://shop.test/media/a.png', 'alt' => 'Primary']], ['related_ids' => [11, 12, 13]]);
        $this->fakeSingle($payload, ['https://shop.test/media/a.png' => $this->png()]);

        $first = app(WooCommerceProductInboundService::class)->sync(null, false, 101);
        $product->refresh();
        $this->assertSame('Woo SYNC-001', $product->name);
        $this->assertSame('SYNC-001', $product->sku);
        $this->assertSame(0.42, (float) $product->weight_kg);
        $this->assertSame(0.58, (float) $product->shipping_weight_kg);
        $this->assertSame('updated', DB::table('sync_run_items')->where('sync_run_id', $first['run']->id)->value('result_code'));
        $this->assertSame($product->id, DB::table('sync_run_items')->where('sync_run_id', $first['run']->id)->value('product_id'));
        $this->assertDatabaseHas('product_provider_snapshots', ['product_id' => $product->id, 'remote_product_id' => '101']);
        $this->assertDatabaseCount('product_media', 1);

        $payload['related_ids'] = [21, 22, 23];
        $this->fakeSingle($payload, ['https://shop.test/media/a.png' => $this->png()]);
        $second = app(WooCommerceProductInboundService::class)->sync(null, false, 101);
        $this->assertSame('unchanged', DB::table('sync_run_items')->where('sync_run_id', $second['run']->id)->value('result_code'));
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_media', 1);
    }

    public function test_media_failure_preserves_last_known_good_gallery(): void
    {
        $product = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'MEDIA-001', 'name' => 'Media']);
        $good = $this->payload(201, 'MEDIA-001', [['id' => 601, 'src' => 'https://shop.test/media/good.png']]);
        $bad = $this->payload(201, 'MEDIA-001', [['id' => 602, 'src' => 'https://evil.test/bad.png']], ['name' => 'Updated despite media failure']);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls, $good, $bad) {
            if ($request->url() === 'https://shop.test/wp-json/wc/v3/products/201') {
                return Http::response($calls++ === 0 ? $good : $bad, 200);
            }
            if ($request->url() === 'https://shop.test/media/good.png') return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
            return Http::response([], 404);
        });
        app(WooCommerceProductInboundService::class)->sync(null, false, 201);
        $path = (string) ProductMedia::query()->value('storage_path');
        Storage::disk('local')->assertExists($path);

        $result = app(WooCommerceProductInboundService::class)->sync(null, false, 201);

        $this->assertSame('media_failed', DB::table('sync_run_items')->where('sync_run_id', $result['run']->id)->value('result_code'));
        $this->assertSame('failed', $result['run']->status->value);
        $this->assertDatabaseCount('product_media', 1);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('Updated despite media failure', $product->fresh()->name);
    }

    public function test_successful_reconciliation_removes_stale_media_only_after_complete_fetch(): void
    {
        Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'MEDIA-002', 'name' => 'Media']);
        $first = $this->payload(202, 'MEDIA-002', [
            ['id' => 701, 'src' => 'https://shop.test/media/one.png'],
            ['id' => 702, 'src' => 'https://shop.test/media/two.png'],
        ]);
        $second = $this->payload(202, 'MEDIA-002', [['id' => 702, 'src' => 'https://shop.test/media/two.png']]);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls, $first, $second) {
            if ($request->url() === 'https://shop.test/wp-json/wc/v3/products/202') return Http::response($calls++ === 0 ? $first : $second, 200);
            if (in_array($request->url(), ['https://shop.test/media/one.png', 'https://shop.test/media/two.png'], true)) return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
            return Http::response([], 404);
        });
        app(WooCommerceProductInboundService::class)->sync(null, false, 202);
        $this->assertDatabaseCount('product_media', 1);
        $this->assertDatabaseHas('product_media', ['provider_media_key' => '701', 'position' => 0, 'is_primary' => 1]);
        $this->assertDatabaseMissing('product_media', ['provider_media_key' => '702']);

        app(WooCommerceProductInboundService::class)->sync(null, false, 202);
        $this->assertDatabaseCount('product_media', 1);
        $this->assertDatabaseHas('product_media', ['provider_media_key' => '702', 'position' => 0, 'is_primary' => 1]);
        $this->assertDatabaseMissing('product_media', ['provider_media_key' => '701']);
    }

    public function test_gif_cover_is_ignored_and_secondary_static_image_is_not_promoted(): void
    {
        Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'GIF-COVER', 'name' => 'Gif Cover']);
        $payload = $this->payload(203, 'GIF-COVER', [
            ['id' => 703, 'src' => 'https://shop.test/media/cover.gif'],
            ['id' => 704, 'src' => 'https://shop.test/media/fallback.png'],
        ]);
        $this->fakeSingle($payload, ['https://shop.test/media/fallback.png' => $this->png()]);

        $result = app(WooCommerceProductInboundService::class)->sync(null, false, 203);

        $this->assertSame('updated', DB::table('sync_run_items')->where('sync_run_id', $result['run']->id)->value('result_code'));
        $this->assertDatabaseCount('product_media', 0);
        Http::assertNotSent(fn (Request $request): bool => in_array($request->url(), ['https://shop.test/media/cover.gif', 'https://shop.test/media/fallback.png'], true));
    }

    public function test_blank_sku_and_changed_bound_sku_fail_closed_without_rebinding(): void
    {
        $blank = $this->payload(301, '', []);
        $this->fakeSingle($blank);
        $blankRun = app(WooCommerceProductInboundService::class)->sync(null, false, 301);
        $this->assertSame('conflict', DB::table('sync_run_items')->where('sync_run_id', $blankRun['run']->id)->value('result_code'));
        $this->assertDatabaseCount('products', 0);

        $product = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'BOUND-001', 'name' => 'Bound']);
        ProductExternalReference::create(['product_id' => $product->id, 'provider' => 'woocommerce', 'connection_key' => 'shop.test', 'reference' => '302', 'reference_type' => 'product']);
        $changed = $this->payload(302, 'DIFFERENT-SKU', []);
        $this->fakeSingle($changed);
        $run = app(WooCommerceProductInboundService::class)->sync(null, false, 302);
        $this->assertSame('conflict', DB::table('sync_run_items')->where('sync_run_id', $run['run']->id)->value('result_code'));
        $this->assertSame('BOUND-001', $product->fresh()->sku);
        $this->assertDatabaseHas('product_external_references', ['product_id' => $product->id, 'reference' => '302']);
        $this->assertDatabaseMissing('products', ['sku' => 'DIFFERENT-SKU']);
    }

    public function test_blank_name_fails_closed_without_creating_or_overwriting_product(): void
    {
        $unbound = $this->payload(303, 'NAMELESS-NEW', [], ['name' => '']);
        $this->fakeSingle($unbound);
        $newRun = app(WooCommerceProductInboundService::class)->sync(null, false, 303);
        $this->assertSame('conflict', DB::table('sync_run_items')->where('sync_run_id', $newRun['run']->id)->value('result_code'));
        $this->assertDatabaseMissing('products', ['sku' => 'NAMELESS-NEW']);

        $product = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'NAME-BOUND', 'name' => 'Keep Me']);
        ProductExternalReference::create(['product_id' => $product->id, 'provider' => 'woocommerce', 'connection_key' => 'shop.test', 'reference' => '304', 'reference_type' => 'product']);
        $bound = $this->payload(304, 'NAME-BOUND', [], ['name' => '']);
        $this->fakeSingle($bound);
        $boundRun = app(WooCommerceProductInboundService::class)->sync(null, false, 304);
        $this->assertSame('conflict', DB::table('sync_run_items')->where('sync_run_id', $boundRun['run']->id)->value('result_code'));
        $this->assertSame('Keep Me', $product->fresh()->name);
    }

    public function test_invalid_image_body_and_cross_host_redirect_are_rejected(): void
    {
        Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'SEC-001', 'name' => 'Security']);
        $invalid = $this->payload(401, 'SEC-001', [['id' => 801, 'src' => 'https://shop.test/media/not-image.png']]);
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/401' => Http::response($invalid, 200),
            'https://shop.test/media/not-image.png' => Http::response('not-an-image', 200, ['Content-Type' => 'image/png']),
        ]);
        $first = app(WooCommerceProductInboundService::class)->sync(null, false, 401);
        $this->assertSame('media_failed', DB::table('sync_run_items')->where('sync_run_id', $first['run']->id)->value('result_code'));
        $this->assertDatabaseCount('product_media', 0);

        $redirect = $this->payload(401, 'SEC-001', [['id' => 802, 'src' => 'https://shop.test/media/redirect.png']]);
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/401' => Http::response($redirect, 200),
            'https://shop.test/media/redirect.png' => Http::response('', 302, ['Location' => 'https://evil.test/image.png']),
        ]);
        $second = app(WooCommerceProductInboundService::class)->sync(null, false, 401);
        $this->assertSame('media_failed', DB::table('sync_run_items')->where('sync_run_id', $second['run']->id)->value('result_code'));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://evil.test/'));
    }


    public function test_media_size_limit_can_be_increased_without_changing_the_default(): void
    {
        $product = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'SIZE-CONFIG', 'name' => 'Size Config']);
        $body = $this->png();
        $payload = $this->payload(403, 'SIZE-CONFIG', [['id' => 804, 'src' => 'https://shop.test/media/config.png']]);

        config(['services.woocommerce.media_max_bytes' => max(1, strlen($body) - 1)]);
        $this->fakeSingle($payload, ['https://shop.test/media/config.png' => $body]);
        $blocked = app(WooCommerceProductInboundService::class)->sync(null, false, 403);
        $this->assertSame('media_failed', DB::table('sync_run_items')->where('sync_run_id', $blocked['run']->id)->value('result_code'));
        $this->assertDatabaseCount('product_media', 0);

        config(['services.woocommerce.media_max_bytes' => strlen($body) + 1]);
        $this->fakeSingle($payload, ['https://shop.test/media/config.png' => $body]);
        $allowed = app(WooCommerceProductInboundService::class)->sync(null, false, 403);
        $this->assertSame('unchanged', DB::table('sync_run_items')->where('sync_run_id', $allowed['run']->id)->value('result_code'));
        $this->assertDatabaseCount('product_media', 1);
    }

    public function test_media_larger_than_ten_mib_is_rejected_without_persisting_media(): void
    {
        Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'SIZE-001', 'name' => 'Size']);
        $payload = $this->payload(402, 'SIZE-001', [['id' => 803, 'src' => 'https://shop.test/media/large.png']]);
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/402' => Http::response($payload, 200),
            'https://shop.test/media/large.png' => Http::response(str_repeat('x', 10485761), 200, ['Content-Type' => 'image/png']),
        ]);

        $result = app(WooCommerceProductInboundService::class)->sync(null, false, 402);
        $this->assertSame('media_failed', DB::table('sync_run_items')->where('sync_run_id', $result['run']->id)->value('result_code'));
        $this->assertDatabaseCount('product_media', 0);
    }

    public function test_media_route_is_authenticated_and_requires_products_view(): void
    {
        $product = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'MEDIA-ROUTE', 'name' => 'Media Route']);
        $path = 'products/'.$product->id.'/woo/test.png';
        Storage::disk('local')->put($path, $this->png());
        $media = ProductMedia::create([
            'product_id' => $product->id, 'provider' => 'woocommerce', 'connection_key' => 'shop.test',
            'provider_media_key' => '901', 'source_url' => 'https://shop.test/media/test.png',
            'storage_path' => $path, 'mime_type' => 'image/png', 'size_bytes' => strlen($this->png()),
            'sha256' => hash('sha256', $this->png()), 'position' => 0, 'is_primary' => true,
        ]);

        $this->get("/products/media/{$media->id}")->assertRedirect('/login');
        $noView = $this->userWith(['sync.operate']);
        $this->actingAs($noView)->get("/products/media/{$media->id}")->assertForbidden();
        $viewer = $this->userWith(['products.view']);
        $this->actingAs($viewer)->get("/products/media/{$media->id}")->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_product_gateway_sends_byl_user_agent(): void
    {
        Http::fake(['https://shop.test/wp-json/wc/v3/products*' => Http::response([], 200)]);

        app(WooCommerceProductInboundService::class)->sync(null, true, null);

        Http::assertSent(fn (Request $request): bool => str_starts_with((string) $request->header('User-Agent')[0], 'Mozilla/5.0')
            && str_contains((string) $request->header('User-Agent')[0], 'BYL-ERP-WooCommerce-Inbound/1.0'));
    }

    public function test_incremental_sync_uses_latest_provider_modified_time_with_overlap(): void
    {
        $product = Product::create(['source_type' => 'woocommerce', 'product_type' => 'simple', 'sku' => 'CURSOR-001', 'name' => 'Cursor']);
        ProductProviderSnapshot::create([
            'product_id' => $product->id,
            'provider' => 'woocommerce',
            'connection_key' => 'shop.test',
            'remote_product_id' => '501',
            'provider_modified_at' => '2026-09-03 08:00:00',
            'payload_hash' => str_repeat('a', 64),
        ]);
        Http::fake(['https://shop.test/wp-json/wc/v3/products*' => Http::response([], 200)]);

        app(WooCommerceProductInboundService::class)->sync(null, false, null);
        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), 'https://shop.test/wp-json/wc/v3/products')) return false;
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return isset($query['modified_after']) && str_contains((string) $query['modified_after'], '07:55:00');
        });
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'woo-product-'.uniqid(), 'label' => 'Woo Product']);
        foreach ($permissions as $permission) $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        $user->roles()->attach($role);
        return $user;
    }


    public function test_product360_distinguishes_mapped_not_synced_from_unconfigured(): void
    {
        $mapped = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'STATE-001', 'name' => 'Mapped']);
        ProductExternalReference::create(['product_id' => $mapped->id, 'provider' => 'woocommerce', 'connection_key' => 'shop.test', 'reference' => '701', 'reference_type' => 'product']);
        $viewer = $this->userWith(['products.view']);
        $this->actingAs($viewer)->get("/products/{$mapped->id}")->assertInertia(fn ($page) => $page
            ->where('product.woo_sync_status', 'not_synced')
            ->where('hasWooProductReference', true));

        $manual = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'STATE-002', 'name' => 'Manual']);
        $this->actingAs($viewer)->get("/products/{$manual->id}")->assertInertia(fn ($page) => $page
            ->where('product.woo_sync_status', 'not_configured')
            ->where('hasWooProductReference', false));
    }

    public function test_single_product_refresh_requires_sync_operate_without_products_operate(): void
    {
        $product = Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'ROUTE-001', 'name' => 'Route']);
        ProductExternalReference::create(['product_id' => $product->id, 'provider' => 'woocommerce', 'connection_key' => 'shop.test', 'reference' => '601', 'reference_type' => 'product']);
        $payload = $this->payload(601, 'ROUTE-001', []);
        $this->fakeSingle($payload);

        $viewer = $this->userWith(['products.view']);
        $this->actingAs($viewer)->post("/products/{$product->id}/sync-woocommerce")->assertForbidden();
        $this->actingAs($viewer)->get("/products/{$product->id}")->assertInertia(fn ($page) => $page->where('canSync', false));

        $syncOperator = $this->userWith(['products.view', 'sync.operate']);
        $this->fakeSingle($payload);
        $this->actingAs($syncOperator)->post("/products/{$product->id}/sync-woocommerce")->assertRedirect();
        $this->actingAs($syncOperator)->get("/products/{$product->id}")->assertInertia(fn ($page) => $page->where('canSync', true)->where('canOperate', false));
    }
}
