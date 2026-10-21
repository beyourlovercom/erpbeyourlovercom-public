<?php

namespace Tests\Feature;

use App\Modules\Orders\Application\Inbound\WooCommerceInboundService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Application\Contracts\SyncRunTracker;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceAutomaticOrderSyncTest extends TestCase
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

    public function test_automatic_sync_advances_checkpoint_across_admin_shell(): void
    {
        $product = Product::create([
            'woocommerce_product_id' => 5001,
            'sku' => 'WOO-5001',
            'name' => 'Canonical Widget',
            'product_type' => 'simple',
        ]);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/orders/700')) {
                return Http::response($this->wooOrder(700, '01:05:00', 5001), 200);
            }
            if (str_contains($request->url(), '/orders?')) {
                $valid = $this->wooOrder(701, '01:10:00', 5001);
                $shell = $this->wooOrder(702, '01:12:00', 0);
                $shell['created_via'] = 'admin';
                $shell['line_items'] = [];

                return Http::response([$valid, $shell], 200, ['X-WP-TotalPages' => '1']);
            }

            return Http::response([], 404);
        });

        app(WooCommerceInboundService::class)->importOne(700, null);
        $this->artisan('orders:sync-woocommerce --json')->assertSuccessful();

        $this->assertSame(2, Order::count());
        $this->assertSame($product->id, Order::latest('id')->firstOrFail()->items()->firstOrFail()->product_id);
        $this->assertFalse(OrderExternalReference::where('reference', '702')->exists());

        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame('partial', $run->status->value);
        $this->assertSame(2, $run->requested_items);
        $this->assertSame(1, $run->succeeded_items);
        $this->assertSame(1, $run->skipped_items);
        $this->assertSame(0, $run->failed_items);
        $this->assertSame('2026-08-27T01:12:00+00:00', $run->context['checkpoint_modified_at']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/orders?')
            && str_contains($request->url(), 'modified_after=2026-08-27T01%3A00%3A00%2B00%3A00'));
    }

    public function test_automatic_sync_excludes_marked_dev_sandbox_order_and_advances_checkpoint(): void
    {
        Product::create([
            'woocommerce_product_id' => 5001,
            'sku' => 'WOO-5001',
            'name' => 'Canonical Widget',
            'product_type' => 'simple',
        ]);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/orders/720')) {
                return Http::response($this->wooOrder(720, '01:05:00', 5001), 200);
            }
            $sandbox = $this->wooOrder(721, '01:12:00', 5001);
            $sandbox['meta_data'] = [['key' => '_byl_dev_sandbox_p13', 'value' => 'yes']];
            $normal = $this->wooOrder(722, '01:10:00', 5001);
            $normal['meta_data'] = [['key' => '_byl_dev_sandbox_p13', 'value' => 'no']];

            return Http::response([$normal, $sandbox], 200, ['X-WP-TotalPages' => '1']);
        });

        app(WooCommerceInboundService::class)->importOne(720, null);
        $this->artisan('orders:sync-woocommerce --json')->assertSuccessful();

        $this->assertSame(2, Order::count());
        $this->assertFalse(OrderExternalReference::where('reference', '721')->exists());
        $this->assertTrue(OrderExternalReference::where('reference', '722')->exists());
        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $run->requested_items);
        $this->assertSame(1, $run->succeeded_items);
        $this->assertSame(1, $run->skipped_items);
        $this->assertSame(0, $run->failed_items);
        $this->assertSame('2026-08-27T01:12:00+00:00', $run->context['checkpoint_modified_at']);
    }

    public function test_single_import_excludes_exact_dev_synthetic_billing_identity_without_private_meta(): void
    {
        $sandbox = $this->wooOrder(730, '01:05:00', 5001);
        $sandbox['billing']['email'] = 'BYL-P13@EXAMPLE.INVALID';
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/730' => Http::response($sandbox, 200)]);

        $result = app(WooCommerceInboundService::class)->importOne(730, null);

        $this->assertSame(1, $result['seen']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, Order::count());
        $this->assertFalse(OrderExternalReference::where('reference', '730')->exists());
    }

    public function test_manual_today_sync_excludes_dev_sandbox_order_but_keeps_normal_orders(): void
    {
        Product::create([
            'woocommerce_product_id' => 5001,
            'sku' => 'WOO-5001',
            'name' => 'Canonical Widget',
            'product_type' => 'simple',
        ]);
        Http::fake(function (Request $request) {
            $sandbox = $this->wooOrder(740, '01:10:00', 5001);
            $sandbox['meta_data'] = [['key' => '_byl_dev_sandbox_p13', 'value' => 'yes']];
            $normal = $this->wooOrder(741, '01:12:00', 5001);

            return Http::response([$sandbox, $normal], 200, ['X-WP-TotalPages' => '1']);
        });

        $result = app(WooCommerceInboundService::class)->importToday(null);

        $this->assertSame(2, $result['seen']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, Order::count());
        $this->assertFalse(OrderExternalReference::where('reference', '740')->exists());
        $this->assertTrue(OrderExternalReference::where('reference', '741')->exists());
    }

    public function test_real_zero_line_checkout_failure_does_not_advance_checkpoint(): void
    {
        Product::create([
            'woocommerce_product_id' => 5001,
            'sku' => 'WOO-5001',
            'name' => 'Canonical Widget',
            'product_type' => 'simple',
        ]);
        $phase = 1;
        Http::fake(function (Request $request) use (&$phase) {
            if (str_ends_with($request->url(), '/orders/710')) {
                return Http::response($this->wooOrder(710, '01:05:00', 5001), 200);
            }
            if ($phase === 1) {
                return Http::response([$this->wooOrder(711, '01:10:00', 5001)], 200, ['X-WP-TotalPages' => '1']);
            }
            $failed = $this->wooOrder(712, '01:20:00', 0);
            $failed['line_items'] = [];

            return Http::response([$failed], 200, ['X-WP-TotalPages' => '1']);
        });

        app(WooCommerceInboundService::class)->importOne(710, null);
        $this->artisan('orders:sync-woocommerce --json')->assertSuccessful();
        $this->assertSame(
            '2026-08-27T01:10:00+00:00',
            app(SyncRunTracker::class)->latestContextString(
                'woocommerce_order_incremental', 'woocommerce', 'shop-a', 'checkpoint_modified_at',
            ),
        );

        $phase = 2;
        $exit = Artisan::call('orders:sync-woocommerce', ['--json' => true]);
        $this->assertSame(1, $exit, Artisan::output());

        $latest = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $latest->status->value);
        $this->assertSame(1, $latest->failed_items);
        $this->assertArrayNotHasKey('checkpoint_modified_at', $latest->context);
        $this->assertSame(
            '2026-08-27T01:10:00+00:00',
            app(SyncRunTracker::class)->latestContextString(
                'woocommerce_order_incremental', 'woocommerce', 'shop-a', 'checkpoint_modified_at',
            ),
        );
        $this->assertFalse(OrderExternalReference::where('reference', '712')->exists());
    }

    public function test_checkpoint_ignores_newer_non_terminal_run_context(): void
    {
        $tracker = app(SyncRunTracker::class);
        $completed = $tracker->start(
            'woocommerce_order_incremental', 0, null, 'woocommerce', 'shop-a',
            ['checkpoint_modified_at' => '2026-08-27T01:10:00+00:00'],
        );
        $tracker->complete($completed, null);
        $tracker->start(
            'woocommerce_order_incremental', 0, null, 'woocommerce', 'shop-a',
            ['checkpoint_modified_at' => '2026-08-27T01:20:00+00:00'],
        );

        $this->assertSame(
            '2026-08-27T01:10:00+00:00',
            $tracker->latestContextString(
                'woocommerce_order_incremental', 'woocommerce', 'shop-a', 'checkpoint_modified_at',
            ),
        );
    }

    public function test_scheduler_registers_bounded_order_sync_when_enabled(): void
    {
        config(['services.woocommerce.order_sync_enabled' => true]);
        require base_path('routes/console.php');

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => $event->description === 'orders.woocommerce.inbound.hourly');
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(55, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertStringContainsString('orders:sync-woocommerce', $event->command);
        $this->assertStringContainsString('--no-interaction', $event->command);
    }

    private function wooOrder(int $id, string $modifiedTime, int $productId): array
    {
        return [
            'id' => $id,
            'number' => 'WC-'.$id,
            'status' => 'processing',
            'created_via' => 'checkout',
            'currency' => 'USD',
            'date_created_gmt' => '2026-08-27T01:00:00',
            'date_modified_gmt' => '2026-08-27T'.$modifiedTime,
            'customer_note' => '',
            'shipping' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'address_1' => '1 Test Str',
                'address_2' => '',
                'city' => 'Berlin',
                'state' => 'BE',
                'postcode' => '10115',
                'country' => 'DE',
            ],
            'billing' => ['phone' => '+49123456'],
            'line_items' => [[
                'name' => 'Imported Product',
                'product_id' => $productId,
                'variation_id' => 0,
                'sku' => $productId > 0 ? 'WOO-5001' : '',
                'quantity' => 1,
                'price' => '19.95',
                'total' => '19.95',
            ]],
        ];
    }
}
