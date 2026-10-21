<?php

namespace App\Modules\Logistics\Application\Contracts;

interface OverseasHandlingEvidenceReader
{
    /** @return array<string,mixed>|null */
    public function read(int|string $orderId): ?array;
}
