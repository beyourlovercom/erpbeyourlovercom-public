<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\WooCommerceTrackingConflictException;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class WooCommerceTrackingOutboundTest extends TestCase
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
        ]);
    }

    public function test_verified_tracking_completes_outbox_and_manual_sync_idempotently(): void
    {
        $gateway = new FakeWooCommerceTrackingGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        [$user, $order] = $this->scenario();

        $first = app(WooCommerceTrackingOutboundService::class)->pushOne($order, $user);
        $second = app(WooCommerceTrackingOutboundService::class)->pushOne($order->fresh(), $user);

        $this->assertSame('completed', $first['status']);
        $this->assertSame('completed', $second['status']);
        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('sync_outbox', 2);
        $this->assertSame(1, $gateway->statusCalls);
        $this->assertDatabaseHas('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
            'status' => 'completed',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'completed',
        ]);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'manual_sync.completed',
            'reason' => 'woocommerce_tracking_updated_automatically',
        ]);
    }

    public function test_remote_conflict_is_terminal_and_never_completes_manual_sync(): void
    {
        $gateway = new FakeWooCommerceTrackingGateway;
        $gateway->conflict = true;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        [, $order] = $this->scenario();

        $result = app(WooCommerceTrackingOutboundService::class)->pushOne($order, null);

        $this->assertSame('conflict', $result['status']);
        $this->assertDatabaseHas('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
            'status' => 'failed',
            'last_error_code' => 'remote_tracking_conflict',
        ]);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'pending',
        ]);
    }

    public function test_transient_failure_schedules_retry_and_does_not_call_gateway_again_before_backoff(): void
    {
        $gateway = new FakeWooCommerceTrackingGateway;
        $gateway->fail = true;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        [, $order] = $this->scenario();

        $first = app(WooCommerceTrackingOutboundService::class)->pushOne($order, null);
        $second = app(WooCommerceTrackingOutboundService::class)->pushOne($order->fresh(), null);

        $this->assertSame('retry_scheduled', $first['status']);
        $this->assertSame('retry_wait', $second['status']);
        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseHas('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
            'status' => 'pending',
            'attempts' => 1,
            'last_error_code' => 'woocommerce_tracking_write_failed',
        ]);
    }

    public function test_unknown_carrier_is_blocked_before_remote_write(): void
    {
        $gateway = new FakeWooCommerceTrackingGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        [, $order] = $this->scenario(carrier: 'Mystery Logistics');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('尚未配置 AfterShip provider slug');

        try {
            app(WooCommerceTrackingOutboundService::class)->pushOne($order, null);
        } finally {
            $this->assertSame(0, $gateway->calls);
            $this->assertDatabaseCount('sync_outbox', 0);
        }
    }

    public function test_controller_action_surfaces_global_success_flash(): void
    {
        $gateway = new FakeWooCommerceTrackingGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        [$user, $order] = $this->scenario();

        $this->actingAs($user)
            ->post('/sync/orders/'.$order->id.'/tracking-outbound')
            ->assertRedirect()
            ->assertSessionHas('success', 'WooCommerce Tracking 已写回并验证');

        $this->assertSame(1, $gateway->calls);
    }

    public function test_derived_child_resolves_root_woo_order_and_keeps_exact_child_line_items(): void
    {
        $parent = Order::create([
            'internal_order_number' => 'WOO-ROOT-8001',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-8001',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $parent->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => '8001',
        ]);
        $child = Order::create([
            'fulfillment_parent_id' => $parent->id,
            'fulfillment_suffix' => 'A',
            'internal_order_number' => 'FULF-8001-A',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-09-15',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $child->items()->create([
            'woocommerce_line_item_id' => 88001,
            'product_summary' => 'Package A item',
            'sku' => 'PKG-A',
            'quantity' => 2,
            'unit_price' => '10.00',
            'line_revenue' => '20.00',
        ]);
        $child->shipment()->create([
            'shipment_status' => 'shipped',
            'carrier' => 'USPS',
            'tracking_number' => 'TRACK-8001-A',
            'shipped_at' => '2026-09-15T01:00:00Z',
        ]);

        $gateway = new FakeWooCommerceTrackingGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        $prepared = app(WooCommerceTrackingOutboundService::class)
            ->describeOrder($child->fresh(['shipment', 'items', 'fulfillmentParent.externalReferences']));

        $this->assertSame(8001, $prepared['provider_order_id']);
        $this->assertSame([['id' => 88001, 'quantity' => 2]], $prepared['line_items']);
        $this->assertSame($parent->id, $prepared['root_order']->id);
    }

    private function scenario(string $carrier = 'DHL'): array
    {
        $user = $this->userWith(['sync.view', 'sync.operate']);
        $order = Order::create([
            'internal_order_number' => 'WOO-OUT-'.uniqid(),
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-7001',
            'order_date' => '2026-08-28',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => '7001',
        ]);
        $order->items()->create([
            'woocommerce_line_item_id' => 1001,
            'product_summary' => 'Camera',
            'sku' => 'CAMERA',
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'shipped',
            'carrier' => $carrier,
            'service' => 'Express',
            'tracking_number' => 'TRACK-7001',
            'shipped_at' => '2026-08-28T00:30:00Z',
        ]);
        $order->manualSync()->create(['website_sync_status' => 'pending']);

        return [$user, $order->fresh(['shipment', 'items', 'externalReferences', 'manualSync'])];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'woo-out-'.uniqid(), 'label' => 'Woo Tracking Outbound']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}

final class FakeWooCommerceTrackingGateway implements WooCommerceTrackingGateway
{
    public int $calls = 0;

    public int $statusCalls = 0;

    public bool $conflict = false;

    public bool $fail = false;

    public function descriptor(): array
    {
        return [
            'configured' => true,
            'url' => 'https://shop.test',
            'connection_key' => 'shop-a',
        ];
    }

    public function inspectTracking(int $providerOrderId): array
    {
        return ['tracking_number' => '', 'provider' => '', 'has_tracking' => false];
    }

    public function syncTracking(
        int $providerOrderId,
        string $providerSlug,
        string $trackingNumber,
        string $shipDate,
        array $lineItems = [],
    ): array {
        $this->calls++;

        if ($this->conflict) {
            throw new WooCommerceTrackingConflictException('remote conflict');
        }
        if ($this->fail) {
            throw new RuntimeException('temporary remote failure');
        }

        return ['written' => true, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        $this->statusCalls++;

        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
