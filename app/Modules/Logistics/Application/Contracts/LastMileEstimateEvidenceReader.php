<?php

namespace App\Modules\Logistics\Application\Contracts;

interface LastMileEstimateEvidenceReader
{
    /** @return array<string,mixed>|null */
    public function read(int|string $orderId): ?array;
}
