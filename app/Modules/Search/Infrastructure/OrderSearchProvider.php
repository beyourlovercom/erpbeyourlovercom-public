<?php

namespace App\Modules\Search\Infrastructure;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Search\Application\Contracts\SearchProvider;

final class OrderSearchProvider implements SearchProvider
{
    public function key(): string { return 'orders'; }

    public function label(): string { return '订单'; }

    public function permission(): string { return 'orders.view'; }

    public function viewAllHref(string $query): string
    {
        return '/orders?search='.rawurlencode($query);
    }

    public function search(string $query, int $limit): array
    {
        $needle = trim($query);
        $prefix = $needle.'%';

        $builder = Order::query()
            ->with([
                'shipment:id,order_id,tracking_number,carrier',
                'externalReferences:id,order_id,provider,reference',
                'fulfillmentParent:id,erp_order_id,external_order_number',
            ])
            ->whereDoesntHave('externalReferences', fn ($refs) => $refs->where('provider', 'historical_logistics'));

        if (preg_match('/^dbid:(\d+)$/i', $needle, $match) === 1) {
            $builder->whereKey((int) $match[1]);
        } elseif (ctype_digit($needle)) {
            $builder->where(function ($q) use ($needle): void {
                $q->where('external_order_number', $needle)
                    ->orWhereHas('externalReferences', fn ($refs) => $refs->where('reference', $needle))
                    ->orWhereHas('shipment', fn ($shipment) => $shipment->where('tracking_number', $needle));
            });
        } else {
            $builder->where(function ($q) use ($prefix): void {
                $q->where('internal_order_number', 'like', $prefix)
                    ->orWhere('external_order_number', 'like', $prefix)
                    ->orWhere('erp_order_id', 'like', $prefix)
                    ->orWhereHas('externalReferences', fn ($refs) => $refs->where('reference', 'like', $prefix))
                    ->orWhereHas('shipment', fn ($shipment) => $shipment->where('tracking_number', 'like', $prefix));
            });
        }

        $rows = $builder->limit(30)->get([
            'id', 'fulfillment_parent_id', 'internal_order_number', 'erp_order_id',
            'external_order_number', 'order_kind', 'order_status', 'order_date',
        ])->sortBy(fn (Order $order): array => [$this->rank($order, $needle), (int) $order->id])
            ->take($limit)
            ->values();

        return $rows->map(function (Order $order): array {
            $tracking = trim((string) $order->shipment?->tracking_number);
            $label = trim((string) $order->erp_order_id)
                ?: (trim((string) $order->external_order_number) !== '' ? (string) $order->external_order_number : 'Order #'.$order->id);

            $parts = [];
            if (trim((string) $order->external_order_number) !== '') $parts[] = '#'.$order->external_order_number;
            if ($tracking !== '') $parts[] = $tracking;
            if ($order->fulfillment_parent_id !== null) $parts[] = '拆分履约';

            return [
                'id' => 'order:'.$order->id,
                'label' => $label,
                'subtitle' => $parts !== [] ? implode(' · ', $parts) : '订单详情',
                'href' => '/orders/'.$order->id,
                'meta' => [
                    'order_id' => (int) $order->id,
                    'order_kind' => (string) ($order->order_kind?->value ?? $order->order_kind),
                    'tracking_number' => $tracking !== '' ? $tracking : null,
                ],
            ];
        })->all();
    }

    private function rank(Order $order, string $needle): int
    {
        if (preg_match('/^dbid:(\d+)$/i', $needle, $match) === 1 && (int) $match[1] === (int) $order->id) return 0;

        $n = mb_strtolower($needle);
        foreach ([
            (string) $order->erp_order_id,
            (string) $order->external_order_number,
            (string) $order->internal_order_number,
            (string) $order->shipment?->tracking_number,
        ] as $value) {
            if ($value !== '' && mb_strtolower($value) === $n) return 0;
        }
        foreach ($order->externalReferences as $reference) {
            if (mb_strtolower((string) $reference->reference) === $n) return 0;
        }

        return 2;
    }
}
