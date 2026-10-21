<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Inventory\Infrastructure\Models\InventoryAdjustment;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservationItem;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Infrastructure\Models\Order;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;

final class EloquentInventoryReservationRepository implements InventoryReservationRepository
{
    public function activeForOrder(int $orderId): ?array
    {
        $reservation = InventoryReservation::query()
            ->where('order_id', $orderId)
            ->where('status', 'reserved')
            ->lockForUpdate()
            ->first();

        return $reservation ? $this->snapshot($reservation->load('items'), true) : null;
    }

    public function reserve(int $orderId, int $warehouseId, string $reservationKey, ?DateTimeInterface $expiresAt, ?int $actorUserId): array
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        if (! $order || $order->order_status?->value !== 'pending_shipment') {
            throw new DomainException('Only pending-shipment orders can reserve inventory.');
        }

        $items = $order->items()->orderBy('id')->get(['product_id', 'quantity']);
        if ($items->isEmpty()) {
            throw new DomainException('Order has no reservable items.');
        }
        if ($items->contains(fn ($item): bool => ! $item->product_id || (int) $item->quantity <= 0)) {
            throw new DomainException('Every order item requires canonical product identity and positive quantity.');
        }

        $required = $items->groupBy('product_id')->map(fn ($rows): int => (int) $rows->sum('quantity'))->sortKeys();
        $expiry = $expiresAt ? Carbon::instance($expiresAt)->utc() : null;
        if ($expiry && $expiry->lte(now()->utc())) {
            throw new DomainException('Reservation expiry must be in the future.');
        }

        $payloadHash = hash('sha256', json_encode([
            'order_id' => $orderId,
            'warehouse_id' => $warehouseId,
            'items' => $required->all(),
            'expires_at' => $expiry?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        $sameKey = InventoryReservation::query()->where('reservation_key', $reservationKey)->lockForUpdate()->first();
        if ($sameKey) {
            if (! hash_equals((string) $sameKey->payload_hash, $payloadHash)) {
                throw new DomainException('Reservation key already exists with different payload.');
            }

            return $this->snapshot($sameKey->load('items'), true);
        }

        $active = InventoryReservation::query()->where('order_id', $orderId)->where('status', 'reserved')->lockForUpdate()->first();
        if ($active) {
            if ((int) $active->warehouse_id === $warehouseId && hash_equals((string) $active->payload_hash, $payloadHash)) {
                return $this->snapshot($active->load('items'), true);
            }
            throw new DomainException('Order already has an active inventory reservation.');
        }

        $warehouse = Warehouse::query()->whereKey($warehouseId)->where('is_active', true)->lockForUpdate()->first();
        if (! $warehouse) {
            throw new DomainException('Warehouse is unavailable for reservation.');
        }

        $authoritative = InventorySyncBatch::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'confirmed')
            ->where('sync_mode', 'full')
            ->exists();
        if (! $authoritative) {
            throw new DomainException('Warehouse has no confirmed full inventory authority.');
        }

        $lockedInventory = WarehouseInventory::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $required->keys()->all())
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        foreach ($required as $productId => $quantity) {
            $inventory = $lockedInventory->get($productId);
            if (! $inventory) {
                throw new DomainException("Inventory row is missing for product {$productId}.");
            }
            if ((int) $inventory->quantity_available < $quantity) {
                throw new DomainException("Insufficient available inventory for product {$productId}.");
            }
        }

        $reservation = InventoryReservation::query()->create([
            'reservation_key' => $reservationKey,
            'payload_hash' => $payloadHash,
            'order_id' => $orderId,
            'warehouse_id' => $warehouseId,
            'status' => 'reserved',
            'expires_at' => $expiry,
            'created_by' => $actorUserId,
        ]);

        foreach ($required as $productId => $quantity) {
            /** @var WarehouseInventory $inventory */
            $inventory = $lockedInventory->get($productId);
            $before = (int) $inventory->quantity_reserved;
            $inventory->forceFill(['quantity_reserved' => $before + $quantity])->save();
            InventoryReservationItem::query()->create([
                'inventory_reservation_id' => $reservation->id,
                'warehouse_inventory_id' => $inventory->id,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
            $this->adjustment($inventory, $actorUserId, $inventory->quantity_on_hand, $inventory->quantity_on_hand, $before, $before + $quantity, 'reservation_reserved');
        }

        return $this->snapshot($reservation->fresh('items'), false);
    }

    public function release(int $reservationId, ?int $actorUserId, string $reason): array
    {
        $reservation = $this->locked($reservationId);
        if ($reservation->status === 'released') {
            return $this->snapshot($reservation, true);
        }
        if ($reservation->status !== 'reserved') {
            throw new DomainException('Only active reservations can be released.');
        }

        $this->releaseInventory($reservation, $actorUserId, 'reservation_released');
        $reservation->forceFill(['status' => 'released', 'released_at' => now(), 'release_reason' => $reason])->save();

        return $this->snapshot($reservation->fresh('items'), false);
    }

    public function commit(int $reservationId, ?int $actorUserId): array
    {
        $reservation = $this->locked($reservationId);
        if ($reservation->status === 'committed') {
            return $this->snapshot($reservation, true);
        }
        if ($reservation->status !== 'reserved') {
            throw new DomainException('Only active reservations can be committed.');
        }
        if ($reservation->expires_at && $reservation->expires_at->lte(now())) {
            throw new DomainException('Expired reservation cannot be committed.');
        }

        $inventories = $this->lockedInventories($reservation);
        foreach ($reservation->items as $item) {
            /** @var WarehouseInventory $inventory */
            $inventory = $inventories->get($item->warehouse_inventory_id);
            $quantity = (int) $item->quantity;
            $qoh = (int) $inventory->quantity_on_hand;
            $reserved = (int) $inventory->quantity_reserved;
            if ($reserved < $quantity || $qoh < $quantity) {
                throw new DomainException('Inventory drift blocks reservation commit.');
            }
            $inventory->forceFill(['quantity_on_hand' => $qoh - $quantity, 'quantity_reserved' => $reserved - $quantity])->save();
            $this->adjustment($inventory, $actorUserId, $qoh, $qoh - $quantity, $reserved, $reserved - $quantity, 'reservation_committed');
        }

        $reservation->forceFill(['status' => 'committed', 'committed_at' => now()])->save();

        return $this->snapshot($reservation->fresh('items'), false);
    }

    public function expire(int $reservationId, ?int $actorUserId): array
    {
        $reservation = $this->locked($reservationId);
        if ($reservation->status === 'expired') {
            return $this->snapshot($reservation, true);
        }
        if ($reservation->status !== 'reserved') {
            throw new DomainException('Only active reservations can expire.');
        }
        if (! $reservation->expires_at || $reservation->expires_at->gt(now())) {
            throw new DomainException('Reservation is not due for expiry.');
        }

        $this->releaseInventory($reservation, $actorUserId, 'reservation_expired');
        $reservation->forceFill(['status' => 'expired', 'expired_at' => now(), 'release_reason' => 'expired'])->save();

        return $this->snapshot($reservation->fresh('items'), false);
    }

    private function locked(int $reservationId): InventoryReservation
    {
        $reservation = InventoryReservation::query()->whereKey($reservationId)->lockForUpdate()->first();
        if (! $reservation) {
            throw new DomainException('Inventory reservation does not exist.');
        }

        return $reservation->load('items');
    }

    private function lockedInventories(InventoryReservation $reservation)
    {
        return WarehouseInventory::query()
            ->whereIn('id', $reservation->items->pluck('warehouse_inventory_id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function releaseInventory(InventoryReservation $reservation, ?int $actorUserId, string $reason): void
    {
        $inventories = $this->lockedInventories($reservation);
        foreach ($reservation->items as $item) {
            /** @var WarehouseInventory $inventory */
            $inventory = $inventories->get($item->warehouse_inventory_id);
            $quantity = (int) $item->quantity;
            $reserved = (int) $inventory->quantity_reserved;
            if ($reserved < $quantity) {
                throw new DomainException('Inventory drift blocks reservation release.');
            }
            $inventory->forceFill(['quantity_reserved' => $reserved - $quantity])->save();
            $this->adjustment($inventory, $actorUserId, $inventory->quantity_on_hand, $inventory->quantity_on_hand, $reserved, $reserved - $quantity, $reason);
        }
    }

    private function adjustment(WarehouseInventory $inventory, ?int $actorUserId, int $qohBefore, int $qohAfter, int $reservedBefore, int $reservedAfter, string $reason): void
    {
        InventoryAdjustment::query()->create([
            'warehouse_id' => $inventory->warehouse_id,
            'product_id' => $inventory->product_id,
            'warehouse_inventory_id' => $inventory->id,
            'quantity_before' => $qohBefore,
            'quantity_after' => $qohAfter,
            'reserved_before' => $reservedBefore,
            'reserved_after' => $reservedAfter,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
        ]);
    }

    /** @return array<string,mixed> */
    private function snapshot(InventoryReservation $reservation, bool $idempotent): array
    {
        $reservation->loadMissing('items');

        return [
            'id' => (int) $reservation->id,
            'reservation_key' => (string) $reservation->reservation_key,
            'order_id' => (int) $reservation->order_id,
            'warehouse_id' => (int) $reservation->warehouse_id,
            'status' => (string) $reservation->status,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
            'committed_at' => $reservation->committed_at?->toIso8601String(),
            'released_at' => $reservation->released_at?->toIso8601String(),
            'expired_at' => $reservation->expired_at?->toIso8601String(),
            'release_reason' => $reservation->release_reason,
            'items' => $reservation->items->sortBy('product_id')->map(fn ($item): array => [
                'product_id' => (int) $item->product_id,
                'warehouse_inventory_id' => (int) $item->warehouse_inventory_id,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
            'idempotent' => $idempotent,
        ];
    }
}
