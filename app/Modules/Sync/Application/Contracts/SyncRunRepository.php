<?php

namespace App\Modules\Sync\Application\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface SyncRunRepository
{
    public function createRun(array $attributes): Model;

    public function lockRun(int $id): Model;

    public function nextItemSequence(Model $run): int;

    public function createItem(Model $run, array $attributes): Model;

    public function updateRun(Model $run, array $attributes): Model;

    public function updateItem(Model $item, array $attributes): Model;

    /** @return Collection<int,Model> */
    public function items(Model $run): Collection;

    public function freshRun(Model $run, array $relations = []): Model;

    public function freshItem(Model $item): Model;

    /** @return Collection<int,Model> */
    public function recent(int $limit): Collection;

    /** @return Collection<int,Model> */
    public function recentForScope(string $runType, ?string $providerKey, ?string $connectionKey, int $limit): Collection;

    public function detail(int $id): Model;
}
