<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\InventoryReservationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssistedFulfillmentService
{
    public function __construct(
        private readonly WarehouseRecommendationService $recommendations,
        private readonly FulfillmentPlanPreviewService $fulfillmentPlans,
        private readonly InventoryReservationService $reservations,
        private readonly ShipmentService $shipments,
        private readonly FulfillmentPaymentGate $paymentGate,
    ) {}

    public function adoptRecommendation(Model $order, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $actor): Model {
            $lockedOrder = $order::query()->with(['address', 'items', 'shipment'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->paymentGate->assertAllowed($lockedOrder);
            $items = $lockedOrder->items->map(fn ($item): array => ['product_id' => $item->product_id, 'quantity' => (int) $item->quantity])->values()->all();
            $productIds = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $context = array_replace(
                $this->recommendations->prepare($productIds),
                $this->fulfillmentPlans->prepare($productIds),
            );
            $recommendation = $this->recommendations->recommend(
                $lockedOrder->address?->country,
                $items,
                $lockedOrder->address?->postal_code,
                [],
                [
                    'province_state' => $lockedOrder->address?->province_state,
                    'city' => $lockedOrder->address?->city,
                    'address_line_1' => $lockedOrder->address?->address_line_1,
                    'address_line_2' => $lockedOrder->address?->address_line_2,
                ],
                $context,
            );
            if (($recommendation['status'] ?? null) !== 'recommended' || empty($recommendation['warehouse']['id'])) {
                abort(409, (string) ($recommendation['reason_label'] ?? '当前无法采用系统推荐仓库。'));
            }

            $warehouseId = (int) $recommendation['warehouse']['id'];
            try {
                $this->reservations->reserve(
                    (int) $lockedOrder->getKey(),
                    $warehouseId,
                    'assist-'.$lockedOrder->getKey().'-'.Str::uuid(),
                    null,
                    $actor,
                );
            } catch (\DomainException $e) {
                abort(409, $e->getMessage());
            }

            return $this->shipments->saveDraft($lockedOrder, [
                'warehouse_id' => $warehouseId,
                'warehouse_selection_source' => 'recommended',
            ], $actor);
        });
    }

    public function saveDraft(Model $order, array $attributes, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $this->paymentGate->assertAllowed($order);
            $active = $this->reservations->activeForOrder((int) $order->getKey());
            $requestedWarehouseId = isset($attributes['warehouse_id']) ? (int) $attributes['warehouse_id'] : null;

            if ($active && $requestedWarehouseId === (int) $active['warehouse_id']) {
                $currentSource = $order->shipment?->warehouse_selection_source;
                $attributes['warehouse_selection_source'] = $currentSource === 'automatic' ? 'automatic' : 'recommended';
            } else {
                if ($active) {
                    $this->reservations->release((int) $active['id'], $actor, 'manual_warehouse_override');
                }

                if ($requestedWarehouseId !== null) {
                    try {
                        $this->reservations->reserve(
                            (int) $order->getKey(),
                            $requestedWarehouseId,
                            'manual-'.$order->getKey().'-'.Str::uuid(),
                            null,
                            $actor,
                        );
                    } catch (\DomainException $e) {
                        abort(409, $e->getMessage());
                    }
                    $attributes['warehouse_selection_source'] = 'manual';
                }
            }

            return $this->shipments->saveDraft($order, $attributes, $actor);
        });
    }

    public function markShipped(Model $order, array $attributes, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $this->paymentGate->assertAllowed($order);
            $active = $this->reservations->activeForOrder((int) $order->getKey());
            if ($active && (int) ($attributes['warehouse_id'] ?? 0) !== (int) $active['warehouse_id']) {
                abort(409, '发货仓库与已预留库存仓库不一致，请先保存新的人工仓库选择。');
            }

            if ($active && $order->shipment?->warehouse_selection_source === 'automatic') {
                unset($attributes['warehouse_id']);
            }

            $updated = $this->shipments->markShipped($order, $attributes, $actor);
            if ($active) {
                $this->reservations->commit((int) $active['id'], $actor);
            }

            return $updated;
        });
    }
}
