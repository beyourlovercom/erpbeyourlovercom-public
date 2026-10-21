<?php

namespace App\Modules\Kol\Application\Contracts;

interface AffiliateCommissionEvidenceReader
{
    /** @return list<array<string, mixed>> */
    public function read(int|string $orderId): array;
}
