<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

final class EloquentSkuAliasRepository implements SkuAliasRepository
{
    public function findByMatchKey(string $matchKey): ?Model
    {
        return ProductSkuAlias::where('match_key', $matchKey)->first();
    }

    public function createAlias(array $attributes): Model
    {
        return ProductSkuAlias::create($attributes);
    }

    public function deleteAlias(Model $alias): void
    {
        $alias->delete();
    }

    public function listForProduct(int $productId): array
    {
        return ProductSkuAlias::where('product_id', $productId)
            ->orderBy('alias_sku')
            ->get()
            ->toArray();
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return ProductSkuAlias::query()
            ->with('product:id,sku,name')
            ->when(
                isset($filters['search']) && $filters['search'] !== '',
                function ($q) use ($filters): void {
                    $search = '%'.$filters['search'].'%';
                    $q->where(fn ($inner) => $inner->where('alias_sku', 'like', $search)
                        ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', $search)->orWhere('name', 'like', $search)));
                }
            )
            ->orderBy('alias_sku')
            ->paginate(min($perPage, 200))
            ->withQueryString();
    }

    public function existsMatchKey(string $matchKey, ?int $excludeAliasId = null): bool
    {
        $query = ProductSkuAlias::where('match_key', $matchKey);
        if ($excludeAliasId !== null) {
            $query->where('id', '!=', $excludeAliasId);
        }
        return $query->exists();
    }
}
