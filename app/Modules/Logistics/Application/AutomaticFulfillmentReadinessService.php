<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Logistics\Infrastructure\Models\ShipmentItemPackagingSnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;

final class AutomaticFulfillmentReadinessService
{
    /** @return array<string,mixed> */
    public function assess(Order $order): array
    {
        $order->loadMissing(['externalReferences', 'items', 'shipment']);

        $facts = [
            'order_id' => (int) $order->getKey(),
            'order_status' => $order->order_status?->value ?? (string) $order->order_status,
            'historical' => false,
            'item_count' => $order->items->count(),
            'resolved_product_items' => $order->items->whereNotNull('product_id')->count(),
            'shipment_id' => $order->shipment?->id,
            'warehouse_id' => $order->shipment?->warehouse_id,
            'reservation_id' => null,
            'reservation_warehouse_id' => null,
            'packaging_snapshot_items' => 0,
            'carrier' => $order->shipment?->carrier,
            'service' => $order->shipment?->service,
            'automatic_dispatch_supported' => false,
        ];

        $historical = $order->externalReferences->contains(
            fn ($reference): bool => (string) $reference->provider === 'historical_logistics'
        );
        if ($historical) {
            $facts['historical'] = true;

            return $this->result('excluded', 'excluded', ['historical_excluded'], $facts);
        }

        $blockers = [];
        $stage = null;

        if (($facts['order_status'] ?? null) !== 'pending_shipment') {
            $this->block($blockers, $stage, 'order_not_pending', 'order');
        }

        $hasUnresolvedProduct = $order->items->contains(
            fn ($item): bool => (int) $item->quantity > 0 && $item->product_id === null
        );
        if ($hasUnresolvedProduct) {
            $this->block($blockers, $stage, 'unresolved_product', 'order');
        }

        $shipment = $order->shipment;
        if ($shipment === null) {
            $this->block($blockers, $stage, 'shipment_draft_missing', 'allocation');

            return $this->result('blocked', $stage ?? 'allocation', $blockers, $facts);
        }

        if ($shipment->warehouse_id === null) {
            $this->block($blockers, $stage, 'warehouse_missing', 'allocation');
        }

        $reservation = InventoryReservation::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'reserved')
            ->latest('id')
            ->first();
        if ($reservation === null) {
            $this->block($blockers, $stage, 'active_reservation_missing', 'allocation');
        } else {
            $facts['reservation_id'] = (int) $reservation->id;
            $facts['reservation_warehouse_id'] = (int) $reservation->warehouse_id;
            if ($shipment->warehouse_id !== null && (int) $reservation->warehouse_id !== (int) $shipment->warehouse_id) {
                $this->block($blockers, $stage, 'reservation_warehouse_mismatch', 'allocation');
            }
        }

        $itemIds = $order->items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $packagingCount = $itemIds === [] ? 0 : ShipmentItemPackagingSnapshot::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('order_item_id', $itemIds)
            ->distinct('order_item_id')
            ->count('order_item_id');
        $facts['packaging_snapshot_items'] = $packagingCount;
        if ($packagingCount !== count($itemIds)) {
            $this->block($blockers, $stage, 'packaging_snapshot_missing', 'packaging');
        }

        if (trim((string) $shipment->carrier) === '' || trim((string) $shipment->service) === '') {
            $this->block($blockers, $stage, 'carrier_decision_missing', 'carrier');
        }

        if ($blockers === []) {
            $this->block($blockers, $stage, 'automatic_dispatch_unavailable', 'dispatch');
        }

        return $this->result('blocked', $stage ?? 'dispatch', $blockers, $facts);
    }

    /** @param list<string> $blockers */
    private function block(array &$blockers, ?string &$stage, string $code, string $candidateStage): void
    {
        $blockers[] = $code;
        $stage ??= $candidateStage;
    }

    /** @param list<string> $blockers @param array<string,mixed> $facts @return array<string,mixed> */
    private function result(string $status, string $stage, array $blockers, array $facts): array
    {
        return [
            'status' => $status,
            'stage' => $stage,
            'blockers' => $blockers,
            'facts' => $facts,
            'ready_to_dispatch' => false,
        ];
    }
}
