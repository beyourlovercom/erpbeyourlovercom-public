<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;

final class ShipmentCompletionSemantics
{
    public const string Processing = 'processing';

    public const string PartiallyShipped = 'partially_shipped';

    public const string Completed = 'completed';

    public const string PartiallyDelivered = 'partially_delivered';

    public const string CompletedDelivered = 'completed_delivered';

    public function assertShipmentHasFulfillmentEvidence(Model $shipment, int $statusCode): void
    {
        abort_unless(
            filled($shipment->carrier) && filled($shipment->tracking_number),
            $statusCode,
            '完结需要已确认承运商和追踪号',
        );
    }

    public function isFulfillmentComplete(Model $shipment): bool
    {
        $status = strtolower(trim((string) $shipment->shipment_status));

        return in_array($status, ['shipped', 'exception', 'completed'], true)
            && filled($shipment->carrier)
            && filled($shipment->tracking_number);
    }

    public function hasDeliveredEvidence(Model $shipment): bool
    {
        if (! $this->isFulfillmentComplete($shipment)) {
            return false;
        }

        $billing = $shipment->relationLoaded('billingEvidence')
            ? $shipment->getRelation('billingEvidence')
            : $shipment->billingEvidence()->first();

        return strtolower(trim((string) ($billing?->delivery_status ?? ''))) === 'delivered';
    }

    public function aggregateForOrder(Order $order): string
    {
        $root = $order->fulfillment_parent_id
            ? $order->fulfillmentParent()->firstOrFail()
            : $order;
        $root->loadMissing(['shipment.billingEvidence', 'originalFulfillmentChildren.shipment.billingEvidence']);

        if ($root->originalFulfillmentChildren->isNotEmpty()) {
            return $this->aggregate($root->originalFulfillmentChildren->map(fn (Order $child) => $child->shipment));
        }

        return $this->aggregate([$root->shipment]);
    }

    public function aggregate(iterable $shipments): string
    {
        $active = collect($shipments)
            ->filter(fn ($shipment): bool => ! ($shipment instanceof Model)
                || strtolower(trim((string) $shipment->shipment_status)) !== 'cancelled')
            ->values();

        if ($active->isEmpty()) {
            return self::Processing;
        }

        $fulfilled = $active->filter(fn ($shipment): bool => $shipment instanceof Model
            && $this->isFulfillmentComplete($shipment))->count();

        if ($fulfilled === 0) {
            return self::Processing;
        }
        if ($fulfilled < $active->count()) {
            return self::PartiallyShipped;
        }

        $delivered = $active->filter(fn ($shipment): bool => $shipment instanceof Model
            && $this->hasDeliveredEvidence($shipment))->count();

        if ($delivered === 0) {
            return self::Completed;
        }
        if ($delivered < $active->count()) {
            return self::PartiallyDelivered;
        }

        return self::CompletedDelivered;
    }
}
