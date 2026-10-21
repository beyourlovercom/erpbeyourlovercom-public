<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only KOL fulfillment projection. Never delegate this route to general
 * Logistics queries, exports or shipment mutations.
 */
final class KolLogisticsController
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor && $actor->can('kol.view'), 403);
        // Team-wide visibility requires an explicit KOL management permission.
        // Independent Logistics rights must never enlarge a specialist's KOL scope.
        $isManager = $actor->can('kol.views.manage');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:all,pending,shipped,exception,completed'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');

        $query = Order::query()
            ->where('order_kind', OrderKind::KolFulfillment->value)
            ->with(['shipment.warehouse', 'owner']);

        if (! $isManager) {
            $query->where('owner_id', (int) $actor->getAuthIdentifier());
        }

        // The owner/kind predicates are always ANDed outside search, status,
        // pagination and any joins. Never search recipient/contact columns.
        if ($search !== '') {
            if (preg_match('/^dbid:([0-9]+)$/i', $search, $match)) {
                $query->whereKey((int) $match[1]);
            } else {
                $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $query->where(function ($scoped) use ($needle): void {
                    $scoped->where('erp_order_id', 'like', "%{$needle}%")
                        ->orWhere('external_order_number', 'like', "%{$needle}%");
                });
            }
        }

        if ($status === 'pending') {
            $query->where(function ($scoped): void {
                $scoped->whereDoesntHave('shipment')
                    ->orWhereHas('shipment', fn ($ship) => $ship->where('shipment_status', 'pending'));
            });
        } elseif ($status !== 'all') {
            $query->whereHas('shipment', fn ($ship) => $ship->where('shipment_status', $status));
        }

        $orders = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $orders->through(static function (Order $order): array {
            $shipment = $order->shipment;

            return [
                'id' => (int) $order->id,
                'erp_order_id' => $order->erp_order_id,
                'external_order_number' => $order->external_order_number,
                'order_date' => $order->order_date?->format('Y-m-d'),
                'owner' => $order->owner?->name,
                'shipment_status' => $shipment?->shipment_status ?? 'pending',
                'warehouse' => $shipment?->warehouse?->name,
                'carrier' => $shipment?->carrier,
                'tracking_number' => $shipment?->tracking_number,
                'shipped_at' => $shipment?->shipped_at?->toDateTimeString(),
            ];
        });

        return Inertia::render('Orders/KolLogistics', [
            'orders' => $orders->toArray(),
            'filters' => ['search' => $search, 'status' => $status],
            'isManager' => $isManager,
        ]);
    }
}
