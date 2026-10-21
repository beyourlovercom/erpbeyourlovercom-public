<?php

namespace App\Modules\Products\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface ProductRepository
{
    public function findByWooId(int $wooId): ?Model;

    public function create(array $attributes): Model;

    public function update(Model $product, array $attributes): void;

    public function delete(Model $product): void;

    public function findById(int $id): ?Model;

    public function findBySku(string $sku): ?Model;

    public function findByLabelSku(string $labelSku): ?Model;

    /**
     * Find a Product whose canonical SKU resolves to the given match key.
     * Uses PHP-side SkuMatchKey comparison so behaviour is DB-collation-independent.
     */
    public function findBySkuMatchKey(string $matchKey): ?Model;

    /** @return list<array{id:int,sku:string,name:string}> */
    public function searchSkus(string $query, int $limit = 20): array;

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator;

    public function totalCount(): int;

    /** @return array{total: int, weight_filled: int, shipping_filled: int} */
    public function coverageCounts(): array;
}
