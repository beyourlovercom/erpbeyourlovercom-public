<?php

namespace App\Modules\Inventory\Application\PackagingScan;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use Illuminate\Support\Collection;

final class PackagingScanEvidencePreviewService
{
    /** @param list<array<string,mixed>> $rows */
    public function preview(array $rows): array
    {
        $refs = collect($rows)->pluck('order_reference')->filter()->map(fn ($v) => trim((string) $v))->unique()->values();
        $orders = $this->ordersForReferences($refs);
        $referenceMap = $this->referenceMap($refs, $orders);
        $resultRows = [];
        foreach ($rows as $row) $resultRows[] = $this->classify($row, $referenceMap, $orders);
        $collection = collect($resultRows);
        return [
            'summary' => [
                'rows' => $collection->count(),
                'matched_orders' => $collection->where('order_match_status', 'matched')->count(),
                'ready_weight_evidence' => $collection->where('can_be_weight_evidence', true)->count(),
                'ready_dimension_evidence' => $collection->where('can_be_dimension_evidence', true)->count(),
                'ready_weight_and_dimension_evidence' => $collection->where('evidence_status', 'ready_weight_and_dimension_evidence')->count(),
                'ready_weight_only_evidence' => $collection->where('evidence_status', 'ready_weight_evidence_only')->count(),
                'ready_dimension_only_evidence' => $collection->where('evidence_status', 'ready_dimension_evidence_only')->count(),
                'ready_single_unit_evidence' => $collection->where('can_be_product_evidence', true)->count(),
                'placeholder_dimensions' => $collection->where('dimension_evidence_status', 'placeholder_dimensions')->count(),
                'missing_dimensions' => $collection->where('dimension_evidence_status', 'missing_dimensions')->count(),
                'missing_actual_weight' => $collection->where('weight_evidence_status', 'missing_actual_weight')->count(),
                'multi_unit' => $collection->where('classification', 'single_sku_multi_unit')->count(),
                'multi_sku' => $collection->where('classification', 'multi_sku')->count(),
                'unmatched_orders' => $collection->where('order_match_status', 'not_found')->count(),
                'ambiguous_orders' => $collection->where('order_match_status', 'ambiguous')->count(),
            ],
            'rows' => $resultRows,
            'weight_candidates' => $this->weightCandidates($collection),
        ];
    }

    private function ordersForReferences(Collection $refs): Collection
    {
        if ($refs->isEmpty()) return collect();
        $values = $refs->all();
        $direct = Order::query()->with(['items.product:id,sku,name'])
            ->where(function ($q) use ($values): void {
                $q->whereIn('external_order_number', $values)
                    ->orWhereIn('internal_order_number', $values)
                    ->orWhereIn('erp_order_id', $values);
            })->get();
        $externalIds = OrderExternalReference::query()->whereIn('reference', $values)->pluck('order_id');
        $external = $externalIds->isEmpty() ? collect() : Order::query()->with(['items.product:id,sku,name'])->whereIn('id', $externalIds)->get();
        return $direct->concat($external)->unique('id')->keyBy('id');
    }

    private function referenceMap(Collection $refs, Collection $orders): array
    {
        $map = [];
        foreach ($refs as $ref) $map[(string) $ref] = [];
        foreach ($orders as $order) {
            foreach ([$order->external_order_number, $order->internal_order_number, $order->erp_order_id] as $value) {
                $value = trim((string) $value);
                if ($value !== '' && array_key_exists($value, $map)) $map[$value][$order->id] = true;
            }
        }
        if ($refs->isNotEmpty()) {
            foreach (OrderExternalReference::query()->whereIn('reference', $refs->all())->get(['order_id','reference']) as $external) {
                $ref = trim((string) $external->reference);
                if (array_key_exists($ref, $map) && $orders->has($external->order_id)) $map[$ref][$external->order_id] = true;
            }
        }
        return $map;
    }

    private function classify(array $row, array $referenceMap, Collection $orders): array
    {
        $ref = trim((string) ($row['order_reference'] ?? ''));
        $ids = array_keys($referenceMap[$ref] ?? []);
        $base = [
            'source_row_number' => (int) ($row['source_row_number'] ?? 0),
            'order_reference' => $ref !== '' ? $ref : null,
            'tracking_number' => $this->text($row['tracking_number'] ?? null),
            'servicer_reference' => $this->text($row['servicer_reference'] ?? null),
            'forecast_weight_kg' => $this->number($row['forecast_weight_kg'] ?? null),
            'inbound_weight_kg' => $this->number($row['inbound_weight_kg'] ?? null),
            'volume_weight_kg' => $this->number($row['volume_weight_kg'] ?? null),
            'billing_weight_kg' => $this->number($row['billing_weight_kg'] ?? null),
            'weight_difference_kg' => $this->number($row['weight_difference_kg'] ?? null),
            'length_cm' => $this->number($row['length_cm'] ?? null),
            'width_cm' => $this->number($row['width_cm'] ?? null),
            'height_cm' => $this->number($row['height_cm'] ?? null),
            'measured_at' => $this->text($row['measured_at'] ?? null),
        ];
        if ($ids === []) return $this->unresolved($base, 'not_found', 'order_not_found');
        if (count($ids) > 1) return $this->unresolved($base, 'ambiguous', 'ambiguous_order');
        $order = $orders->get($ids[0]);
        if (! $order) return $this->unresolved($base, 'not_found', 'order_not_found');
        $items = $order->items->filter(fn ($item) => (int) $item->quantity > 0);
        if ($items->isEmpty()) return $this->resolvedBase($base, $order, 'no_items', 'order_has_no_items');
        if ($items->contains(fn ($item) => $item->product_id === null || $item->product === null)) return $this->resolvedBase($base, $order, 'unresolved_product', 'unresolved_product');
        $byProduct = $items->groupBy('product_id')->map(fn ($group) => ['item'=>$group->first(), 'quantity'=>$group->sum(fn ($item) => (int) $item->quantity)]);
        if ($byProduct->count() > 1) return $this->resolvedBase($base, $order, 'multi_sku', 'multi_sku_parcel');
        $entry = $byProduct->first(); $item = $entry['item']; $qty = (int) $entry['quantity'];
        $classification = $qty === 1 ? 'single_sku_single_unit' : 'single_sku_multi_unit';
        $product = ['id'=>$item->product->id,'sku'=>$item->product->sku,'name'=>$item->product->name,'quantity'=>$qty];
        if ($qty !== 1) return [
            ...$base,
            'order_match_status'=>'matched',
            'classification'=>$classification,
            'evidence_status'=>'multi_unit_parcel',
            'weight_evidence_status'=>'ineligible_multi_unit',
            'dimension_evidence_status'=>'ineligible_multi_unit',
            'can_be_product_evidence'=>false,
            'can_be_weight_evidence'=>false,
            'can_be_dimension_evidence'=>false,
            'weight_evidence_candidate'=>null,
            'dimension_evidence_candidate'=>null,
            'order'=>$this->order($order),
            'product'=>$product,
        ];

        $weightReady = $base['inbound_weight_kg'] !== null && $base['inbound_weight_kg'] > 0;
        $dims = [$base['length_cm'],$base['width_cm'],$base['height_cm']];
        $hasAllDimensions = ! collect($dims)->contains(fn ($v) => $v === null || $v <= 0);
        $placeholder = $hasAllDimensions && $this->placeholderDimensions($dims);
        $dimensionReady = $hasAllDimensions && ! $placeholder;

        $weightStatus = $weightReady ? 'ready_weight_evidence' : 'missing_actual_weight';
        $dimensionStatus = $dimensionReady ? 'ready_dimension_evidence' : ($placeholder ? 'placeholder_dimensions' : 'missing_dimensions');
        $combinedStatus = match (true) {
            $weightReady && $dimensionReady => 'ready_weight_and_dimension_evidence',
            $weightReady => 'ready_weight_evidence_only',
            $dimensionReady => 'ready_dimension_evidence_only',
            default => 'no_product_measurement_evidence',
        };

        $weightCandidate = $weightReady ? [
            'product_id' => (int) $item->product->id,
            'weight_kind' => 'shipping_reference_weight',
            'observed_weight_kg' => $base['inbound_weight_kg'],
            'raw_sku' => $item->product->sku,
            'source_type' => 'logistics_scan',
            'source_reference' => $base['tracking_number'] ?? $ref,
            'source_order_reference' => $ref,
            'observed_at' => $base['measured_at'],
        ] : null;

        $dimensionCandidate = $dimensionReady ? [
            'product_id' => (int) $item->product->id,
            'sku' => $item->product->sku,
            'length_cm' => $base['length_cm'],
            'width_cm' => $base['width_cm'],
            'height_cm' => $base['height_cm'],
            'volume_cm3' => round((float) $base['length_cm'] * (float) $base['width_cm'] * (float) $base['height_cm'], 3),
            'source_reference' => $base['tracking_number'] ?? $ref,
            'source_order_reference' => $ref,
            'observed_at' => $base['measured_at'],
        ] : null;

        return [
            ...$base,
            'order_match_status'=>'matched',
            'classification'=>$classification,
            'evidence_status'=>$combinedStatus,
            'weight_evidence_status'=>$weightStatus,
            'dimension_evidence_status'=>$dimensionStatus,
            'can_be_product_evidence'=>$weightReady || $dimensionReady,
            'can_be_weight_evidence'=>$weightReady,
            'can_be_dimension_evidence'=>$dimensionReady,
            'weight_evidence_candidate'=>$weightCandidate,
            'dimension_evidence_candidate'=>$dimensionCandidate,
            'order'=>$this->order($order),
            'product'=>$product,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function weightCandidates(Collection $rows): array
    {
        return $rows
            ->filter(fn (array $row): bool => ($row['can_be_weight_evidence'] ?? false) && is_array($row['weight_evidence_candidate'] ?? null))
            ->groupBy(fn (array $row): string => (string) $row['weight_evidence_candidate']['product_id'])
            ->map(function (Collection $group): array {
                $samples = $group->unique(function (array $row): string {
                    $candidate = $row['weight_evidence_candidate'];
                    return implode('|', [
                        (string) ($candidate['source_reference'] ?? ''),
                        (string) ($candidate['source_order_reference'] ?? ''),
                        (string) ($candidate['observed_at'] ?? ''),
                        (string) ($candidate['observed_weight_kg'] ?? ''),
                    ]);
                })->values();

                $weights = $samples->pluck('weight_evidence_candidate.observed_weight_kg')->map(fn ($value): float => (float) $value)->sort()->values()->all();
                $medianAll = $this->median($weights);
                $threshold = $medianAll * 0.15;
                $eligible = array_values(array_filter($weights, fn (float $weight): bool => abs($weight - $medianAll) <= $threshold + 0.0000001));
                $suggested = $this->median($eligible);
                $min = min($eligible);
                $max = max($eligible);
                $spreadRatio = $suggested > 0 ? ($max - $min) / $suggested : 1.0;
                $highConfidence = count($eligible) >= 5 && $spreadRatio <= 0.10;
                $first = $samples->first();

                return [
                    'product_id' => (int) $first['product']['id'],
                    'sku' => (string) $first['product']['sku'],
                    'name' => (string) $first['product']['name'],
                    'status' => $highConfidence ? 'high_confidence' : 'review_required',
                    'suggested_shipping_weight_kg' => round($suggested, 3),
                    'raw_sample_count' => count($weights),
                    'eligible_sample_count' => count($eligible),
                    'gross_outlier_count' => count($weights) - count($eligible),
                    'median_all_kg' => round($medianAll, 3),
                    'eligible_min_kg' => round($min, 3),
                    'eligible_max_kg' => round($max, 3),
                    'eligible_spread_ratio' => round($spreadRatio, 4),
                    'policy' => [
                        'minimum_high_confidence_samples' => 5,
                        'gross_outlier_relative_deviation' => 0.15,
                        'maximum_high_confidence_spread_ratio' => 0.10,
                    ],
                    'samples' => $samples->map(function (array $row) use ($medianAll, $threshold): array {
                        $candidate = $row['weight_evidence_candidate'];
                        $weight = (float) $candidate['observed_weight_kg'];
                        return [
                            'order_reference' => $candidate['source_order_reference'] ?? null,
                            'source_reference' => $candidate['source_reference'] ?? null,
                            'observed_weight_kg' => $weight,
                            'observed_at' => $candidate['observed_at'] ?? null,
                            'gross_outlier' => abs($weight - $medianAll) > $threshold + 0.0000001,
                        ];
                    })->values()->all(),
                ];
            })
            ->sortByDesc(fn (array $candidate): int => $candidate['eligible_sample_count'])
            ->values()
            ->all();
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) return 0.0;
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 1 ? (float) $values[$middle] : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private function unresolved(array $base, string $matchStatus, string $status): array
    {
        return [
            ...$base,
            'order_match_status'=>$matchStatus,
            'classification'=>'unresolved',
            'evidence_status'=>$status,
            'weight_evidence_status'=>'ineligible_unresolved_order',
            'dimension_evidence_status'=>'ineligible_unresolved_order',
            'can_be_product_evidence'=>false,
            'can_be_weight_evidence'=>false,
            'can_be_dimension_evidence'=>false,
            'weight_evidence_candidate'=>null,
            'dimension_evidence_candidate'=>null,
            'order'=>null,
            'product'=>null,
        ];
    }

    private function resolvedBase(array $base, Order $order, string $classification, string $status): array
    {
        return [
            ...$base,
            'order_match_status'=>'matched',
            'classification'=>$classification,
            'evidence_status'=>$status,
            'weight_evidence_status'=>'ineligible_'.$classification,
            'dimension_evidence_status'=>'ineligible_'.$classification,
            'can_be_product_evidence'=>false,
            'can_be_weight_evidence'=>false,
            'can_be_dimension_evidence'=>false,
            'weight_evidence_candidate'=>null,
            'dimension_evidence_candidate'=>null,
            'order'=>$this->order($order),
            'product'=>null,
        ];
    }
    private function order(Order $order): array
    { return ['id'=>$order->id,'external_order_number'=>$order->external_order_number,'internal_order_number'=>$order->internal_order_number,'erp_order_id'=>$order->erp_order_id]; }
    private function placeholderDimensions(array $dims): bool
    { return count($dims) === 3 && abs((float)$dims[0]-1.0)<0.0001 && abs((float)$dims[1]-1.0)<0.0001 && abs((float)$dims[2]-1.0)<0.0001; }
    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string)$value) === '') return null;
        $normalized = str_replace([',','kg','KG','cm','CM'], '', trim((string)$value));
        return is_numeric($normalized) ? (float) $normalized : null;
    }
    private function text(mixed $value): ?string { $value=trim((string)($value ?? '')); return $value===''?null:$value; }
}
