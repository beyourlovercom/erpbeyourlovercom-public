<?php

namespace App\Modules\Sync\Infrastructure;

use App\Modules\Sync\Application\Contracts\SyncOutboxRepository;
use App\Modules\Sync\Infrastructure\Models\SyncOutbox;
use Illuminate\Database\Eloquent\Model;

final class EloquentSyncOutboxRepository implements SyncOutboxRepository
{
    public function findByIdempotencyKeyForUpdate(string $idempotencyKey): ?Model
    {
        return SyncOutbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    public function findByIdForUpdate(int $id): ?Model
    {
        return SyncOutbox::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findOrFail(int $id): Model
    {
        return SyncOutbox::query()->findOrFail($id);
    }
}
