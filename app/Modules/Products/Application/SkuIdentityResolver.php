<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\Contracts\SkuReconciliationRepository;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Application\SkuResolveResult;

final class SkuIdentityResolver
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly SkuAliasRepository $aliases,
        private readonly SkuReconciliationRepository $reconciliations,
    ) {}

    /**
     * Resolve a raw SKU string. Precedence:
     * 1. Canonical Product SKU (exact, case-insensitive via match key)
     * 2. Product SKU Alias
     * 3. Unmatched
     *
     * Does NOT record a reconciliation observation; callers must call observeUnmatched() explicitly.
     */
    public function resolve(string $rawSku): SkuResolveResult
    {
        $matchKey = SkuMatchKey::compute($rawSku);
        if ($matchKey === null) {
            return SkuResolveResult::unmatched();
        }

        $product = $this->products->findBySkuMatchKey($matchKey);
        if ($product !== null) {
            return SkuResolveResult::canonical($product);
        }

        $alias = $this->aliases->findByMatchKey($matchKey);
        if ($alias !== null) {
            $product = $this->products->findById((int) $alias->getAttribute('product_id'));
            if ($product !== null) {
                return SkuResolveResult::alias($product);
            }
        }

        return SkuResolveResult::unmatched();
    }

    /**
     * Record or update a reconciliation observation for an unmatched SKU.
     */
    public function observeUnmatched(string $rawSku, string $sourceType, ?string $sourceReference, ?string $productNameHint = null): void
    {
        $matchKey = SkuMatchKey::compute($rawSku);
        if ($matchKey === null) {
            return;
        }

        $this->reconciliations->observe($matchKey, $rawSku, $sourceType, $sourceReference, $productNameHint);
    }
}
