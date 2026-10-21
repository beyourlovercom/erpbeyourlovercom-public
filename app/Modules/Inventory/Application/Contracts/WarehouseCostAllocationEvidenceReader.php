<?php

namespace App\Modules\Inventory\Application\Contracts;

interface WarehouseCostAllocationEvidenceReader
{
    /** @return array<string,mixed>|null */
    public function read(int $orderId): ?array;
}
