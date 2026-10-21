<?php

namespace App\Modules\Finance\Application\Contracts;

use Illuminate\Database\Eloquent\Model;

interface WooFinancialBootstrapOrderStore
{
    public function findExactOperational(
        int $orderId,
        string $provider,
        string $connectionKey,
        string $reference,
        bool $lock = false,
    ): ?Model;
}
