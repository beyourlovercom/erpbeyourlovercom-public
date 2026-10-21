<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

final class EloquentProductRepository implements ProductRepository
{
    public function findByWooId(int $wooId): ?Model
    {
        return Product::where('woocommerce_product_id', $wooId)->first();
    }

    public function create(array $attributes): Model
    {
        return Product::create($attributes);
    }

    public function update(Model $product, array $attributes): void
    {
        $product->fill($attributes)->save();
    }

    public function delete(Model $product): void
    {
        $product->delete();
    }

    public function findById(int $id): ?Model
    {
        return Product::find($id);
    }

    public function findBySku(string $sku): ?Model
    {
        return Product::where('sku', $sku)->whereNotNull('sku')->first();
    }

    public function findByLabelSku(string $labelSku): ?Model
    {
        return Product::where('label_sku', $labelSku)->whereNotNull('label_sku')->first();
    }

    public function findBySkuMatchKey(string $matchKey): ?Model
    {
        // Use PHP-side SkuMatchKey comparison so behaviour is identical across
        // SQLite (case-insensitive LIKE by default) and MySQL (collation-dependent).
        // We narrow candidates with LOWER() for index efficiency, then confirm in PHP.
        $candidates = Product::whereNotNull('sku')
            ->whereRaw('LOWER(sku) = ?', [$matchKey])
            ->get();

        foreach ($candidates as $candidate) {
            if (SkuMatchKey::compute((string) $candidate->getAttribute('sku')) === $matchKey) {
                return $candidate;
            }
        }

        return null;
    }

    public function searchSkus(string $query, int $limit = 20): array
    {
        $query = trim($query);
        $builder = Product::query()
            ->whereNotNull('sku')
            ->where('sku', '!=', '');

        if ($query === '') {
            $builder
                ->orderBy('sku')
                ->orderBy('name')
                ->orderBy('id');
        } else {
            $builder
                ->where(function ($q) use ($query): void {
                    $q->where('sku', 'like', $query.'%')
                        ->orWhere('sku', 'like', '%'.$query.'%')
                        ->orWhere('name', 'like', '%'.$query.'%');
                })
                ->orderByRaw(
                    'CASE WHEN sku LIKE ? THEN 0 WHEN sku LIKE ? THEN 1 WHEN name LIKE ? THEN 2 ELSE 3 END',
                    [$query.'%', '%'.$query.'%', '%'.$query.'%']
                )
                ->orderBy('sku')
                ->orderBy('name')
                ->orderBy('id');
        }

        return $builder
            ->limit($limit)
            ->get(['id', 'sku', 'name'])
            ->map(fn ($p): array => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name])
            ->all();
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return Product::query()
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($q) use ($filters): void {
                $raw = trim((string) $filters['search']);
                $search = '%'.$raw.'%';
                $q->where(function ($inner) use ($raw, $search): void {
                    $inner->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('gtin', 'like', $search)
                        ->orWhereHas('skuAliases', fn ($a) => $a->where('alias_sku', 'like', $search));
                    if (ctype_digit($raw)) {
                        $inner->orWhere('woocommerce_product_id', (int) $raw);
                    }
                });
            })
            ->orderBy(in_array($filters['sort'] ?? 'name', ['name', 'sku', 'regular_price', 'sale_price', 'shipping_weight_kg', 'id'], true) ? $filters['sort'] ?? 'name' : 'name', ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(max(1, $perPage))
            ->withQueryString();
    }

    public function totalCount(): int
    {
        return Product::count();
    }

    public function coverageCounts(): array
    {
        $total = Product::count();

        return [
            'total' => $total,
            'weight_filled' => Product::whereNotNull('weight_kg')->count(),
            'shipping_filled' => Product::whereNotNull('shipping_weight_kg')->count(),
        ];
    }
}
