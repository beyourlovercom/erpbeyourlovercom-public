<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\SyncOrchestrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SyncOrchestrationControllerTest extends TestCase
{
    use RefreshDatabase;

    private ControllerFakeTrackingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new ControllerFakeTrackingGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $this->gateway);
    }

    public function test_operator_can_launch_selected_tracking_batch(): void
    {
        $user = $this->userWith(['sync.view', 'sync.operate']);
        $order = $this->order(8101);
        $this->actingAs($user)
            ->post('/sync/orchestration/tracking/selected', ['order_ids' => [$order->id]])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '所选 Tracking 写回完成'));

        $this->assertSame(1, $this->gateway->writeCalls);
        $this->assertDatabaseHas('sync_runs', [
            'run_type' => 'tracking_selected',
            'status' => 'completed',
            'requested_items' => 1,
            'succeeded_items' => 1,
        ]);
        $this->assertDatabaseHas('sync_run_items', [
            'operation' => 'woocommerce.tracking_outbound',
            'status' => 'succeeded',
            'order_id' => $order->id,
        ]);
    }

    public function test_viewer_can_open_run_detail_evidence(): void
    {
        $user = $this->userWith(['sync.view']);
        $order = $this->order(8201);
        $run = app(SyncOrchestrationService::class)->trackingSelected([$order->id], null);

        $this->actingAs($user)
            ->get('/sync/runs/'.$run->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sync/RunShow')
                ->where('run.id', $run->id)
                ->where('run.status', 'completed')
                ->where('run.items.0.order_id', $order->id)
                ->where('run.items.0.status', 'succeeded')
            );
    }

    private function order(int $providerOrderId): Order
    {
        $order = Order::create([
            'internal_order_number' => 'ORCH-CTRL-'.$providerOrderId,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-'.$providerOrderId,
            'order_date' => '2026-08-28',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => (string) $providerOrderId,
        ]);
        $order->items()->create([
            'woocommerce_line_item_id' => $providerOrderId + 100000,
            'product_summary' => 'Synthetic item',
            'sku' => 'SKU-'.$providerOrderId,
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'shipped',
            'carrier' => 'DHL',
            'service' => 'Express',
            'tracking_number' => 'TRACK-'.$providerOrderId,
            'shipped_at' => '2026-08-28T00:30:00Z',
        ]);
        $order->manualSync()->create(['website_sync_status' => 'pending']);

        return $order->fresh(['shipment', 'items', 'externalReferences', 'manualSync']);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'sync-orch-'.uniqid(), 'label' => 'Sync Orchestration']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}

final class ControllerFakeTrackingGateway implements WooCommerceTrackingGateway
{
    public int $writeCalls = 0;

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a'];
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
        $this->writeCalls++;

        return ['written' => true, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
