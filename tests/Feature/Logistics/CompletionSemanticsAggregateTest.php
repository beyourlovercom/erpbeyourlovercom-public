<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\ShipmentCompletionSemantics;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompletionSemanticsAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_order_is_partial_until_all_active_shipments_have_shipped_carrier_and_tracking(): void
    {
        [$parent, $a, $b] = $this->splitScenario();
        $this->shipment($a, 'shipped', 'USPS', 'TRACK-A');
        $this->shipment($b, 'pending', null, null);

        $this->assertSame(ShipmentCompletionSemantics::PartiallyShipped, $this->semantics()->aggregateForOrder($parent));

        $b->shipment()->update([
            'shipment_status' => 'shipped',
            'carrier' => '4PX',
            'tracking_number' => 'TRACK-B',
        ]);

        $this->assertSame(ShipmentCompletionSemantics::Completed, $this->semantics()->aggregateForOrder($parent->fresh()));
    }

    public function test_all_fulfilled_with_some_delivered_is_partial_delivery_not_full_delivery(): void
    {
        [$parent, $a, $b] = $this->splitScenario();
        $shipmentA = $this->shipment($a, 'completed', 'USPS', 'TRACK-A');
        $shipmentB = $this->shipment($b, 'shipped', '4PX', 'TRACK-B');
        $this->delivery($shipmentA, 'delivered');
        $this->delivery($shipmentB, 'in_transit');

        $this->assertSame(ShipmentCompletionSemantics::PartiallyDelivered, $this->semantics()->aggregateForOrder($parent));
    }

    public function test_completed_delivered_requires_every_active_shipment_to_have_real_delivered_evidence(): void
    {
        [$parent, $a, $b] = $this->splitScenario();
        $shipmentA = $this->shipment($a, 'completed', 'USPS', 'TRACK-A');
        $shipmentB = $this->shipment($b, 'completed', '4PX', 'TRACK-B');
        $this->delivery($shipmentA, 'delivered');
        $this->delivery($shipmentB, 'delivered');

        $this->assertSame(ShipmentCompletionSemantics::CompletedDelivered, $this->semantics()->aggregateForOrder($parent));
    }

    public function test_missing_child_shipment_fails_closed_instead_of_completing_parent(): void
    {
        [$parent, $a] = $this->splitScenario();
        $shipmentA = $this->shipment($a, 'completed', 'USPS', 'TRACK-A');
        $this->delivery($shipmentA, 'delivered');

        $this->assertSame(ShipmentCompletionSemantics::PartiallyShipped, $this->semantics()->aggregateForOrder($parent));
    }

    /** @return array{0:Order,1:Order,2:Order} */
    private function splitScenario(): array
    {
        $suffix = uniqid();
        $parent = $this->order('ROOT-'.$suffix, null, null, 'customer');
        $a = $this->order('A-'.$suffix, $parent->id, 'A', 'fulfillment_derived');
        $b = $this->order('B-'.$suffix, $parent->id, 'B', 'fulfillment_derived');

        return [$parent, $a, $b];
    }

    private function order(string $suffix, ?int $parentId, ?string $fulfillmentSuffix, string $kind): Order
    {
        return Order::create([
            'customer_id' => null,
            'fulfillment_parent_id' => $parentId,
            'fulfillment_suffix' => $fulfillmentSuffix,
            'internal_order_number' => 'AGG-'.$suffix,
            'erp_order_id' => '007-0914-c'.$suffix,
            'source_type' => $kind === 'customer' ? 'woocommerce' : 'manual_other',
            'order_kind' => $kind,
            'external_order_number' => $kind === 'customer' ? $suffix : null,
            'order_date' => '2026-09-14',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
    }

    private function shipment(Order $order, string $status, ?string $carrier, ?string $tracking): Shipment
    {
        return $order->shipment()->create([
            'shipment_status' => $status,
            'carrier' => $carrier,
            'tracking_number' => $tracking,
            'shipped_at' => in_array($status, ['shipped', 'completed', 'exception'], true) ? now() : null,
        ]);
    }

    private function delivery(Shipment $shipment, string $status): void
    {
        $shipment->billingEvidence()->create([
            'delivery_status' => $status,
            'data_source' => 'delivery_evidence',
            'provider_reference' => 'AGG-EVIDENCE-'.uniqid(),
        ]);
    }

    private function semantics(): ShipmentCompletionSemantics
    {
        return app(ShipmentCompletionSemantics::class);
    }
}
