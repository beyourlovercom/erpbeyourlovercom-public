<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class CompletionSemanticsCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_completion_accepts_shipped_carrier_and_tracking_without_delivery_evidence(): void
    {
        [$order, $shipment] = $this->scenario();

        app(ShipmentService::class)->markCompleted($order, null);

        $this->assertSame('completed', $shipment->fresh()->shipment_status);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'shipment.completed',
            'entity_id' => (string) $shipment->id,
        ]);
    }

    public function test_direct_completion_does_not_require_delivered_evidence_and_preserves_it(): void
    {
        foreach (['in_transit', 'exception', 'delivered'] as $deliveryStatus) {
            [$order, $shipment] = $this->scenario();
            $billing = $this->billing($shipment, $deliveryStatus);

            app(ShipmentService::class)->markCompleted($order, null);

            $this->assertSame('completed', $shipment->fresh()->shipment_status);
            $this->assertSame($deliveryStatus, $billing->fresh()->delivery_status);
        }
    }

    public function test_direct_completion_requires_both_carrier_and_tracking(): void
    {
        foreach ([['', 'TRACK123'], ['USPS', '']] as [$carrier, $tracking]) {
            [$order, $shipment] = $this->scenario(carrier: $carrier, tracking: $tracking);

            $this->assertHttpStatus(
                fn () => app(ShipmentService::class)->markCompleted($order, null),
                409,
                '承运商和追踪号',
            );

            $this->assertSame('shipped', $shipment->fresh()->shipment_status);
        }
    }

    public function test_review_completion_accepts_shipped_exception_with_tracking_without_delivery_evidence_or_exact_shipped_at(): void
    {
        [$order, $shipment] = $this->scenario('exception', null);
        $review = $this->review($order);

        app(ShipmentService::class)->decideReview($order, 'completed', 'fulfillment shipped', null);

        $this->assertSame('completed', $shipment->fresh()->shipment_status);
        $this->assertNull($shipment->fresh()->shipped_at);
        $this->assertSame('decided', $review->fresh()->status);
        $this->assertSame('completed', $review->fresh()->destination);
        $this->assertSame('completed', $order->fresh()->order_status->value);
    }

    /** @return array{0:Order,1:Shipment} */
    private function scenario(
        string $shipmentStatus = 'shipped',
        ?string $shippedAt = '2026-09-14 02:00:00',
        string $carrier = 'USPS',
        string $tracking = 'TRACK123',
    ): array {
        $suffix = uniqid();
        $order = Order::create([
            'internal_order_number' => 'COMPLETION-'.$suffix,
            'erp_order_id' => '007-0914-c'.$suffix,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'COMPLETION-'.$suffix,
            'order_date' => '2026-09-14',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $shipment = $order->shipment()->create([
            'shipment_status' => $shipmentStatus,
            'carrier' => $carrier,
            'tracking_number' => $tracking,
            'shipped_at' => $shippedAt,
        ]);

        return [$order, $shipment];
    }

    private function billing(Shipment $shipment, string $deliveryStatus): Model
    {
        return $shipment->billingEvidence()->create([
            'delivery_status' => $deliveryStatus,
            'data_source' => 'carrier_import',
            'provider_reference' => 'EVIDENCE-'.uniqid(),
        ]);
    }

    private function review(Order $order): LogisticsReview
    {
        return LogisticsReview::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'requested_at' => now(),
            'reason' => 'completion semantics review',
            'trigger' => 'uat',
        ]);
    }

    private function assertHttpStatus(callable $callback, int $status, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected HTTP exception was not thrown.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
