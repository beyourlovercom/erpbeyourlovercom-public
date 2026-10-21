<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class InventoryReservationService
{
    public function __construct(
        private readonly InventoryReservationRepository $reservations,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed>|null */
    public function activeForOrder(int $orderId): ?array
    {
        return $this->reservations->activeForOrder($orderId);
    }

    /** @return array<string,mixed> */
    public function reserve(int $orderId, int $warehouseId, string $reservationKey, ?DateTimeInterface $expiresAt, Authenticatable $actor): array
    {
        $this->authorize($actor);

        return $this->reserveInternal($orderId, $warehouseId, $reservationKey, $expiresAt, $actor);
    }

    /** @return array<string,mixed> */
    public function reserveAsSystem(int $orderId, int $warehouseId, string $reservationKey, ?DateTimeInterface $expiresAt = null): array
    {
        return $this->reserveInternal($orderId, $warehouseId, $reservationKey, $expiresAt, null);
    }

    /** @return array<string,mixed> */
    private function reserveInternal(int $orderId, int $warehouseId, string $reservationKey, ?DateTimeInterface $expiresAt, ?Authenticatable $actor): array
    {
        $reservationKey = trim($reservationKey);
        if ($reservationKey === '' || mb_strlen($reservationKey) > 64) {
            throw new DomainException('Reservation key is invalid.');
        }

        return DB::transaction(function () use ($orderId, $warehouseId, $reservationKey, $expiresAt, $actor): array {
            $result = $this->reservations->reserve($orderId, $warehouseId, $reservationKey, $expiresAt, $actor ? (int) $actor->getAuthIdentifier() : null);
            if (! ($result['idempotent'] ?? false)) {
                $this->record($actor, 'inventory.reservation.reserved', $result, null, $result, 'inventory_reservation');
            }

            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function release(int $reservationId, Authenticatable $actor, string $reason = 'manual_release'): array
    {
        $this->authorize($actor);
        $reason = trim($reason) ?: 'manual_release';
        if (mb_strlen($reason) > 191) {
            throw new DomainException('Release reason is too long.');
        }

        return DB::transaction(function () use ($reservationId, $actor, $reason): array {
            $result = $this->reservations->release($reservationId, (int) $actor->getAuthIdentifier(), $reason);
            if (! ($result['idempotent'] ?? false)) {
                $this->record($actor, 'inventory.reservation.released', $result, ['status' => 'reserved'], $result, $reason);
            }

            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function commit(int $reservationId, Authenticatable $actor): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($reservationId, $actor): array {
            $result = $this->reservations->commit($reservationId, (int) $actor->getAuthIdentifier());
            if (! ($result['idempotent'] ?? false)) {
                $this->record($actor, 'inventory.reservation.committed', $result, ['status' => 'reserved'], $result, 'inventory_reservation_commit');
            }

            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function expire(int $reservationId, Authenticatable $actor): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($reservationId, $actor): array {
            $result = $this->reservations->expire($reservationId, (int) $actor->getAuthIdentifier());
            if (! ($result['idempotent'] ?? false)) {
                $this->record($actor, 'inventory.reservation.expired', $result, ['status' => 'reserved'], $result, 'inventory_reservation_expired');
            }

            return $result;
        });
    }

    private function authorize(Authenticatable $actor): void
    {
        if (! $actor->can('inventory.operate') && ! $actor->can('logistics.operate')) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string,mixed> $result */
    private function record(?Authenticatable $actor, string $action, array $result, ?array $before, array $after, string $reason): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action,
            'inventory_reservation',
            $result['id'] ?? null,
            before: $before,
            after: $after,
            reason: $reason,
        );
    }
}
