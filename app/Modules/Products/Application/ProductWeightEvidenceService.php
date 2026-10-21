<?php

namespace App\Modules\Products\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\WeightEvidenceRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ProductWeightEvidenceService
{
    public const SOURCE_TYPES = [
        'woocommerce_reference',
        'manual_entry',
        'manual_measurement',
        'supplier_document',
        'warehouse_measurement',
        'historical_logistics',
        'logistics_scan',
        'other_documented',
    ];

    public const WEIGHT_KINDS = [
        'product_weight',
        'shipping_reference_weight',
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SkuIdentityResolver $resolver,
        private readonly WeightEvidenceRepository $evidences,
        private readonly ProductRepository $products,
    ) {}

    public function createEvidence(Model $product, array $attributes, ?int $actorUserId): Model
    {
        $evidence = $this->evidences->create([
            'product_id' => $product->getKey(),
            'weight_kind' => $attributes['weight_kind'],
            'observed_weight_kg' => $attributes['observed_weight_kg'],
            'raw_sku' => $attributes['raw_sku'] ?? null,
            'source_type' => $attributes['source_type'],
            'source_reference' => $attributes['source_reference'] ?? null,
            'source_note' => $attributes['source_note'] ?? null,
            'observed_at' => $attributes['observed_at'] ?? null,
            'status' => 'pending',
            'created_by_user_id' => $actorUserId,
        ]);

        $actor = $this->actor($actorUserId);
        $this->audit->record(
            actor: $actor,
            action: 'product.weight_evidence.created',
            entityType: 'product_weight_evidence',
            entityId: $evidence->getKey(),
            after: [
                'product_id' => $product->getKey(),
                'sku' => $product->getAttribute('sku'),
                'weight_kind' => $evidence->getAttribute('weight_kind'),
                'observed_weight_kg' => $evidence->getAttribute('observed_weight_kg'),
                'source_type' => $evidence->getAttribute('source_type'),
                'evidence_id' => $evidence->getKey(),
            ],
        );

        return $evidence;
    }

    /**
     * Adopt evidence onto the product.
     * Returns 'confirm_required' if a non-null current value would be replaced and $confirmed is false.
     * Returns null on success.
     */
    public function adoptEvidence(Model $evidence, bool $confirmed, ?int $actorUserId): ?string
    {
        $product = $this->products->findById((int) $evidence->getAttribute('product_id'));
        if ($product === null) {
            return null;
        }

        $weightKind = $evidence->getAttribute('weight_kind');
        [$targetField, $pointerField] = $this->fieldPair($weightKind);

        $currentValue = $product->getAttribute($targetField);

        if ($currentValue !== null && !$confirmed) {
            return 'confirm_required';
        }

        DB::transaction(function () use ($evidence, $product, $targetField, $pointerField, $currentValue, $actorUserId): void {
            $before = [
                'product_id' => $product->getKey(),
                'sku' => $product->getAttribute('sku'),
                'target_field' => $targetField,
                'before_value' => $currentValue,
                'evidence_id' => $product->getAttribute($pointerField),
            ];

            $this->products->update($product, [
                $targetField => $evidence->getAttribute('observed_weight_kg'),
                $pointerField => $evidence->getKey(),
            ]);

            $this->evidences->update($evidence, [
                'status' => 'adopted',
                'reviewed_by_user_id' => $actorUserId,
                'reviewed_at' => now(),
            ]);

            $actor = $this->actor($actorUserId);
            $this->audit->record(
                actor: $actor,
                action: 'product.weight_evidence.adopted',
                entityType: 'product',
                entityId: $product->getKey(),
                before: $before,
                after: [
                    'product_id' => $product->getKey(),
                    'sku' => $product->getAttribute('sku'),
                    'target_field' => $targetField,
                    'after_value' => (string) $evidence->getAttribute('observed_weight_kg'),
                    'evidence_id' => $evidence->getKey(),
                    'source_type' => $evidence->getAttribute('source_type'),
                ],
            );
        });

        return null;
    }

    public function rejectEvidence(Model $evidence, ?string $rejectionNote, ?int $actorUserId): void
    {
        $this->evidences->update($evidence, [
            'status' => 'rejected',
            'reviewed_by_user_id' => $actorUserId,
            'reviewed_at' => now(),
            'rejection_note' => $rejectionNote,
        ]);

        $this->audit->record(
            actor: $this->actor($actorUserId),
            action: 'product.weight_evidence.rejected',
            entityType: 'product_weight_evidence',
            entityId: $evidence->getKey(),
            after: [
                'product_id' => $evidence->getAttribute('product_id'),
                'evidence_id' => $evidence->getKey(),
                'rejection_note' => $rejectionNote,
            ],
        );
    }

    public function clearWeight(Model $product, string $weightKind, ?int $actorUserId): void
    {
        [$targetField, $pointerField] = $this->fieldPair($weightKind);

        $before = [
            'product_id' => $product->getKey(),
            'sku' => $product->getAttribute('sku'),
            'target_field' => $targetField,
            'before_value' => $product->getAttribute($targetField),
            'evidence_id' => $product->getAttribute($pointerField),
        ];

        DB::transaction(function () use ($product, $targetField, $pointerField): void {
            $this->products->update($product, [
                $targetField => null,
                $pointerField => null,
            ]);
        });

        $this->audit->record(
            actor: $this->actor($actorUserId),
            action: 'product.weight.cleared',
            entityType: 'product',
            entityId: $product->getKey(),
            before: $before,
            after: [
                'product_id' => $product->getKey(),
                'sku' => $product->getAttribute('sku'),
                'target_field' => $targetField,
                'after_value' => null,
                'evidence_id' => null,
            ],
        );
    }

    /**
     * Create and immediately adopt a manual_entry evidence within a transaction.
     * Called by ProductService when a weight value is set/changed via UI.
     */
    public function createAndAdoptManualEntry(
        Model $product,
        string $weightKind,
        float $newValue,
        ?int $actorUserId,
        bool $confirmed = false,
    ): Model {
        [$targetField, $pointerField] = $this->fieldPair($weightKind);

        $currentValue = $product->getAttribute($targetField);

        $evidence = null;

        DB::transaction(function () use ($product, $weightKind, $newValue, $actorUserId, $targetField, $pointerField, $currentValue, &$evidence): void {
            $evidence = $this->evidences->create([
                'product_id' => $product->getKey(),
                'weight_kind' => $weightKind,
                'observed_weight_kg' => $newValue,
                'raw_sku' => $product->getAttribute('sku'),
                'source_type' => 'manual_entry',
                'status' => 'adopted',
                'created_by_user_id' => $actorUserId,
                'reviewed_by_user_id' => $actorUserId,
                'reviewed_at' => now(),
            ]);

            $before = [
                'product_id' => $product->getKey(),
                'sku' => $product->getAttribute('sku'),
                'target_field' => $targetField,
                'before_value' => $currentValue,
                'evidence_id' => $product->getAttribute($pointerField),
            ];

            $this->products->update($product, [
                $targetField => $newValue,
                $pointerField => $evidence->getKey(),
            ]);

            $actor = $this->actor($actorUserId);

            $this->audit->record(
                actor: $actor,
                action: 'product.weight_evidence.created',
                entityType: 'product_weight_evidence',
                entityId: $evidence->getKey(),
                after: [
                    'product_id' => $product->getKey(),
                    'sku' => $product->getAttribute('sku'),
                    'weight_kind' => $weightKind,
                    'observed_weight_kg' => (string) $newValue,
                    'source_type' => 'manual_entry',
                    'evidence_id' => $evidence->getKey(),
                ],
            );

            $this->audit->record(
                actor: $actor,
                action: 'product.weight_evidence.adopted',
                entityType: 'product',
                entityId: $product->getKey(),
                before: $before,
                after: [
                    'product_id' => $product->getKey(),
                    'sku' => $product->getAttribute('sku'),
                    'target_field' => $targetField,
                    'after_value' => (string) $newValue,
                    'evidence_id' => $evidence->getKey(),
                    'source_type' => 'manual_entry',
                ],
            );
        });

        return $evidence;
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->evidences->paginateFiltered($filters, $perPage);
    }

    public function findById(int $id): ?Model
    {
        return $this->evidences->findById($id);
    }

    public function coverageSummary(): array
    {
        $counts = $this->products->coverageCounts();
        $total = $counts['total'];

        return [
            'total_products' => $total,
            'weight_kg_filled' => $counts['weight_filled'],
            'weight_kg_missing' => $total - $counts['weight_filled'],
            'shipping_weight_kg_filled' => $counts['shipping_filled'],
            'shipping_weight_kg_missing' => $total - $counts['shipping_filled'],
            'pending_evidence_count' => $this->evidences->pendingCount(),
        ];
    }

    /** Preview CSV — zero mutations. */
    public function previewCsv(string $csvPath): array
    {
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return $this->emptyPreviewResult(['Cannot open file']);
        }

        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            return $this->emptyPreviewResult(['Empty or unreadable CSV']);
        }

        $headerLine = (string) preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
        $headers = str_getcsv($headerLine);
        $headerMap = array_flip(array_map(static fn ($h): string => trim((string) $h), $headers));

        foreach (['SKU', 'Weight (kg)', 'Weight Kind', 'Source Type'] as $required) {
            if (!isset($headerMap[$required])) {
                fclose($handle);
                return $this->emptyPreviewResult(["Missing required column: {$required}"]);
            }
        }

        $result = [
            'total_rows' => 0,
            'valid_rows' => 0,
            'unmatched_sku_rows' => 0,
            'invalid_weight_rows' => 0,
            'duplicate_rows' => 0,
            'would_replace_non_null' => 0,
            'would_fill_null' => 0,
            'evidence_only_rows' => 0,
            'errors' => [],
            'valid_candidates' => [],
        ];

        $seenKeys = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($row, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $result['total_rows']++;
            $cell = static fn (string $col): ?string => isset($headerMap[$col]) ? (($v = trim((string) ($row[$headerMap[$col]] ?? ''))) === '' ? null : $v) : null;

            $rawSku = $cell('SKU');
            $rawWeight = $cell('Weight (kg)');
            $weightKind = $cell('Weight Kind');
            $sourceType = $cell('Source Type');
            $sourceRef = $cell('Source Reference');
            $observedAt = $cell('Observed At');
            $note = $cell('Note');

            if ($rawSku === null) {
                $result['invalid_weight_rows']++;
                $result['errors'][] = "Row {$rowNum}: missing SKU";
                continue;
            }

            if (!in_array($weightKind, self::WEIGHT_KINDS, true)) {
                $result['invalid_weight_rows']++;
                $result['errors'][] = "Row {$rowNum}: invalid Weight Kind '{$weightKind}'";
                continue;
            }

            if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
                $result['invalid_weight_rows']++;
                $result['errors'][] = "Row {$rowNum}: invalid Source Type '{$sourceType}'";
                continue;
            }

            if ($rawWeight === null || !is_numeric($rawWeight) || (float) $rawWeight <= 0) {
                $result['invalid_weight_rows']++;
                $result['errors'][] = "Row {$rowNum}: weight must be strictly greater than zero (got: '{$rawWeight}')";
                continue;
            }

            $dedupeKey = "{$rawSku}|{$weightKind}";
            if (isset($seenKeys[$dedupeKey])) {
                $result['duplicate_rows']++;
                $result['errors'][] = "Row {$rowNum}: duplicate SKU+WeightKind combination '{$rawSku}' / '{$weightKind}'";
                continue;
            }
            $seenKeys[$dedupeKey] = true;

            $resolved = $this->resolver->resolve($rawSku);
            if (!$resolved->isResolved()) {
                $result['unmatched_sku_rows']++;
                $result['errors'][] = "Row {$rowNum}: SKU '{$rawSku}' not found";
                continue;
            }

            $product = $resolved->product;
            [$targetField] = $this->fieldPair($weightKind);
            $currentValue = $product->getAttribute($targetField);

            if ($currentValue !== null) {
                $result['would_replace_non_null']++;
            } else {
                $result['would_fill_null']++;
            }

            $result['evidence_only_rows']++;
            $result['valid_rows']++;
            $result['valid_candidates'][] = [
                'product_id' => $product->getKey(),
                'canonical_sku' => $resolved->canonicalSku,
                'raw_sku' => $rawSku,
                'weight_kind' => $weightKind,
                'observed_weight_kg' => (float) $rawWeight,
                'source_type' => $sourceType,
                'source_reference' => $sourceRef,
                'source_note' => $note,
                'observed_at' => $observedAt,
                'resolve_type' => $resolved->type,
                'current_value' => $currentValue,
            ];
        }

        fclose($handle);

        return $result;
    }

    /** Confirm CSV — creates evidence only, no adoption. */
    public function confirmCsv(array $candidates, ?int $actorUserId): int
    {
        $created = 0;

        DB::transaction(function () use ($candidates, $actorUserId, &$created): void {
            foreach ($candidates as $candidate) {
                $evidence = $this->evidences->create([
                    'product_id' => $candidate['product_id'],
                    'weight_kind' => $candidate['weight_kind'],
                    'observed_weight_kg' => $candidate['observed_weight_kg'],
                    'raw_sku' => $candidate['raw_sku'],
                    'source_type' => $candidate['source_type'],
                    'source_reference' => $candidate['source_reference'] ?? null,
                    'source_note' => $candidate['source_note'] ?? null,
                    'observed_at' => $candidate['observed_at'] ?? null,
                    'status' => 'pending',
                    'created_by_user_id' => $actorUserId,
                ]);

                $product = $this->products->findById((int) $candidate['product_id']);

                $this->audit->record(
                    actor: $this->actor($actorUserId),
                    action: 'product.weight_evidence.created',
                    entityType: 'product_weight_evidence',
                    entityId: $evidence->getKey(),
                    after: [
                        'product_id' => $candidate['product_id'],
                        'sku' => $product?->getAttribute('sku'),
                        'raw_sku' => $candidate['raw_sku'],
                        'weight_kind' => $candidate['weight_kind'],
                        'observed_weight_kg' => (string) $candidate['observed_weight_kg'],
                        'source_type' => $candidate['source_type'],
                        'evidence_id' => $evidence->getKey(),
                    ],
                );

                $created++;
            }
        });

        return $created;
    }

    /** @return array{0: string, 1: string} */
    private function fieldPair(string $weightKind): array
    {
        return $weightKind === 'product_weight'
            ? ['weight_kg', 'weight_evidence_id']
            : ['shipping_weight_kg', 'shipping_weight_evidence_id'];
    }

    private function actor(?int $userId): AuditActor
    {
        if ($userId !== null && Auth::check()) {
            return AuditActor::user(Auth::user(), AuditSource::Web);
        }

        return AuditActor::system(AuditSource::System);
    }

    private function emptyPreviewResult(array $errors = []): array
    {
        return [
            'total_rows' => 0,
            'valid_rows' => 0,
            'unmatched_sku_rows' => 0,
            'invalid_weight_rows' => 0,
            'duplicate_rows' => 0,
            'would_replace_non_null' => 0,
            'would_fill_null' => 0,
            'evidence_only_rows' => 0,
            'errors' => $errors,
            'valid_candidates' => [],
        ];
    }
}
