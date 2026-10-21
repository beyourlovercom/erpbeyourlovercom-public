<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Products\Application\SkuReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProductSkuAliasController
{
    public function __construct(
        private readonly SkuAliasService $aliases,
        private readonly SkuReconciliationService $reconciliations,
    ) {}

    public function index(Request $request): Response
    {
        $aliasFilters = $request->only(['search']);
        $reconcileFilters = $request->only(['status', 'search']);
        if (! isset($reconcileFilters['status'])) {
            $reconcileFilters['status'] = 'open';
        }

        $aliasList = $this->aliases->list($aliasFilters, 50);
        $reconcileList = $this->reconciliations->list($reconcileFilters, 50);

        return Inertia::render('Products/SkuAliases', [
            'aliases' => $aliasList->through(fn ($a): array => [
                'id' => $a->id,
                'alias_sku' => $a->alias_sku,
                'match_key' => $a->match_key,
                'source_type' => $a->source_type,
                'product' => $a->product ? ['id' => $a->product->id, 'sku' => $a->product->sku, 'name' => $a->product->name] : null,
                'created_at' => $a->created_at?->toDateString(),
            ])->toArray(),
            'reconciliations' => $reconcileList->through(fn ($r): array => [
                'id' => $r->id,
                'raw_sku' => $r->raw_sku,
                'match_key' => $r->match_key,
                'status' => $r->status,
                'occurrence_count' => $r->occurrence_count,
                'source_counts' => $r->source_counts,
                'last_seen_at' => $r->last_seen_at?->toDateString(),
                'resolved_product' => $r->resolvedProduct ? ['id' => $r->resolvedProduct->id, 'sku' => $r->resolvedProduct->sku, 'name' => $r->resolvedProduct->name] : null,
            ])->toArray(),
            'aliasFilters' => $aliasFilters,
            'reconcileFilters' => $reconcileFilters,
            'canOperate' => Auth::user()?->can('products.operate') ?? false,
        ]);
    }

    public function storeAlias(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'alias_sku' => ['required', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->aliases->createAlias(
                productId: (int) $data['product_id'],
                aliasSku: $data['alias_sku'],
                notes: $data['notes'] ?? null,
                actor: Auth::user(),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['alias_sku' => $e->getMessage()]);
        }

        return back()->with('success', 'SKU 别名已创建');
    }

    public function destroyAlias(int $id): RedirectResponse
    {
        $alias = \App\Modules\Products\Infrastructure\Models\ProductSkuAlias::find($id);
        if ($alias === null) {
            return back()->withErrors(['alias' => '别名不存在']);
        }

        $this->aliases->deleteAlias($alias, Auth::user());

        return back()->with('success', 'SKU 别名已删除');
    }

    public function resolveReconciliation(Request $request, int $id): RedirectResponse
    {
        $item = \App\Modules\Products\Infrastructure\Models\ProductSkuReconciliation::find($id);
        if ($item === null) {
            return back()->withErrors(['reconciliation' => '记录不存在']);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'alias_sku' => ['required', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->reconciliations->resolve(
                item: $item,
                productId: (int) $data['product_id'],
                aliasSku: $data['alias_sku'],
                note: $data['note'] ?? null,
                actor: Auth::user(),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['alias_sku' => $e->getMessage()]);
        }

        return back()->with('success', '已解析，SKU 别名已创建');
    }

    public function ignoreReconciliation(Request $request, int $id): RedirectResponse
    {
        $item = \App\Modules\Products\Infrastructure\Models\ProductSkuReconciliation::find($id);
        if ($item === null) {
            return back()->withErrors(['reconciliation' => '记录不存在']);
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->reconciliations->ignore($item, $data['note'] ?? null, Auth::user());

        return back()->with('success', '已标记为忽略');
    }

    public function reopenReconciliation(int $id): RedirectResponse
    {
        $item = \App\Modules\Products\Infrastructure\Models\ProductSkuReconciliation::find($id);
        if ($item === null) {
            return back()->withErrors(['reconciliation' => '记录不存在']);
        }

        $this->reconciliations->reopen($item, Auth::user());

        return back()->with('success', '已重新开放');
    }
}
