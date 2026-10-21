<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OverseaManualShipmentService
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly WooCommerceTrackingOutboundService $trackingOutbound,
    ) {}

    /**
     * @return array{order:Order,sync:array<string,mixed>}
     */
    public function confirmUsSm(Order $order, string $trackingNumber, ?Authenticatable $actor): array
    {
        $trackingNumber = trim($trackingNumber);
        abort_if($trackingNumber === '', 422, '世迈发货必须填写真实 Tracking。');

        $order->loadMissing(['shipment.warehouse', 'shipment.overseaReview']);
        $shipment = $order->shipment;
        abort_unless($shipment, 422, '订单没有 Shipment。');

        $code = OverseaWarehouseReviewService::canonicalWarehouseCode($shipment->warehouse?->code);
        abort_unless($code === 'US-SM', 422, '仅 US-SM / 世迈允许使用逐单确认发货。');
        abort_unless($shipment->shipment_status === 'pending', 409, '只有待发货 Shipment 可以逐单确认发货。');

        $review = $shipment->overseaReview;
        abort_unless(
            $review?->status === 'approved'
                && (int) $review->reviewed_warehouse_id === (int) $shipment->warehouse_id,
            409,
            '订单尚未完成当前仓库审核。',
        );

        $carrier = trim((string) $shipment->carrier);
        abort_if($carrier === '', 422, '世迈发货缺少 Carrier。');

        $shipped = DB::transaction(function () use ($order, $shipment, $trackingNumber, $carrier, $actor): Order {
            $updated = $this->shipments->markShipped($order, [
                'warehouse_id' => $shipment->warehouse_id,
                'carrier' => $carrier,
                'service' => $shipment->service,
                'tracking_number' => $trackingNumber,
                'shipped_at' => now()->utc()->toIso8601String(),
                'logistics_note' => $shipment->logistics_note,
            ], $actor);

            OverseaWarehouseReview::query()
                ->where('order_id', $order->id)
                ->where('status', 'approved')
                ->where('reviewed_warehouse_id', $shipment->warehouse_id)
                ->update(['progress' => 'shipped']);

            return $updated->fresh([
                'shipment.overseaReview',
                'items',
                'externalReferences',
                'fulfillmentParent.externalReferences',
            ]);
        });

        try {
            $sync = $this->trackingOutbound->pushOne($shipped, $actor);
        } catch (Throwable $e) {
            $sync = [
                'status' => 'attention',
                'reason' => 'woocommerce_tracking_push_exception',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'order' => $shipped->fresh(['shipment.overseaReview']),
            'sync' => $sync,
        ];
    }
}
