<?php

namespace App\Modules\Products\Application\Contracts;

interface ProductCostEvidenceReader
{
    /** @return list<array<string, mixed>> */
    public function read(int|string $orderId): array;
}
