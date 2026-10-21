<?php

namespace App\Modules\Products\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface SkuReconciliationRepository
{
    public function findByMatchKey(string $matchKey): ?Model;

    public function observe(string $matchKey, string $rawSku, string $sourceType, ?string $sourceReference, ?string $productNameHint = null): Model;

    public function resolve(Model $item, int $productId, int $aliasId, int $resolvedByUserId, ?string $note): void;

    public function ignore(Model $item, int $resolvedByUserId, ?string $note): void;

    public function reopen(Model $item): void;

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator;
}
