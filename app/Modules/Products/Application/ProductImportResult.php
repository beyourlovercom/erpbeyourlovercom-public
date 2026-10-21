<?php

namespace App\Modules\Products\Application;

final class ProductImportResult
{
    public function __construct(
        public readonly int $totalRows = 0,
        public readonly int $candidates = 0,
        public readonly int $newCandidates = 0,
        public readonly int $updates = 0,
        public readonly int $unchanged = 0,
        public readonly int $active = 0,
        public readonly int $inactive = 0,
        public readonly int $skippedNoSkuVariations = 0,
        public readonly int $noSkuNonVariationReconciliation = 0,
        public readonly int $invalid = 0,
        public readonly int $duplicateSkuConflicts = 0,
        public readonly int $identityConflicts = 0,
        public readonly array $errors = [],
        public readonly int $resolvedVariationMappings = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'totalRows' => $this->totalRows,
            'candidates' => $this->candidates,
            'newCandidates' => $this->newCandidates,
            'updates' => $this->updates,
            'unchanged' => $this->unchanged,
            'active' => $this->active,
            'inactive' => $this->inactive,
            'skippedNoSkuVariations' => $this->skippedNoSkuVariations,
            'noSkuNonVariationReconciliation' => $this->noSkuNonVariationReconciliation,
            'invalid' => $this->invalid,
            'duplicateSkuConflicts' => $this->duplicateSkuConflicts,
            'identityConflicts' => $this->identityConflicts,
            'errors' => $this->errors,
            'resolvedVariationMappings' => $this->resolvedVariationMappings,
            'unresolvedVariationMappings' => max(0, $this->skippedNoSkuVariations - $this->resolvedVariationMappings),
            'canConfirm' => ($this->candidates > 0 || $this->resolvedVariationMappings > 0) && $this->duplicateSkuConflicts === 0 && $this->identityConflicts === 0,
        ];
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'inserted' => $this->newCandidates,
            'updated' => $this->updates,
            'skipped' => $this->unchanged,
            default => null,
        };
    }
}
