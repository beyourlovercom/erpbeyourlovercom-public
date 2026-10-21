<?php

namespace App\Modules\Sync\Application\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface SyncOrchestrationRepository
{
    /** @param list<int> $ids @return Collection<int,Model> keyed by order id */
    public function ordersByIds(array $ids): Collection;

    /** @return Collection<int,Model> */
    public function eligibleTrackingOrders(int $limit): Collection;

    /** @return Collection<int,Model> */
    public function dueTrackingOutbox(int $limit): Collection;

    public function shipmentWithOrder(int $shipmentId): ?Model;

    /** @return array{retry_due:int,retry_waiting:int,processing:int,failed:int,conflicts:int} */
    public function queueSummary(string $operation): array;
}
