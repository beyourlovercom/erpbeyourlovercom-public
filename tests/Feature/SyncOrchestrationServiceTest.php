<?php

namespace Tests\Feature;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\SyncOrchestrationService;
use App\Modules\Sync\Application\SyncRunStatus;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class SyncOrchestrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrchestrationFakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new OrchestrationFakeGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $this->gateway);
    }

    public function test_selected_batch_records_every_selection_and_skips_missing_order(): void
    {
        $first = $this->order(7101);
        $second = $this->order(7102);
        $run = app(SyncOrchestrationService::class)->trackingSelected([
            $first->id,
            $second->id,
            999999,
        ], null);

        $this->assertSame(SyncRunStatus::Partial, $run->status);
        $this->assertSame(3, $run->requested_items);
        $this->assertSame(3, $run->processed_items);
        $this->assertSame(2, $run->succeeded_items);
        $this->assertSame(1, $run->skipped_items);
        $this->assertSame(2, $this->gateway->writeCalls);
        $this->assertDatabaseCount('sync_outbox', 4);
        $this->assertSame(2, \DB::table('sync_outbox')->where('operation', 'woocommerce.tracking_outbound')->count());
        $this->assertSame(2, \DB::table('sync_outbox')->where('operation', 'woocommerce.order_status_outbound')->count());
        $this->assertDatabaseCount('sync_run_items', 3);
    }

    public function test_all_pending_is_bounded_and_excludes_completed_manual_sync(): void
    {
        $this->order(7201);
        $this->order(7202);
        $this->order(7203);
        $completed = $this->order(7204);
        $completed->manualSync()->update(['website_sync_status' => 'completed', 'synced_at' => now()]);

        $run = app(SyncOrchestrationService::class)->trackingAllPending(null, 2);

        $this->assertSame(SyncRunStatus::Completed, $run->status);
        $this->assertSame(2, $run->requested_items);
        $this->assertSame(2, $run->succeeded_items);
        $this->assertSame(2, $this->gateway->writeCalls);
        $this->assertDatabaseCount('sync_outbox', 4);
        $this->assertSame(2, \DB::table('sync_outbox')->where('operation', 'woocommerce.tracking_outbound')->count());
        $this->assertSame(2, \DB::table('sync_outbox')->where('operation', 'woocommerce.order_status_outbound')->count());
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $completed->id,
            'website_sync_status' => 'completed',
        ]);
    }

    public function test_retry_runner_only_executes_pending_intent_after_available_at(): void
    {
        $order = $this->order(7301);
        $this->gateway->failWrites = true;
        $first = app(WooCommerceTrackingOutboundService::class)->pushOne($order, null);
        $this->assertSame('retry_scheduled', $first['status']);

        $tooEarly = app(SyncOrchestrationService::class)->retryPending(null);
        $this->assertSame(0, $tooEarly->requested_items);
        $this->assertSame(1, $this->gateway->writeCalls);

        DB::table('sync_outbox')->update(['available_at' => now()->subSecond()]);
        $this->gateway->failWrites = false;
        $retried = app(SyncOrchestrationService::class)->retryPending(null);

        $this->assertSame(1, $retried->requested_items);
        $this->assertSame(1, $retried->succeeded_items);
        $this->assertSame(2, $this->gateway->writeCalls);
        $this->assertDatabaseHas('sync_outbox', [
            'id' => $first['outbox']->id,
            'status' => 'completed',
            'attempts' => 2,
        ]);
    }

    public function test_reconciliation_is_read_only_and_classifies_remote_state(): void
    {
        $matched = $this->order(7401, tracking: 'TRACK-7401');
        $missing = $this->order(7402, tracking: 'TRACK-7402');
        $conflict = $this->order(7403, tracking: 'TRACK-7403');
        $this->gateway->remote[7401] = [
            'tracking_number' => 'TRACK-7401',
            'provider' => 'dhl-api',
            'has_tracking' => true,
        ];
        $this->gateway->remote[7402] = [
            'tracking_number' => '',
            'provider' => '',
            'has_tracking' => false,
        ];
        $this->gateway->remote[7403] = [
            'tracking_number' => 'OTHER-7403',
            'provider' => 'usps',
            'has_tracking' => true,
        ];

        $run = app(SyncOrchestrationService::class)->reconcileSelected([
            $matched->id,
            $missing->id,
            $conflict->id,
        ], null);

        $this->assertSame(SyncRunStatus::Partial, $run->status);
        $this->assertSame(1, $run->succeeded_items);
        $this->assertSame(2, $run->conflict_items);
        $this->assertSame(0, $this->gateway->writeCalls);
        $this->assertSame(3, $this->gateway->inspectCalls);
        $this->assertDatabaseHas('sync_run_items', ['result_code' => 'matched']);
        $this->assertDatabaseHas('sync_run_items', ['result_code' => 'remote_missing']);
        $this->assertDatabaseHas('sync_run_items', ['result_code' => 'conflict']);
    }

    private function order(int $providerOrderId, string $tracking = ''): Order
    {
        $tracking = $tracking !== '' ? $tracking : 'TRACK-'.$providerOrderId;
        $order = Order::create([
            'internal_order_number' => 'ORCH-'.$providerOrderId,
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
            'tracking_number' => $tracking,
            'shipped_at' => '2026-08-28T00:30:00Z',
        ]);
        $order->manualSync()->create(['website_sync_status' => 'pending']);

        return $order->fresh(['shipment', 'items', 'externalReferences', 'manualSync']);
    }
}

final class OrchestrationFakeGateway implements WooCommerceTrackingGateway
{
    public int $writeCalls = 0;

    public int $inspectCalls = 0;

    public bool $failWrites = false;

    /** @var array<int,array{tracking_number:string,provider:string,has_tracking:bool}> */
    public array $remote = [];

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
        $this->inspectCalls++;

        return $this->remote[$providerOrderId] ?? [
            'tracking_number' => '',
            'provider' => '',
            'has_tracking' => false,
        ];
    }

    public function syncTracking(
        int $providerOrderId,
        string $providerSlug,
        string $trackingNumber,
        string $shipDate,
        array $lineItems = [],
    ): array {
        $this->writeCalls++;
        if ($this->failWrites) {
            throw new RuntimeException('synthetic transient failure');
        }

        return ['written' => true, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
