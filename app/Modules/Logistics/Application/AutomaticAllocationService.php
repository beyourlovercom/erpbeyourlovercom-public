<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class AutomaticAllocationService
{
    public function __construct(
        private readonly AutomaticAllocationEligibilityService $eligibility,
        private readonly InventoryReservationService $reservations,
        private readonly ShipmentService $shipments,
    ) {}

    /** @return array<string,mixed> */
    public function preview(Order $order): array
    {
        return [...$this->eligibility->evaluate($order), 'mode' => 'preview', 'mutated' => false];
    }

    /** @return array<string,mixed> */
    public function allocate(Order $order, ?Authenticatable $actor = null): array
    {
        if (! (bool) config('inventory_auto_allocation.enabled', false)
            || (bool) config('inventory_auto_allocation.dry_run', true)) {
            throw new DomainException('Automatic allocation apply is disabled by runtime configuration.');
        }
        return DB::transaction(function () use ($order, $actor): array {
            $lockedOrder = Order::query()
                ->with(['address', 'items', 'shipment.warehouse', 'externalReferences'])
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $eligibility = $this->eligibility->evaluate($lockedOrder);
            if (($eligibility['state'] ?? null) === 'ALREADY_RESERVED'
                && ($eligibility['status'] ?? null) === 'allocated') {
                return [...$eligibility, 'mode' => 'apply', 'mutated' => false, 'idempotent' => true];
            }

            if (($eligibility['state'] ?? null) !== 'READY_AUTO_ALLOCATE') {
                return [...$eligibility, 'mode' => 'apply', 'mutated' => false, 'idempotent' => true];
            }

            $warehouseId = (int) ($eligibility['warehouse']['id'] ?? 0);
            if ($warehouseId <= 0) {
                throw new DomainException('Automatic allocation recommendation has no warehouse.');
            }

            $reservationKey = 'automatic-order-'.$lockedOrder->id;
            $reservation = $actor
                ? $this->reservations->reserve((int) $lockedOrder->id, $warehouseId, $reservationKey, null, $actor)
                : $this->reservations->reserveAsSystem((int) $lockedOrder->id, $warehouseId, $reservationKey);
            $freshOrder = $this->shipments->saveDraft($lockedOrder, [
                'warehouse_id' => $warehouseId,
                'warehouse_selection_source' => 'automatic',
            ], $actor);

            $shipment = $freshOrder->shipment;
            if (! $shipment
                || (int) $shipment->warehouse_id !== $warehouseId
                || $shipment->warehouse_selection_source !== 'automatic') {
                throw new DomainException('Automatic allocation failed to persist matching Shipment Draft provenance.');
            }

            return [
                'state' => 'ALREADY_RESERVED',
                'status' => 'allocated',
                'reason_code' => 'automatic_allocated',
                'reason_label' => '已自动分配仓库并预留库存。',
                'country_code' => $eligibility['country_code'] ?? null,
                'warehouse' => $eligibility['warehouse'],
                'can_apply' => false,
                'mode' => 'apply',
                'mutated' => ! (bool) ($reservation['idempotent'] ?? false),
                'idempotent' => (bool) ($reservation['idempotent'] ?? false),
                'reservation_id' => $reservation['id'] ?? null,
                'shipment_id' => $shipment->id,
            ];
        });
    }
}
