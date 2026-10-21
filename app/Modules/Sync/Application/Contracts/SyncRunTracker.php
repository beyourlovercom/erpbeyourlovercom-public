<?php

namespace App\Modules\Sync\Application\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

interface SyncRunTracker
{
    public function start(
        string $runType,
        int $requestedItems,
        ?Authenticatable $actor,
        ?string $providerKey = null,
        ?string $connectionKey = null,
        array $context = [],
    ): Model;

    public function setRequestedItems(Model $run, int $requestedItems): Model;

    public function annotateContext(Model $run, array $context): Model;

    public function addItem(Model $run, array $attributes): Model;

    public function markProcessing(Model $item): Model;

    public function annotateItem(Model $item, array $attributes): Model;
    public function finishSucceeded(Model $item, ?string $resultCode = null): Model;

    public function finishSkipped(Model $item, ?string $resultCode = null): Model;

    public function finishFailed(
        Model $item,
        ?string $resultCode = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): Model;

    public function complete(Model $run, ?Authenticatable $actor): Model;

    public function latestContextString(
        string $runType,
        ?string $providerKey,
        ?string $connectionKey,
        string $key,
    ): ?string;
}
