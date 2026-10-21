<?php

namespace Tests\Feature;

use App\Modules\Sync\Application\WooCommerceOrderStatusUnsupportedException;
use App\Modules\Sync\Application\WooCommerceTrackingConflictException;
use App\Modules\Sync\Infrastructure\WooCommerce\HttpWooCommerceTrackingGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpWooCommerceTrackingGatewayTest extends TestCase
{
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
        Carbon::setTestNow('2026-09-15T01:02:03Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_first_package_is_written_with_exact_woo_line_item_binding(): void
    {
        Http::fakeSequence('https://shop.test/wp-json/wc/v3/orders/7001')
            ->push($this->remoteOrder(), 200)
            ->push(['id' => 7001], 200)
            ->push($this->remoteOrder([$this->tracking('dhl-api', 'TRACK-A', [1001])]), 200);

        $result = app(HttpWooCommerceTrackingGateway::class)
            ->syncTracking(7001, 'dhl-api', 'TRACK-A', '2026-09-15', [['id' => 1001, 'quantity' => 1]]);

        $this->assertTrue($result['written']);
        $this->assertTrue($result['verified']);
        $this->assertSame(1, $result['package_count']);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $meta = collect($request->data()['meta_data'] ?? [])->keyBy('key');
            $item = $meta['_aftership_tracking_items']['value'][0] ?? [];

            return ($item['tracking_id'] ?? null) === md5('dhl-api-TRACK-A')
                && ($item['line_items'][0]['id'] ?? null) === 1001
                && ($item['line_items'][0]['quantity'] ?? null) === 1
                && array_keys($item['line_items'][0] ?? []) === ['id', 'quantity']
                && ($meta['_aftership_tracking_number']['value'] ?? null) === 'TRACK-A';
        });
    }

    public function test_second_package_appends_without_deleting_first_package(): void
    {
        $a = $this->tracking('usps', 'TRACK-A', [1001]);
        $b = $this->tracking('4px', 'TRACK-B', [1002]);
        Http::fakeSequence('https://shop.test/wp-json/wc/v3/orders/7001')
            ->push($this->remoteOrder([$a]), 200)
            ->push(['id' => 7001], 200)
            ->push($this->remoteOrder([$a, $b]), 200);

        $result = app(HttpWooCommerceTrackingGateway::class)
            ->syncTracking(7001, '4px', 'TRACK-B', '2026-09-15', [['id' => 1002, 'quantity' => 1]]);

        $this->assertSame(2, $result['package_count']);
        Http::assertSent(function (Request $request) use ($a): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $meta = collect($request->data()['meta_data'] ?? [])->keyBy('key');
            $items = $meta['_aftership_tracking_items']['value'] ?? [];

            return count($items) === 2
                && ($items[0]['tracking_id'] ?? null) === $a['tracking_id']
                && ($items[1]['tracking_id'] ?? null) === md5('4px-TRACK-B')
                && ($meta['_aftership_tracking_number']['value'] ?? null) === 'TRACK-A'
                && ($meta['_aftership_tracking_provider_name']['value'] ?? null) === 'usps';
        });
    }

    public function test_same_package_and_same_line_items_is_idempotent(): void
    {
        $a = $this->tracking('usps', 'TRACK-A', [1001]);
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/7001' => Http::response($this->remoteOrder([$a]), 200),
        ]);

        $result = app(HttpWooCommerceTrackingGateway::class)
            ->syncTracking(7001, 'usps', 'TRACK-A', '2026-09-15', [['id' => 1001, 'quantity' => 1]]);

        $this->assertSame(['written' => false, 'verified' => true, 'package_count' => 1], $result);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_line_item_already_claimed_by_other_package_fails_closed(): void
    {
        $a = $this->tracking('usps', 'TRACK-A', [1001]);
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/7001' => Http::response($this->remoteOrder([$a]), 200),
        ]);

        $this->expectException(WooCommerceTrackingConflictException::class);
        $this->expectExceptionMessage('already assigned');
        try {
            app(HttpWooCommerceTrackingGateway::class)
                ->syncTracking(7001, '4px', 'TRACK-B', '2026-09-15', [['id' => 1001, 'quantity' => 1]]);
        } finally {
            Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
        }
    }

    public function test_order_status_sync_is_idempotent_and_verified(): void
    {
        Http::fakeSequence('https://shop.test/wp-json/wc/v3/orders/7001')
            ->push($this->remoteOrder(status: 'processing'), 200)
            ->push(['id' => 7001], 200)
            ->push($this->remoteOrder(status: 'completed'), 200);

        $result = app(HttpWooCommerceTrackingGateway::class)->syncOrderStatus(7001, 'completed');

        $this->assertSame(['written' => true, 'verified' => true, 'status' => 'completed'], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && ($request->data()['status'] ?? null) === 'completed');
    }

    public function test_unregistered_custom_order_status_fails_closed_before_write(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/7001' => Http::response($this->remoteOrder(status: 'completed'), 200),
            'https://shop.test/wp-json/wc/v3/orders/statuses' => Http::response([
                ['slug' => 'processing', 'name' => 'Processing'],
                ['slug' => 'completed', 'name' => 'Completed'],
            ], 200),
        ]);

        $this->expectException(WooCommerceOrderStatusUnsupportedException::class);
        $this->expectExceptionMessage('is not registered');

        try {
            app(HttpWooCommerceTrackingGateway::class)->syncOrderStatus(7001, 'completed-delivered');
        } finally {
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        }
    }

    private function remoteOrder(array $trackings = [], string $status = 'processing'): array
    {
        $meta = [];
        if ($trackings !== []) {
            $meta = [
                ['id' => 501, 'key' => '_aftership_tracking_items', 'value' => $trackings],
                ['id' => 502, 'key' => '_aftership_tracking_number', 'value' => $trackings[0]['tracking_number']],
                ['id' => 503, 'key' => '_aftership_tracking_provider_name', 'value' => $trackings[0]['slug']],
            ];
        }

        return [
            'id' => 7001,
            'status' => $status,
            'line_items' => [
                ['id' => 1001, 'name' => 'Camera', 'product_id' => 501, 'quantity' => 1, 'sku' => 'CAMERA', 'total' => '10.00'],
                ['id' => 1002, 'name' => 'Fatima App - Black', 'product_id' => 502, 'quantity' => 1, 'sku' => 'FATIMA-BLK', 'total' => '20.00'],
            ],
            'meta_data' => $meta,
        ];
    }

    private function tracking(string $provider, string $tracking, array $lineIds): array
    {
        return [
            'slug' => $provider,
            'tracking_number' => $tracking,
            'tracking_id' => md5($provider.'-'.$tracking),
            'additional_fields' => [
                'account_number' => '', 'key' => '', 'postal_code' => '', 'ship_date' => '2026-09-15',
                'destination_country' => '', 'state' => '',
            ],
            'line_items' => collect($lineIds)->map(fn (int $id): array => ['id' => $id, 'quantity' => 1])->values()->all(),
            'metrics' => ['created_at' => '2026-09-15T00:00:00Z', 'updated_at' => '2026-09-15T00:00:00Z'],
        ];
    }
}
