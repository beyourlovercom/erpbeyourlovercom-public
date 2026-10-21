<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductShippingWeightStandardCandidate;
use App\Modules\Products\Infrastructure\Models\ProductShippingWeightStandardCandidateEvidence;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ShippingWeightStandardCandidateService
{
    private const ALGORITHM_VERSION = 'shipping_weight_median_v1';
    private const OUTLIER_THRESHOLD = 0.15;
    private const HIGH_CONFIDENCE_MIN_ELIGIBLE = 5;
    private const HIGH_CONFIDENCE_MAX_SPREAD = 0.10;

    /**
     * Create a candidate from a set of existing ProductWeightEvidence IDs.
     * $productId is the explicit requested product; all evidences must belong to it.
     * Idempotent: identical input (same product + algorithm + sorted IDs) returns existing candidate.
     *
     * @param  array<int>  $evidenceIds
     * @throws RuntimeException on validation failure
     */
    public function createFromEvidenceIds(
        int $productId,
        array $evidenceIds,
        ?int $createdByUserId = null,
    ): ProductShippingWeightStandardCandidate {
        // Dedupe and ensure at least one
        $evidenceIds = collect($evidenceIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if (count($evidenceIds) === 0) {
            throw new RuntimeException('At least one evidence ID is required.');
        }

        // Validate all evidences exist
        $evidences = ProductWeightEvidence::whereIn('id', $evidenceIds)->get()->keyBy('id');
        if ($evidences->count() !== count($evidenceIds)) {
            $missing = array_diff($evidenceIds, $evidences->keys()->all());
            throw new RuntimeException('Evidence IDs not found: '.implode(', ', $missing));
        }

        // Validate all evidences belong to the explicit requested product
        foreach ($evidences as $e) {
            if ((int) $e->product_id !== $productId) {
                throw new RuntimeException("Evidence {$e->id} does not belong to product {$productId}.");
            }
            if ($e->weight_kind !== 'shipping_reference_weight') {
                throw new RuntimeException("Evidence {$e->id} is not weight_kind=shipping_reference_weight.");
            }
            if ($e->status === 'rejected') {
                throw new RuntimeException("Evidence {$e->id} is rejected and cannot be used.");
            }
            if ((float) $e->observed_weight_kg <= 0) {
                throw new RuntimeException("Evidence {$e->id} has non-positive weight.");
            }
        }

        // Fingerprint uses the explicit requested product ID
        $fingerprint = hash('sha256', $productId.':'.self::ALGORITHM_VERSION.':'.implode(',', $evidenceIds));

        $existing = ProductShippingWeightStandardCandidate::where('fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            return $existing;
        }

        // Algorithm: shipping_weight_median_v1
        $weights = $evidences->map(fn ($e) => (float) $e->observed_weight_kg)->values()->sort()->values();
        $rawCount = $weights->count();

        // Raw median used only as outlier reference
        $rawMedian = $this->median($weights->all());

        $classified = $evidences->map(function ($e) use ($rawMedian): array {
            $w = (float) $e->observed_weight_kg;
            $deviation = $rawMedian > 0 ? abs($w - $rawMedian) / $rawMedian : 0.0;
            $isGrossOutlier = $deviation > self::OUTLIER_THRESHOLD;
            return [
                'evidence' => $e,
                'weight' => $w,
                'deviation_ratio' => $deviation,
                'is_gross_outlier' => $isGrossOutlier,
                'is_eligible' => !$isGrossOutlier,
            ];
        })->values();

        $eligible = $classified->filter(fn ($c) => $c['is_eligible']);
        $eligibleWeights = $eligible->map(fn ($c) => $c['weight'])->sort()->values();
        $eligibleCount = $eligibleWeights->count();
        $outlierCount = $classified->filter(fn ($c) => $c['is_gross_outlier'])->count();

        if ($eligibleCount === 0) {
            throw new RuntimeException('No eligible (non-outlier) evidences remain after outlier detection.');
        }

        $suggestedWeight = $this->median($eligibleWeights->all());
        $eligibleMin = $eligibleWeights->first();
        $eligibleMax = $eligibleWeights->last();
        // Spread ratio relative to suggested weight (median of eligible)
        $spreadRatio = $suggestedWeight > 0 ? ($eligibleMax - $eligibleMin) / $suggestedWeight : 0.0;

        $isHighConfidence = $eligibleCount >= self::HIGH_CONFIDENCE_MIN_ELIGIBLE
            && $spreadRatio <= self::HIGH_CONFIDENCE_MAX_SPREAD;

        return DB::transaction(function () use (
            $productId, $suggestedWeight, $rawCount, $eligibleCount, $outlierCount,
            $eligibleMin, $eligibleMax, $spreadRatio, $isHighConfidence,
            $fingerprint, $classified, $createdByUserId
        ): ProductShippingWeightStandardCandidate {
            $candidate = ProductShippingWeightStandardCandidate::create([
                'product_id' => $productId,
                'suggested_weight_kg' => $suggestedWeight,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'workflow_status' => 'pending',
                'confidence_status' => $isHighConfidence ? 'high_confidence' : 'review_required',
                'raw_sample_count' => $rawCount,
                'eligible_sample_count' => $eligibleCount,
                'gross_outlier_count' => $outlierCount,
                'eligible_min_kg' => $eligibleMin,
                'eligible_max_kg' => $eligibleMax,
                'eligible_spread_ratio' => $spreadRatio,
                'policy_json' => [
                    'algorithm' => self::ALGORITHM_VERSION,
                    'outlier_threshold' => self::OUTLIER_THRESHOLD,
                    'high_confidence_min_eligible' => self::HIGH_CONFIDENCE_MIN_ELIGIBLE,
                    'high_confidence_max_spread' => self::HIGH_CONFIDENCE_MAX_SPREAD,
                ],
                'fingerprint' => $fingerprint,
                'created_by_user_id' => $createdByUserId,
            ]);

            foreach ($classified as $c) {
                ProductShippingWeightStandardCandidateEvidence::create([
                    'candidate_id' => $candidate->id,
                    'product_weight_evidence_id' => $c['evidence']->id,
                    'is_eligible' => $c['is_eligible'],
                    'is_gross_outlier' => $c['is_gross_outlier'],
                    'deviation_ratio' => $c['deviation_ratio'],
                ]);
            }

            return $candidate;
        });
    }

    /**
     * Adopt a candidate.
     * Returns literal string 'confirm_required' when replacing a non-null shipping weight without confirmation.
     * Returns null on success.
     */
    public function adopt(
        int $candidateId,
        int $reviewedByUserId,
        bool $confirmed = false,
    ): ?string {
        $candidate = ProductShippingWeightStandardCandidate::findOrFail($candidateId);

        if ($candidate->workflow_status !== 'pending') {
            throw new RuntimeException("Candidate {$candidateId} is not in pending status.");
        }

        $product = Product::findOrFail($candidate->product_id);

        if (!$confirmed && $product->shipping_weight_kg !== null) {
            return 'confirm_required';
        }

        DB::transaction(function () use ($candidate, $product, $reviewedByUserId): void {
            // Supersede only the previous adopted candidate currently pointed to by the product, if different
            $previousId = (int) $product->shipping_weight_standard_candidate_id;
            if ($previousId && $previousId !== $candidate->id) {
                ProductShippingWeightStandardCandidate::where('id', $previousId)
                    ->where('workflow_status', 'adopted')
                    ->update(['workflow_status' => 'superseded']);
            }

            $now = now();
            $candidate->update([
                'workflow_status' => 'adopted',
                'reviewed_by_user_id' => $reviewedByUserId,
                'reviewed_at' => $now,
            ]);

            $product->update([
                'shipping_weight_kg' => $candidate->suggested_weight_kg,
                'shipping_weight_standard_candidate_id' => $candidate->id,
                'shipping_weight_evidence_id' => null,
            ]);
        });

        return null;
    }

    /**
     * Reject a candidate. Rejection note is optional.
     */
    public function reject(
        int $candidateId,
        int $reviewedByUserId,
        ?string $rejectionNote = null,
    ): ProductShippingWeightStandardCandidate {
        $candidate = ProductShippingWeightStandardCandidate::findOrFail($candidateId);

        if ($candidate->workflow_status !== 'pending') {
            throw new RuntimeException("Candidate {$candidateId} is not in pending status.");
        }

        $candidate->update([
            'workflow_status' => 'rejected',
            'reviewed_by_user_id' => $reviewedByUserId,
            'reviewed_at' => now(),
            'rejection_note' => $rejectionNote,
        ]);

        return $candidate->fresh();
    }

    /** @param  float[]  $values sorted ascending */
    private function median(array $values): float
    {
        $n = count($values);
        if ($n === 0) return 0.0;
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
