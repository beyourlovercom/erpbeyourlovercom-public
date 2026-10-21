<?php

namespace App\Modules\Logistics\Application\Contracts;

interface FulfillmentOriginEvidenceReader
{
    /** @return array<string, mixed>|null */
    public function read(int|string $orderId): ?array;
}
