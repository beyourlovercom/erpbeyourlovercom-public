<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\ProductCostEvidenceReader;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class EloquentProductCostEvidenceReader implements ProductCostEvidenceReader
{
    public function read(int|string $orderId): array
    {
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $order = $orderClass::query()->with('items.product')->find($orderId);
        if ($order === null) {
            return [];
        }

        $items = $order->items->sortBy('id')->values();
        $productIds = $items->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $sources = collect();
        if ($productIds !== [] && Schema::hasTable('product_cost_sources')) {
            $date = $order->order_date?->format('Y-m-d');
            $sources = ProductCostSource::query()
                ->whereIn('product_id', $productIds)
                ->where('status', 'confirmed')
                ->when($date !== null, fn ($q) => $q
                    ->whereDate('effective_from', '<=', $date)
                    ->where(fn ($inner) => $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date)))
                ->orderBy('product_id')
                ->orderBy('effective_from', 'desc')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->map(function (Collection $history): Collection {
                    $latest = $history->max(fn (ProductCostSource $source): string => $source->effective_from?->format('Y-m-d') ?? '');
                    return $history->filter(fn (ProductCostSource $source): bool => ($source->effective_from?->format('Y-m-d') ?? '') === $latest)->values();
                });
        }

        return $items->map(function (Model $item) use ($sources): array {
            $product = $item->product;
            /** @var Collection<int,ProductCostSource> $confirmed */
            $confirmed = $product === null ? collect() : ($sources->get($product->getKey()) ?? collect());
            return [
                'item_id' => (int) $item->getKey(),
                'product_id' => $product?->getKey() === null ? null : (int) $product->getKey(),
                'sku' => $item->sku,
                'product_name' => $item->product_summary ?: $product?->name,
                'quantity' => $item->quantity,
                'confirmed_cost_sources' => $confirmed->map(fn (ProductCostSource $source): array => [
                    'id' => (int) $source->getKey(),
                    'unit_cost' => (string) $source->unit_cost,
                    'currency' => strtoupper((string) $source->currency),
                    'provenance' => (string) $source->provenance,
                    'source_key' => (string) $source->source_key,
                    'source_reference' => (string) $source->source_reference,
                    'effective_from' => $source->effective_from?->format('Y-m-d'),
                    'source_at' => $source->source_at?->toIso8601String(),
                    'confirmed_at' => $source->confirmed_at?->toIso8601String(),
                ])->all(),
                'cost_currency' => $product?->cost_currency,
                'standard_purchase_cost' => $product?->standard_purchase_cost,
                'standard_production_cost' => $product?->standard_production_cost,
                'standard_packaging_cost' => $product?->standard_packaging_cost,
                'updated_at' => $product?->updated_at,
            ];
        })->all();
    }
}
