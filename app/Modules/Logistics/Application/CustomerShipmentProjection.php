<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\Models\Shipment;
use Illuminate\Support\Collection;

/** Read-only Customer projection of canonical Logistics shipment truth. */
final class CustomerShipmentProjection
{
    public function countForCustomer(int $customerId): int
    {
        return Shipment::query()
            ->whereHas('order', fn ($query) => $query->where('customer_id', $customerId)->where('order_kind', 'customer'))
            ->count();
    }

    /** @return array<int, array<string, mixed>> */
    public function recordsForCustomer(int $customerId, int $limit = 20): array
    {
        return Shipment::query()
            ->whereHas('order', fn ($query) => $query->where('customer_id', $customerId)->where('order_kind', 'customer'))
            ->with('order')
            ->latest('shipped_at')->latest('id')->limit($limit)->get()
            ->map(fn (Shipment $shipment): array => [
                'id' => $shipment->id,
                'erp_order_id' => $shipment->order?->erp_order_id,
                'external_order_number' => $shipment->order?->external_order_number,
                'order_number' => $shipment->order?->erp_order_id,
                'order_id' => $shipment->order_id,
                'carrier' => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
                'shipped_at' => $shipment->shipped_at?->toDateString(),
                'status' => $shipment->shipment_status,
            ])->all();
    }

    /** @param iterable<int, int|object> $orderIds */
    public function statusesForOrders(iterable $orderIds): array
    {
        $ids = $this->ids($orderIds);
        if ($ids->isEmpty()) return [];

        return Shipment::query()->whereIn('order_id', $ids)
            ->orderByDesc('shipped_at')->orderByDesc('id')
            ->get(['order_id', 'shipment_status'])
            ->unique('order_id')->pluck('shipment_status', 'order_id')->all();
    }

    /** @param iterable<int, int|object> $customerIds */
    public function countsForCustomers(iterable $customerIds): array
    {
        $ids = $this->ids($customerIds);
        if ($ids->isEmpty()) return [];

        return Shipment::query()
            ->whereHas('order', fn ($query) => $query->whereIn('customer_id', $ids)->where('order_kind', 'customer'))
            ->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->selectRaw('orders.customer_id, COUNT(shipments.id) as shipment_count')
            ->groupBy('orders.customer_id')->pluck('shipment_count', 'orders.customer_id')
            ->map(fn (mixed $count): int => (int) $count)->all();
    }

    /** @param iterable<int, int|object> $values */
    private function ids(iterable $values): Collection
    {
        return collect($values)->map(fn (mixed $value): int => (int) (is_object($value) ? $value->id : $value))
            ->filter()->unique()->values();
    }
}
