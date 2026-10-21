<?php

namespace App\Modules\Inventory\Application\Contracts;

use DateTimeInterface;

interface InventoryReservationRepository
{
    /** @return array<string,mixed>|null */
    public function activeForOrder(int $orderId): ?array;

    /** @return array<string,mixed> */
    public function reserve(int $orderId, int $warehouseId, string $reservationKey, ?DateTimeInterface $expiresAt, ?int $actorUserId): array;

    /** @return array<string,mixed> */
    public function release(int $reservationId, ?int $actorUserId, string $reason): array;

    /** @return array<string,mixed> */
    public function commit(int $reservationId, ?int $actorUserId): array;

    /** @return array<string,mixed> */
    public function expire(int $reservationId, ?int $actorUserId): array;
}
