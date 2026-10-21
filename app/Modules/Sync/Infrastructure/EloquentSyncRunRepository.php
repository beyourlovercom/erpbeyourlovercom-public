<?php

namespace App\Modules\Sync\Infrastructure;

use App\Modules\Sync\Application\Contracts\SyncRunRepository;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class EloquentSyncRunRepository implements SyncRunRepository
{
    public function createRun(array $attributes): Model
    {
        return SyncRun::create($attributes);
    }

    public function lockRun(int $id): Model
    {
        return SyncRun::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function nextItemSequence(Model $run): int
    {
        return ((int) $run->items()->max('sequence')) + 1;
    }

    public function createItem(Model $run, array $attributes): Model
    {
        return $run->items()->create($attributes);
    }
    public function updateRun(Model $run, array $attributes): Model
    {
        $run->forceFill($attributes)->save();

        return $run;
    }

    public function updateItem(Model $item, array $attributes): Model
    {
        $item->forceFill($attributes)->save();

        return $item;
    }

    public function items(Model $run): Collection
    {
        return $run->items()->get();
    }

    public function freshRun(Model $run, array $relations = []): Model
    {
        return $run->fresh($relations);
    }

    public function freshItem(Model $item): Model
    {
        return $item->fresh();
    }
    public function recent(int $limit): Collection
    {
        return SyncRun::query()
            ->with('initiator')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function recentForScope(string $runType, ?string $providerKey, ?string $connectionKey, int $limit): Collection
    {
        return SyncRun::query()
            ->where('run_type', $runType)
            ->when($providerKey !== null, fn ($query) => $query->where('provider_key', $providerKey))
            ->when($connectionKey !== null, fn ($query) => $query->where('connection_key', $connectionKey))
            ->latest('id')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }

    public function detail(int $id): Model
    {
        return SyncRun::query()
            ->with(['initiator', 'items'])
            ->findOrFail($id);
    }
}
