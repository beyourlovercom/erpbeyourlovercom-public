<?php

namespace App\Modules\Sync\Application\Contracts;

use Illuminate\Database\Eloquent\Model;

interface SyncOutboxRepository
{
    public function findByIdempotencyKeyForUpdate(string $idempotencyKey): ?Model;

    public function findByIdForUpdate(int $id): ?Model;

    public function findOrFail(int $id): Model;
}
