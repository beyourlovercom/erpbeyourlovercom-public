<?php

namespace App\Modules\Products\Application\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ConfirmedProductCostSourceRepository
{
    public function schemaReady(): bool;

    public function findByFingerprint(string $fingerprint): ?Model;

    /** @return list<Model> */
    public function findConfirmedForProductPeriod(int $productId, string $effectiveFrom): array;

    public function create(array $attributes): Model;
}
