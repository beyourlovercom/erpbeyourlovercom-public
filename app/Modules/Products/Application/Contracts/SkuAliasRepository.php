<?php

namespace App\Modules\Products\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface SkuAliasRepository
{
    public function findByMatchKey(string $matchKey): ?Model;

    public function createAlias(array $attributes): Model;

    public function deleteAlias(Model $alias): void;

    public function listForProduct(int $productId): array;

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator;

    public function existsMatchKey(string $matchKey, ?int $excludeAliasId = null): bool;
}
