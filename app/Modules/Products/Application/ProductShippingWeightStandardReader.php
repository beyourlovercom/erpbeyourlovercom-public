<?php

namespace App\Modules\Products\Application;

use Illuminate\Support\Facades\DB;

final class ProductShippingWeightStandardReader
{
    /** @return array<int,array<string,mixed>> */
    public function forProductIds(array $productIds): array
    {
        $ids = collect($productIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) return [];

        $results = [];

        // Priority: valid adopted aggregated candidate pointing to same product
        $aggregated = DB::table('products as p')
            ->join(
                'product_shipping_weight_standard_candidates as c',
                'c.id', '=', 'p.shipping_weight_standard_candidate_id'
            )
            ->whereIn('p.id', $ids)
            ->whereNotNull('p.shipping_weight_kg')
            ->where('p.shipping_weight_kg', '>', 0)
            ->where('c.workflow_status', 'adopted')
            ->whereColumn('c.product_id', 'p.id')
            ->select([
                'p.id as product_id',
                'p.shipping_weight_kg',
                'c.id as standard_candidate_id',
                'c.algorithm_version',
                'c.eligible_sample_count',
                'c.raw_sample_count',
                'c.gross_outlier_count',
                'c.confidence_status',
            ])
            ->get();

        foreach ($aggregated as $row) {
            $results[(int) $row->product_id] = [
                'product_id' => (int) $row->product_id,
                'shipping_weight_kg' => number_format((float) $row->shipping_weight_kg, 4, '.', ''),
                'source_type' => 'aggregated_logistics_standard',
                'standard_candidate_id' => (int) $row->standard_candidate_id,
                'algorithm_version' => $row->algorithm_version,
                'eligible_sample_count' => (int) $row->eligible_sample_count,
                'raw_sample_count' => (int) $row->raw_sample_count,
                'gross_outlier_count' => (int) $row->gross_outlier_count,
                'confidence_status' => $row->confidence_status,
            ];
        }

        // Fallback: legacy adopted raw evidence path for products not resolved above
        $remaining = array_diff($ids, array_keys($results));
        if (count($remaining) > 0) {
            $legacy = DB::table('products as product')
                ->join('product_weight_evidences as evidence', 'evidence.id', '=', 'product.shipping_weight_evidence_id')
                ->whereIn('product.id', $remaining)
                ->whereNotNull('product.shipping_weight_kg')
                ->where('product.shipping_weight_kg', '>', 0)
                ->where('evidence.status', 'adopted')
                ->where('evidence.weight_kind', 'shipping_reference_weight')
                ->whereColumn('evidence.product_id', 'product.id')
                ->select([
                    'product.id as product_id',
                    'product.shipping_weight_kg',
                    'product.shipping_weight_evidence_id',
                    'evidence.source_type',
                    'evidence.source_reference',
                    'evidence.observed_at',
                ])
                ->get();

            foreach ($legacy as $row) {
                $results[(int) $row->product_id] = [
                    'product_id' => (int) $row->product_id,
                    'shipping_weight_kg' => number_format((float) $row->shipping_weight_kg, 4, '.', ''),
                    'evidence_id' => (int) $row->shipping_weight_evidence_id,
                    'source_type' => $row->source_type,
                    'source_reference' => $row->source_reference,
                    'observed_at' => $row->observed_at,
                ];
            }
        }

        return $results;
    }
}
