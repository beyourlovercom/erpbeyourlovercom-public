<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class ShipmentAggregateStatusService
{
    public function __construct(private readonly ShipmentCompletionSemantics $semantics) {}

    /** @return array{root:Order,aggregate:string,woo_status:string,exception:bool,state_fingerprint:string} */
    public function reconcile(Order $order, ?Authenticatable $actor = null): array
    {
        return DB::transaction(function () use ($order, $actor): array {
            $root = $order->fulfillment_parent_id
                ? Order::query()->whereKey($order->fulfillment_parent_id)->lockForUpdate()->firstOrFail()
                : Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $root->loadMissing(['shipment.billingEvidence', 'shipmentAttempts', 'originalFulfillmentChildren.shipment.billingEvidence', 'originalFulfillmentChildren.shipmentAttempts']);
            $aggregate = $this->semantics->aggregateForOrder($root);
            $sourceType = $root->source_type instanceof \BackedEnum ? $root->source_type->value : (string) $root->source_type;
            if ($sourceType === 'woocommerce') {
                $targetStatus = match ($aggregate) {
                    ShipmentCompletionSemantics::Completed, ShipmentCompletionSemantics::PartiallyDelivered => OrderStatus::Completed,
                    ShipmentCompletionSemantics::CompletedDelivered => OrderStatus::CompletedDelivered,
                    default => OrderStatus::PendingShipment,
                };
                if ($root->order_status !== $targetStatus) {
                    $root->forceFill(['order_status' => $targetStatus])->save();
                }
            }

            $segments = $root->originalFulfillmentChildren->isNotEmpty()
                ? $root->originalFulfillmentChildren->values()
                : collect([$root]);
            $shipments = $segments->pluck('shipment')->filter();
            $hasException = $shipments->contains(fn ($shipment): bool => $shipment->shipment_status === 'exception');
            $stateFingerprint = hash('sha256', (string) json_encode(
                $segments->map(function (Order $segment): array {
                    $shipment = $segment->shipment;
                    $billing = $shipment?->billingEvidence;

                    return [
                        'order_id' => (int) $segment->getKey(),
                        'shipment_id' => $shipment ? (int) $shipment->getKey() : null,
                        'shipment_status' => $shipment?->shipment_status,
                        'carrier' => $shipment?->carrier,
                        'tracking_number' => $shipment?->tracking_number,
                        'delivery_status' => $billing?->delivery_status,
                        'delivery_evidence' => is_array($billing?->raw_evidence) ? ($billing->raw_evidence['delivery_evidence'] ?? null) : null,
                        'shipment_attempt_count' => $segment->shipmentAttempts->count(),
                    ];
                })->sortBy('order_id')->values()->all(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $wanted = array_values(array_filter([
                $aggregate === ShipmentCompletionSemantics::PartiallyShipped ? 'partially_shipped' : null,
                $aggregate === ShipmentCompletionSemantics::PartiallyDelivered ? 'partially_delivered' : null,
                $hasException ? 'fulfillment_exception' : null,
            ]));
            $managed = ['partially_shipped', 'partially_delivered', 'fulfillment_exception'];
            $root->operationalMarkers()->whereIn('marker', array_diff($managed, $wanted))->delete();
            foreach ($wanted as $marker) {
                $root->operationalMarkers()->firstOrCreate(
                    ['marker' => $marker],
                    ['marked_by' => $actor?->getAuthIdentifier(), 'marked_at' => now()],
                );
            }

            return [
                'root' => $root->fresh(['operationalMarkers']),
                'aggregate' => $aggregate,
                'woo_status' => $this->wooStatus($aggregate),
                'exception' => $hasException,
                'state_fingerprint' => $stateFingerprint,
            ];
        });
    }

    public function wooStatus(string $aggregate): string
    {
        return match ($aggregate) {
            ShipmentCompletionSemantics::Completed, ShipmentCompletionSemantics::PartiallyDelivered => 'completed',
            ShipmentCompletionSemantics::CompletedDelivered => trim((string) config('services.woocommerce.completed_delivered_status', 'completed-delivered')) ?: 'completed-delivered',
            default => 'processing',
        };
    }
}
