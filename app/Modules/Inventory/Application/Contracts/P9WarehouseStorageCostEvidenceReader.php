<?php

namespace App\Modules\Inventory\Application\Contracts;

interface P9WarehouseStorageCostEvidenceReader
{
    /** @return array<string,mixed>|null */
    public function read(int $orderId): ?array;
}
