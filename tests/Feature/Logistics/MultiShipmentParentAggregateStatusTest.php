<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\ShipmentCompletionSemantics;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\WooCommerceOrderStatusOutboundService;
use App\Modules\Sync\Application\WooCommerceOrderStatusUnsupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MultiShipmentParentAggregateStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_parent_progresses_processing_completed_and_completed_delivered_with_woo_sync(): void
    {
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.connection_key' => 'shop-a',
            'services.woocommerce.completed_delivered_status' => 'completed-delivered',
        ]);
        $gateway = new AggregateStatusFakeGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);

        [$parent, $a, $b] = $this->splitScenario();
        $shipmentA = $this->shipment($a, 'shipped', 'USPS', 'TRACK-A');
        $shipmentB = $this->shipment($b, 'pending', null, null);

        $partial = app(WooCommerceOrderStatusOutboundService::class)->pushOne($a);
        $this->assertSame(ShipmentCompletionSemantics::PartiallyShipped, $partial['aggregate']);
        $this->assertSame('processing', $partial['woo_status']);
        $this->assertSame('pending_shipment', $parent->fresh()->order_status->value);
        $this->assertDatabaseHas('order_operational_markers', ['order_id' => $parent->id, 'marker' => 'partially_shipped']);

        $shipmentB->update(['shipment_status' => 'shipped', 'carrier' => '4PX', 'tracking_number' => 'TRACK-B', 'shipped_at' => now()]);
        $completed = app(WooCommerceOrderStatusOutboundService::class)->pushOne($b->fresh());
        $this->assertSame(ShipmentCompletionSemantics::Completed, $completed['aggregate']);
        $this->assertSame('completed', $completed['woo_status']);
        $this->assertSame('completed', $parent->fresh()->order_status->value);
        $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $parent->id, 'marker' => 'partially_shipped']);

        $this->delivery($shipmentA, 'delivered');
        $this->delivery($shipmentB->fresh(), 'delivered');
        $delivered = app(WooCommerceOrderStatusOutboundService::class)->pushOne($parent->fresh());
        $this->assertSame(ShipmentCompletionSemantics::CompletedDelivered, $delivered['aggregate']);
        $this->assertSame('completed-delivered', $delivered['woo_status']);
        $this->assertSame('completed_delivered', $parent->fresh()->order_status->value);
        $this->assertSame(['processing', 'completed', 'completed-delivered'], $gateway->statuses);
    }

    public function test_unregistered_completed_delivered_status_is_terminal_and_does_not_retry(): void
    {
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.connection_key' => 'shop-a',
            'services.woocommerce.completed_delivered_status' => 'completed-delivered',
        ]);
        $gateway = new AggregateStatusFakeGateway;
        $gateway->unsupported = 'completed-delivered';
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);

        [$parent, $a, $b] = $this->splitScenario();
        $shipmentA = $this->shipment($a, 'completed', 'USPS', 'TRACK-A');
        $shipmentB = $this->shipment($b, 'completed', '4PX', 'TRACK-B');
        $this->delivery($shipmentA, 'delivered');
        $this->delivery($shipmentB, 'delivered');

        $result = app(WooCommerceOrderStatusOutboundService::class)->pushOne($parent);

        $this->assertSame('unsupported', $result['status']);
        $this->assertSame('completed-delivered', $result['woo_status']);
        $this->assertDatabaseHas('sync_outbox', [
            'operation' => 'woocommerce.order_status_outbound',
            'status' => 'failed',
            'attempts' => 1,
            'last_error_code' => 'woocommerce_status_unsupported',
        ]);
    }

    private function splitScenario(): array
    {
        $parent = Order::create([
            'internal_order_number' => 'AGG-ROOT-9001',
            'erp_order_id' => '007-0915-c9001',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => '9001',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $parent->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => '9001',
        ]);
        $a = $this->child($parent, 'A');
        $b = $this->child($parent, 'B');

        return [$parent, $a, $b];
    }

    private function child(Order $parent, string $suffix): Order
    {
        return Order::create([
            'fulfillment_parent_id' => $parent->id,
            'fulfillment_suffix' => $suffix,
            'internal_order_number' => 'AGG-9001-'.$suffix,
            'erp_order_id' => '007-0915-c9001'.$suffix,
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function shipment(Order $order, string $status, ?string $carrier, ?string $tracking): Shipment
    {
        return $order->shipment()->create([
            'shipment_status' => $status,
            'carrier' => $carrier,
            'tracking_number' => $tracking,
            'shipped_at' => $status === 'shipped' ? now() : null,
        ]);
    }

    private function delivery(Shipment $shipment, string $status): void
    {
        $shipment->billingEvidence()->create([
            'delivery_status' => $status,
            'data_source' => 'aftership_branded_page',
            'provider_reference' => 'EVIDENCE-'.$shipment->id,
        ]);
    }
}

final class AggregateStatusFakeGateway implements WooCommerceTrackingGateway
{
    /** @var array<int,string> */
    public array $statuses = [];

    public ?string $unsupported = null;

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a'];
    }

    public function inspectTracking(int $providerOrderId): array
    {
        return ['tracking_number' => '', 'provider' => '', 'has_tracking' => false];
    }

    public function syncTracking(int $providerOrderId, string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems = []): array
    {
        return ['written' => false, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        $this->statuses[] = $status;
        if ($this->unsupported === $status) {
            throw new WooCommerceOrderStatusUnsupportedException('status is not registered');
        }

        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
