<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Application\SkuAliasService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductSkuController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly SkuAliasService $aliases,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search']);
        $products = $this->products->list($filters, 50);

        return Inertia::render('Products/Skus', [
            'products' => $products->through(fn ($p): array => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'is_active' => $p->is_active,
                'source_type' => $p->source_type,
                'alias_count' => $p->skuAliases()->count(),
            ])->toArray(),
            'filters' => $filters,
        ]);
    }
}
