<?php

namespace App\Modules\Search\Infrastructure;

use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Search\Application\Contracts\SearchProvider;

final class ProductSearchProvider implements SearchProvider
{
    public function key(): string { return 'products'; }

    public function label(): string { return '产品'; }

    public function permission(): string { return 'products.view'; }

    public function viewAllHref(string $query): string
    {
        return '/products/list?search='.rawurlencode($query);
    }

    public function search(string $query, int $limit): array
    {
        $needle = trim($query);
        $like = '%'.$needle.'%';

        $rows = Product::query()
            ->with(['skuAliases:id,product_id,alias_sku'])
            ->where(function ($q) use ($needle, $like): void {
                $q->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('gtin', 'like', $like)
                    ->orWhereHas('skuAliases', fn ($aliases) => $aliases->where('alias_sku', 'like', $like));

                if (ctype_digit($needle)) {
                    $q->orWhere('woocommerce_product_id', (int) $needle);
                }
            })
            ->limit(30)
            ->get(['id', 'sku', 'name', 'gtin', 'woocommerce_product_id'])
            ->sortBy(fn (Product $product): array => [$this->rank($product, $needle), strtoupper((string) $product->sku), (int) $product->id])
            ->take($limit)
            ->values();

        return $rows->map(fn (Product $product): array => [
            'id' => 'product:'.$product->id,
            'label' => trim((string) $product->sku) !== '' ? (string) $product->sku : (string) $product->name,
            'subtitle' => trim((string) $product->name) !== '' ? (string) $product->name : 'Product #'.$product->id,
            'href' => '/products/'.$product->id,
            'meta' => [
                'product_id' => (int) $product->id,
                'gtin' => $product->gtin,
                'woocommerce_product_id' => $product->woocommerce_product_id,
            ],
        ])->all();
    }

    private function rank(Product $product, string $needle): int
    {
        $n = mb_strtolower($needle);
        $sku = mb_strtolower((string) $product->sku);
        $name = mb_strtolower((string) $product->name);
        $gtin = mb_strtolower((string) $product->gtin);
        $woo = (string) ($product->woocommerce_product_id ?? '');

        if ($sku !== '' && $sku === $n) return 0;
        if ($gtin !== '' && $gtin === $n) return 1;
        if ($woo !== '' && $woo === $needle) return 1;
        foreach ($product->skuAliases as $alias) {
            if (mb_strtolower((string) $alias->alias_sku) === $n) return 1;
        }
        if ($sku !== '' && str_starts_with($sku, $n)) return 2;
        if ($name !== '' && str_starts_with($name, $n)) return 3;

        return 4;
    }
}
