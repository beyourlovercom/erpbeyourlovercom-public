<?php

namespace App\Modules\Sync\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ManualSyncService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function ensurePending(Model $order, ?Authenticatable $actor): Model
    {
        $sync = $order->manualSync()->lockForUpdate()->firstOrCreate(
            ['order_id' => $order->getKey()],
            ['website_sync_status' => ManualSyncStatus::Pending->value],
        );

        if ($sync->website_sync_status === ManualSyncStatus::Completed) {
            return $sync;
        }

        if ($sync->website_sync_status !== ManualSyncStatus::Pending) {
            $sync->forceFill([
                'website_sync_status' => ManualSyncStatus::Pending,
                'synced_at' => null,
                'synced_by' => null,
            ])->save();
        }

        return $sync;
    }

    public function complete(Model $order, ?Authenticatable $actor, ?string $note = null): Model
    {
        return $this->completeWithReason($order, $actor, $note, 'external_site_updated_manually');
    }

    public function completeFromAutomaticTracking(Model $order, ?Authenticatable $actor, ?string $note = null): Model
    {
        return $this->completeWithReason($order, $actor, $note, 'woocommerce_tracking_updated_automatically');
    }

    private function completeWithReason(Model $order, ?Authenticatable $actor, ?string $note, string $reason): Model
    {
        return DB::transaction(function () use ($order, $actor, $note, $reason): Model {
            $order->loadMissing('shipment');
            if ($order->shipment?->shipment_status !== 'shipped') {
                throw new \DomainException('Only shipped orders can be marked as manually synced.');
            }
            $sync = $order->manualSync()->lockForUpdate()->firstOrCreate(
                ['order_id' => $order->getKey()],
                ['website_sync_status' => ManualSyncStatus::Pending],
            );
            if ($sync->website_sync_status === ManualSyncStatus::Completed) {
                return $sync->fresh(['order', 'operator']);
            }
            $before = $sync->only(['website_sync_status', 'synced_at', 'synced_by', 'sync_note']);
            $sync->fill([
                'website_sync_status' => ManualSyncStatus::Completed,
                'synced_at' => now(),
                'synced_by' => $actor?->getAuthIdentifier(),
                'sync_note' => $note,
            ])->save();
            $this->record($actor, 'manual_sync.completed', $sync, $before, $reason);

            return $sync->fresh(['order', 'operator']);
        });
    }

    public function reopen(Model $order, ?Authenticatable $actor, ?string $note = null): Model
    {
        return DB::transaction(function () use ($order, $actor, $note): Model {
            $sync = $order->manualSync()->lockForUpdate()->firstOrCreate(
                ['order_id' => $order->getKey()],
                ['website_sync_status' => ManualSyncStatus::Pending],
            );
            if ($sync->website_sync_status === ManualSyncStatus::Pending && $sync->synced_at === null && $sync->synced_by === null && $sync->sync_note === $note) {
                return $sync->fresh(['order', 'operator']);
            }
            $before = $sync->only(['website_sync_status', 'synced_at', 'synced_by', 'sync_note']);
            $sync->fill([
                'website_sync_status' => ManualSyncStatus::Pending,
                'synced_at' => null,
                'synced_by' => null,
                'sync_note' => $note,
            ])->save();
            $this->record($actor, 'manual_sync.reopened', $sync, $before, 'correction_required');

            return $sync->fresh(['order', 'operator']);
        });
    }

    public function returnForCorrection(Model $order, ?Authenticatable $actor, ?string $note = null): Model
    {
        return DB::transaction(function () use ($order, $actor, $note): Model {
            $sync = $order->manualSync()->lockForUpdate()->firstOrCreate(
                ['order_id' => $order->getKey()],
                ['website_sync_status' => ManualSyncStatus::Pending],
            );
            if ($sync->website_sync_status === ManualSyncStatus::Returned && $sync->synced_at === null && $sync->synced_by === null && $sync->sync_note === $note) {
                return $sync->fresh(['order', 'operator']);
            }
            $before = $sync->only(['website_sync_status', 'synced_at', 'synced_by', 'sync_note']);
            $sync->fill([
                'website_sync_status' => ManualSyncStatus::Returned,
                'synced_at' => null,
                'synced_by' => null,
                'sync_note' => $note,
            ])->save();
            $this->record($actor, 'manual_sync.returned', $sync, $before, 'external_site_update_requires_correction');

            return $sync->fresh(['order', 'operator']);
        });
    }

    private function record(?Authenticatable $actor, string $action, Model $sync, array $before, string $reason): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action,
            get_class($sync),
            $sync->getKey(),
            before: $before,
            after: $sync->only(['website_sync_status', 'synced_at', 'synced_by', 'sync_note']),
            reason: $reason,
        );
    }
}
