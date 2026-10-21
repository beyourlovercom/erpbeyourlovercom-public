<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\ProductExternalReferenceRepository;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\SkuMatchKey;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly SkuAliasRepository $skuAliases,
        private readonly ProductWeightEvidenceService $weightEvidence,
        private readonly ProductExternalReferenceRepository $externalRefs,
    ) {}

    /**
     * @return array{result: ProductImportResult, candidates: list<array<string, mixed>>}
     *
     * $connectionKey scopes Woo product/variation external references.
     * When null, falls back to the configured services.woocommerce.connection_key.
     */
    public function preview(string $csvPath, ?string $connectionKey = null): array
    {
        $connectionKey = $connectionKey ?? (string) config('services.woocommerce.connection_key', 'beyourlover.com');
        $usesLegacyWooId = $this->usesLegacyWooId($connectionKey);

        $handle = fopen($csvPath, 'r');
        if ($handle === false) return ['result' => new ProductImportResult(invalid: 1, errors: ["Cannot open file: {$csvPath}"]), 'candidates' => []];
        $headerLine = fgets($handle);
        if ($headerLine === false) { fclose($handle); return ['result' => new ProductImportResult(invalid: 1, errors: ['Empty or unreadable CSV']), 'candidates' => []]; }
        $headerLine = (string) preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
        $headers = str_getcsv($headerLine);
        $headerMap = array_flip(array_map(static fn ($header): string => trim((string) $header), $headers));
        foreach (['ID', 'Type', 'SKU', 'Name', 'Published'] as $required) {
            if (! isset($headerMap[$required])) { fclose($handle); return ['result' => new ProductImportResult(invalid: 1, errors: ["Missing required column: {$required}"]), 'candidates' => []]; }
        }

        $total = $candidates = $active = $inactive = $invalid = $skippedVariations = $reconciliation = 0;
        $new = $updates = $unchanged = $duplicateSku = $identity = $resolvedVariations = 0;
        $errors = $candidateRows = $seenSkus = $seenWooIds = [];

        // First pass: collect all rows so we can build parent-lookup maps.
        $allRows = [];
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if ($this->rowIsEmpty($row)) continue;
            $allRows[] = ['row' => $row, 'rowNum' => $rowNum];
        }
        fclose($handle);

        $cell = static fn (array $row, array $map, string $column): ?string => isset($map[$column]) ? (($value = trim((string) ($row[$map[$column]] ?? ''))) === '' ? null : $value) : null;

        // Pre-scan: build batch-level SKU -> wooId and wooId -> SKU maps for in-batch parent resolution.
        // Only non-variation rows with a SKU can act as parents.
        $batchSkuToWooId = [];  // SKU -> woo ID (for Parent=SKU lookup within batch)
        $batchWooIdToSku = [];  // woo ID -> SKU (for Parent=id:<ID> lookup within batch)
        foreach ($allRows as $entry) {
            ['row' => $row] = $entry;
            $bwooIdRaw = $cell($row, $headerMap, 'ID');
            $bwooId = ctype_digit((string) $bwooIdRaw) ? (int) $bwooIdRaw : 0;
            if ($bwooId <= 0) continue;
            $btype = strtolower((string) ($cell($row, $headerMap, 'Type') ?? ''));
            if ($btype === 'variation') continue;
            $bsku = $cell($row, $headerMap, 'SKU');
            if ($bsku === null) continue;
            $batchSkuToWooId[$bsku] = $bwooId;
            $batchWooIdToSku[$bwooId] = $bsku;
        }

        // Phase 1: process SKU-bearing rows (simple/variable/sku-variation).
        $variationRows = [];  // deferred blank-SKU variations for phase 2
        foreach ($allRows as $entry) {
            ['row' => $row, 'rowNum' => $rowNum] = $entry;
            $total++;
            $wooIdRaw = $cell($row, $headerMap, 'ID');
            $wooId = ctype_digit((string) $wooIdRaw) ? (int) $wooIdRaw : 0;
            $type = strtolower((string) ($cell($row, $headerMap, 'Type') ?? ''));
            $sku = $cell($row, $headerMap, 'SKU');
            $name = $cell($row, $headerMap, 'Name');

            if ($wooId <= 0 || $name === null) { $invalid++; $errors[] = "Row {$rowNum}: invalid or missing ID/Name"; continue; }

            if ($sku === null) {
                if ($type === 'variation') {
                    $variationRows[] = $entry;  // defer
                    $total--;  // don't double-count deferred variations in phase 2
                } else {
                    $reconciliation++;
                }
                continue;
            }

            // SKU-bearing row.
            $values = $this->parseValues($row, $headerMap, $wooId, $type, $sku, $name);
            if ($values === null) { $invalid++; $errors[] = "Row {$rowNum}: invalid numeric value"; continue; }

            $refType = ($type === 'variation') ? 'variation' : 'product';
            $values['_ref_type'] = $refType;
            $values['_connection_key'] = $connectionKey;
            $values['_remote_woocommerce_id'] = $wooId;
            $values['_write_legacy_woo_id'] = $usesLegacyWooId;
            if (! $usesLegacyWooId) {
                $values['woocommerce_product_id'] = null;
            }

            $candidates++;
            if ($values['is_active']) $active++; else $inactive++;
            if (isset($seenSkus[$sku])) { $duplicateSku++; $errors[] = "Row {$rowNum}: duplicate SKU conflict: {$sku}"; continue; }
            if (isset($seenWooIds[$wooId])) { $identity++; $errors[] = "Row {$rowNum}: duplicate Woo Product ID conflict: {$wooId}"; continue; }
            $seenSkus[$sku] = true;
            $seenWooIds[$wooId] = true;

            $existingByWoo = $usesLegacyWooId ? $this->products->findByWooId($wooId) : null;
            $existingBySku = $this->products->findBySku($sku);
            $extProduct = $this->externalRefs->findProduct('woocommerce', $connectionKey, (string) $wooId);

            if ($extProduct !== null && $extProduct->getAttribute('sku') !== $sku) {
                $identity++; $errors[] = "Row {$rowNum}: external reference {$wooId} already mapped to SKU '{$extProduct->getAttribute('sku')}'"; continue;
            }

            if ($extProduct === null && $usesLegacyWooId) {
                if (($existingByWoo !== null && $existingByWoo->getAttribute('sku') !== $sku) || ($existingBySku !== null && $existingBySku->getAttribute('woocommerce_product_id') !== null && (int) $existingBySku->getAttribute('woocommerce_product_id') !== $wooId)) {
                    $identity++; $errors[] = "Row {$rowNum}: Woo-ID/SKU identity conflict for {$sku}"; continue;
                }
            }

            if ($existingByWoo === null && $existingBySku === null && $extProduct === null) {
                $skuMatchKey = SkuMatchKey::compute($sku);
                if ($skuMatchKey !== null && $this->skuAliases->existsMatchKey($skuMatchKey)) {
                    $identity++; $errors[] = "Row {$rowNum}: SKU '{$sku}' match key is already registered as a SKU alias"; continue;
                }
                $new++;
            } elseif ($this->sameBootstrapValues($existingByWoo ?? $existingBySku ?? $extProduct, $values)) $unchanged++;
            else $updates++;

            $candidateRows[] = $values;
        }

        // Phase 2: process blank-SKU variation rows, resolving parent via DB or current batch.
        foreach ($variationRows as $entry) {
            ['row' => $row, 'rowNum' => $rowNum] = $entry;
            $total++;
            $wooIdRaw = $cell($row, $headerMap, 'ID');
            $wooId = ctype_digit((string) $wooIdRaw) ? (int) $wooIdRaw : 0;
            $name = $cell($row, $headerMap, 'Name');
            if ($wooId <= 0 || $name === null) { $invalid++; $errors[] = "Row {$rowNum}: invalid or missing ID/Name"; continue; }

            $parentRaw = $cell($row, $headerMap, 'Parent');
            $parentProduct = $this->resolveParent($parentRaw, $connectionKey);
            $inBatch = $parentProduct === null && $parentRaw !== null && $this->parentIsInBatch($parentRaw, $batchSkuToWooId, $batchWooIdToSku);

            if ($parentProduct !== null) {
                // Parent already in DB — store product_id directly.
                $candidateRows[] = [
                    '_external_ref_only' => true,
                    '_parent_product_id' => $parentProduct->getKey(),
                    '_variation_woo_id' => $wooId,
                    '_connection_key' => $connectionKey,
                ];
                $skippedVariations++;
                $resolvedVariations++;
            } elseif ($inBatch) {
                // Parent is in the same CSV batch but not yet in DB; confirm() will re-resolve.
                $candidateRows[] = [
                    '_external_ref_only' => true,
                    '_parent_product_id' => null,
                    '_parent_raw' => $parentRaw,
                    '_variation_woo_id' => $wooId,
                    '_connection_key' => $connectionKey,
                ];
                $skippedVariations++;
                $resolvedVariations++;
            } else {
                $skippedVariations++;
                $errors[] = "Row {$rowNum}: blank-SKU variation {$wooId} has unresolved Parent '{$parentRaw}' — skipped";
            }
        }

        return ['result' => new ProductImportResult($total, $candidates, $new, $updates, $unchanged, $active, $inactive, $skippedVariations, $reconciliation, $invalid, $duplicateSku, $identity, $errors, $resolvedVariations), 'candidates' => $candidateRows];
    }

    /**
     * Resolve Parent cell to a canonical ERP Product via exact SKU, SKU Alias, or id:<WooProductID>.
     * $batchSkuToWooId and $batchWooIdToSku allow resolving parents that appear in the same CSV batch
     * but are not yet persisted to the DB.
     *
     * @param array<string,int> $batchSkuToWooId
     * @param array<int,string> $batchWooIdToSku
     */
    private function resolveParent(?string $parentRaw, string $connectionKey, array $batchSkuToWooId = [], array $batchWooIdToSku = []): ?\Illuminate\Database\Eloquent\Model
    {
        if ($parentRaw === null || $parentRaw === '') return null;

        if (str_starts_with($parentRaw, 'id:')) {
            $wooId = (int) substr($parentRaw, 3);
            if ($wooId <= 0) return null;
            // Try external reference first (proven Woo product reference).
            $product = $this->externalRefs->findProduct('woocommerce', $connectionKey, (string) $wooId);
            if ($product !== null) return $product;
            // Legacy woocommerce_product_id belongs only to the configured primary Woo connection.
            if ($this->usesLegacyWooId($connectionKey)) {
                $product = $this->products->findByWooId($wooId);
                if ($product !== null) return $product;
            }
            // In-batch: if this wooId appears as a parent candidate in the same CSV.
            if (isset($batchWooIdToSku[$wooId])) {
                return $this->products->findBySku($batchWooIdToSku[$wooId]);
            }
            return null;
        }

        // Exact canonical SKU (DB first, then batch).
        $product = $this->products->findBySku($parentRaw);
        if ($product !== null) return $product;

        // SKU Alias.
        $matchKey = SkuMatchKey::compute($parentRaw);
        if ($matchKey !== null) {
            $product = $this->products->findBySkuMatchKey($matchKey);
            if ($product !== null) return $product;
        }

        return null;
    }

    /**
     * Return true when $parentRaw refers to a non-variation, SKU-bearing row present in the
     * current CSV batch (exact SKU match or exact id:<wooId> match).
     * No fuzzy/name/attribute inference allowed.
     *
     * @param array<string,int> $batchSkuToWooId
     * @param array<int,string> $batchWooIdToSku
     */
    private function parentIsInBatch(string $parentRaw, array $batchSkuToWooId, array $batchWooIdToSku): bool
    {
        if (str_starts_with($parentRaw, 'id:')) {
            $wooId = (int) substr($parentRaw, 3);
            return $wooId > 0 && isset($batchWooIdToSku[$wooId]);
        }
        return isset($batchSkuToWooId[$parentRaw]);
    }

    /** @param list<array<string, mixed>> $candidates */
    public function confirm(array $candidates): ProductImportResult
    {
        $inserted = $updated = $unchanged = 0;
        DB::transaction(function () use ($candidates, &$inserted, &$updated, &$unchanged): void {
            // Phase 1: create/update canonical Products and write their external references.
            // External-ref-only rows are skipped here and handled in phase 2.
            foreach ($candidates as $values) {
                if ($values['_external_ref_only'] ?? false) continue;

                $sku = (string) $values['sku'];
                $connectionKey = (string) ($values['_connection_key'] ?? config('services.woocommerce.connection_key', 'beyourlover.com'));
                $writeLegacyWooId = (bool) ($values['_write_legacy_woo_id'] ?? $this->usesLegacyWooId($connectionKey));
                $wooId = (int) ($values['_remote_woocommerce_id'] ?? $values['woocommerce_product_id'] ?? 0);
                if ($wooId <= 0) {
                    throw new \RuntimeException("Confirm blocked: missing Woo remote ID for {$sku}");
                }
                $refType = (string) ($values['_ref_type'] ?? 'product');

                $byWoo = $writeLegacyWooId ? $this->products->findByWooId($wooId) : null;
                $bySku = $this->products->findBySku($sku);
                $byExtRef = $this->externalRefs->findProduct('woocommerce', $connectionKey, (string) $wooId);

                // Identity guard: the legacy global column is authoritative only on the configured primary Woo connection.
                if ($writeLegacyWooId && (($byWoo !== null && $byWoo->getAttribute('sku') !== $sku) || ($bySku !== null && $bySku->getAttribute('woocommerce_product_id') !== null && (int) $bySku->getAttribute('woocommerce_product_id') !== $wooId))) {
                    if ($byExtRef === null || $byExtRef->getAttribute('sku') !== $sku) {
                        throw new \RuntimeException("Confirm blocked by identity conflict for {$sku}");
                    }
                }

                $product = $byExtRef ?? $byWoo ?? $bySku;

                if ($product === null) {
                    $skuMatchKey = SkuMatchKey::compute($sku);
                    if ($skuMatchKey !== null && $this->skuAliases->existsMatchKey($skuMatchKey)) {
                        throw new \RuntimeException("Confirm blocked: SKU '{$sku}' match key is already registered as a SKU alias");
                    }
                    $cleanValues = $this->stripInternalKeys($values);
                    $product = $this->products->create($cleanValues);
                    $inserted++;
                } else {
                    $attributes = $this->bootstrapAttributes($values);
                    if ($product->getAttribute('weight_kg') === null && $values['weight_kg'] !== null) $attributes['weight_kg'] = $values['weight_kg'];
                    if ($this->sameBootstrapValues($product, $values)) $unchanged++;
                    else { $this->products->update($product, $attributes); $updated++; }
                }

                $this->externalRefs->upsert((int) $product->getKey(), 'woocommerce', $connectionKey, (string) $wooId, $refType);
            }

            // Phase 2: write external references for blank-SKU variation rows.
            // Parents created in phase 1 are now available in the DB.
            foreach ($candidates as $values) {
                if (! ($values['_external_ref_only'] ?? false)) continue;

                $variationWooId = (string) $values['_variation_woo_id'];
                $connectionKey = (string) $values['_connection_key'];

                // If parent_product_id was resolved at preview time, use it directly.
                // If null, the parent was in the same batch — re-resolve now that phase 1 created it.
                if ($values['_parent_product_id'] !== null) {
                    $parentId = (int) $values['_parent_product_id'];
                } else {
                    $parentRaw = (string) ($values['_parent_raw'] ?? '');
                    $parent = $this->resolveParent($parentRaw, $connectionKey);
                    if ($parent === null) {
                        throw new \RuntimeException(
                            "Confirm blocked: blank-SKU variation {$variationWooId} parent '{$parentRaw}' could not be resolved after phase-1 creation"
                        );
                    }
                    $parentId = (int) $parent->getKey();
                }

                $this->externalRefs->upsert($parentId, 'woocommerce', $connectionKey, $variationWooId, 'variation');
            }
        });
        $skuCandidates = array_filter($candidates, static fn (array $row): bool => ! ($row['_external_ref_only'] ?? false));
        $processed = $inserted + $updated + $unchanged;
        $active = count(array_filter($skuCandidates, static fn (array $row): bool => ($row['is_active'] ?? false) === true));
        $inactive = $processed - $active;

        return new ProductImportResult($processed, $processed, $inserted, $updated, $unchanged, $active, $inactive);
    }

    private function stripInternalKeys(array $values): array
    {
        return array_filter($values, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    public function import(string $csvPath): ProductImportResult
    {
        $preview = $this->preview($csvPath);
        if (! $preview['result']->toArray()['canConfirm']) return $preview['result'];
        return $this->confirm($preview['candidates']);
    }

    private function parseValues(array $row, array $map, int $wooId, string $type, string $sku, string $name): ?array
    {
        $cell = static fn (string $column): ?string => isset($map[$column]) ? (($value = trim((string) ($row[$map[$column]] ?? ''))) === '' ? null : $value) : null;
        $number = static function (?string $value): ?float { if ($value === null) return null; return is_numeric($value) && (float) $value >= 0 ? (float) $value : null; };
        $rawWeight = $cell('Weight (kg)'); $rawSale = $cell('Sale price'); $rawRegular = $cell('Regular price');
        $weight = $number($rawWeight); $sale = $number($rawSale); $regular = $number($rawRegular);
        foreach ([[$rawWeight, $weight], [$rawSale, $sale], [$rawRegular, $regular]] as [$raw, $parsed]) if ($raw !== null && $parsed === null) return null;
        $published = $cell('Published');
        return ['woocommerce_product_id' => $wooId, 'source_type' => 'woocommerce', 'product_type' => $type ?: 'simple', 'sku' => $sku, 'gtin' => $cell('GTIN, UPC, EAN, or ISBN'), 'name' => $name, 'sale_price' => $sale, 'regular_price' => $regular, 'is_active' => $published === null ? true : $published === '1', 'weight_kg' => $weight];
    }

    private function bootstrapAttributes(array $values): array
    {
        $attributes = ['source_type' => 'woocommerce', 'product_type' => $values['product_type'], 'sku' => $values['sku'], 'gtin' => $values['gtin'], 'name' => $values['name'], 'sale_price' => $values['sale_price'], 'regular_price' => $values['regular_price'], 'is_active' => $values['is_active']];
        if (($values['_write_legacy_woo_id'] ?? true) === true) {
            $attributes['woocommerce_product_id'] = $values['woocommerce_product_id'];
        }
        return $attributes;
    }

    private function usesLegacyWooId(string $connectionKey): bool
    {
        $primaryConnectionKey = (string) config('services.woocommerce.connection_key', 'beyourlover.com');
        return $connectionKey !== '' && $connectionKey === $primaryConnectionKey;
    }

    private function sameBootstrapValues(Model $product, array $values): bool
    {
        foreach ($this->bootstrapAttributes($values) as $key => $value) if ($product->getAttribute($key) != $value) return false;
        return $values['weight_kg'] === null || $product->getAttribute('weight_kg') !== null || $product->getAttribute('weight_kg') == $values['weight_kg'];
    }

    private function rowIsEmpty(array $row): bool { return count(array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) === 0; }

    public function searchSkus(string $query, int $limit = 20): array
    {
        return $this->products->searchSkus($query, $limit);
    }

    public function findById(int $id): ?Model
    {
        return $this->products->findById($id);
    }

    public function findBySku(string $sku): ?object
    {
        return $this->products->findBySku($sku);
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->products->list($filters, $perPage);
    }

    public function totalCount(): int
    {
        return $this->products->totalCount();
    }

    /**
     * Throw if the given raw SKU's match key is already registered as an Alias match key.
     * This prevents a new canonical SKU from silently taking over an existing Alias's identity.
     * Pass $excludeProductId when updating so the product's own alias list is excluded.
     */
    private function assertNoAliasMatchKeyCollision(string $rawSku, ?int $excludeProductId = null): void
    {
        $matchKey = SkuMatchKey::compute($rawSku);
        if ($matchKey === null) {
            return;
        }
        if ($this->skuAliases->existsMatchKey($matchKey)) {
            throw new \RuntimeException("SKU '{$rawSku}' match key '{$matchKey}' is already registered as a SKU alias. Delete the alias first.");
        }
    }

    public function createManual(array $attributes): Model
    {
        $nullable = fn (string $k) => isset($attributes[$k]) && $attributes[$k] !== '' ? $attributes[$k] : null;
        $nullableFloat = fn (string $k) => isset($attributes[$k]) && $attributes[$k] !== '' ? (float) $attributes[$k] : null;

        $sku = $attributes['sku'] ?? null;
        $labelSku = isset($attributes['label_sku']) && trim((string) $attributes['label_sku']) !== ''
            ? trim((string) $attributes['label_sku'])
            : null;
        if ($sku !== null && $sku !== '') {
            $this->assertNoAliasMatchKeyCollision((string) $sku);
        }
        $this->assertLabelIdentityAvailable($sku !== null ? (string) $sku : null, $labelSku, null);

        $weightKg = $nullableFloat('weight_kg');
        $shippingWeightKg = $nullableFloat('shipping_weight_kg');

        $product = $this->products->create([
            'source_type' => 'manual',
            'woocommerce_product_id' => null,
            'product_type' => $attributes['product_type'] ?? 'simple',
            'sku' => $attributes['sku'] ?? null,
            'label_sku' => $labelSku,
            'label_description' => $nullable('label_description'),
            'gtin' => $nullable('gtin'),
            'name' => $attributes['name'],
            'unit' => $nullable('unit'),
            'is_active' => isset($attributes['is_active']) ? (bool) $attributes['is_active'] : true,
            'weight_kg' => null,
            'sale_price' => $nullableFloat('sale_price'),
            'regular_price' => $nullableFloat('regular_price'),
            'shipping_weight_kg' => null,
            'length_cm' => $nullableFloat('length_cm'),
            'width_cm' => $nullableFloat('width_cm'),
            'height_cm' => $nullableFloat('height_cm'),
            'country_of_origin' => $nullable('country_of_origin'),
            'hs_code' => $nullable('hs_code'),
            'standard_production_cost' => $nullableFloat('standard_production_cost'),
            'standard_packaging_cost' => $nullableFloat('standard_packaging_cost'),
            'standard_purchase_cost' => $nullableFloat('standard_purchase_cost'),
            'cost_currency' => $nullable('cost_currency'),
            'cost_note' => $nullable('cost_note'),
            'notes' => $nullable('notes'),
        ]);

        $actorUserId = Auth::id();

        if ($weightKg !== null && $weightKg > 0) {
            $this->weightEvidence->createAndAdoptManualEntry($product, 'product_weight', $weightKg, $actorUserId);
            $product->refresh();
        }

        if ($shippingWeightKg !== null && $shippingWeightKg > 0) {
            $this->weightEvidence->createAndAdoptManualEntry($product, 'shipping_reference_weight', $shippingWeightKg, $actorUserId);
            $product->refresh();
        }

        return $product;
    }

    public function updateProduct(Model $product, array $attributes): void
    {
        $pick = fn (string $k, mixed $default) => array_key_exists($k, $attributes) ? ($attributes[$k] !== '' && $attributes[$k] !== null ? $attributes[$k] : null) : $default;
        $pickFloat = fn (string $k, mixed $default) => array_key_exists($k, $attributes) ? ($attributes[$k] !== '' && $attributes[$k] !== null ? (float) $attributes[$k] : null) : $default;

        $newSku = array_key_exists('sku', $attributes) ? ($attributes['sku'] ?: null) : $product->getAttribute('sku');
        $newLabelSku = array_key_exists('label_sku', $attributes)
            ? (trim((string) ($attributes['label_sku'] ?? '')) !== '' ? trim((string) $attributes['label_sku']) : null)
            : $product->getAttribute('label_sku');
        if ($newSku !== null && $newSku !== $product->getAttribute('sku')) {
            $this->assertNoAliasMatchKeyCollision((string) $newSku);
        }
        $this->assertLabelIdentityAvailable(
            $newSku !== null ? (string) $newSku : null,
            $newLabelSku !== null ? (string) $newLabelSku : null,
            (int) $product->getKey(),
        );

        // Determine new weight values; weight changes require provenance creation.
        $newWeightKg = array_key_exists('weight_kg', $attributes)
            ? ($attributes['weight_kg'] !== '' && $attributes['weight_kg'] !== null ? (float) $attributes['weight_kg'] : null)
            : null;
        $newShippingWeightKg = array_key_exists('shipping_weight_kg', $attributes)
            ? ($attributes['shipping_weight_kg'] !== '' && $attributes['shipping_weight_kg'] !== null ? (float) $attributes['shipping_weight_kg'] : null)
            : null;

        $currentWeightKg = $product->getAttribute('weight_kg') !== null ? (float) $product->getAttribute('weight_kg') : null;
        $currentShippingWeightKg = $product->getAttribute('shipping_weight_kg') !== null ? (float) $product->getAttribute('shipping_weight_kg') : null;

        $weightKgChanged = array_key_exists('weight_kg', $attributes) && $newWeightKg !== $currentWeightKg;
        $shippingWeightKgChanged = array_key_exists('shipping_weight_kg', $attributes) && $newShippingWeightKg !== $currentShippingWeightKg;

        $this->products->update($product, [
            'product_type' => $attributes['product_type'] ?? $product->getAttribute('product_type'),
            'sku' => array_key_exists('sku', $attributes) ? ($attributes['sku'] ?: null) : $product->getAttribute('sku'),
            'label_sku' => $newLabelSku,
            'label_description' => $pick('label_description', $product->getAttribute('label_description')),
            'gtin' => $pick('gtin', $product->getAttribute('gtin')),
            'name' => $attributes['name'] ?? $product->getAttribute('name'),
            'unit' => $pick('unit', $product->getAttribute('unit')),
            'is_active' => array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : $product->getAttribute('is_active'),
            // Weight fields written only by evidence adopt/clear below; generic update always keeps current DB value.
            'weight_kg' => $currentWeightKg,
            'sale_price' => $pickFloat('sale_price', $product->getAttribute('sale_price')),
            'regular_price' => $pickFloat('regular_price', $product->getAttribute('regular_price')),
            'shipping_weight_kg' => $currentShippingWeightKg,
            'length_cm' => $pickFloat('length_cm', $product->getAttribute('length_cm')),
            'width_cm' => $pickFloat('width_cm', $product->getAttribute('width_cm')),
            'height_cm' => $pickFloat('height_cm', $product->getAttribute('height_cm')),
            'country_of_origin' => $pick('country_of_origin', $product->getAttribute('country_of_origin')),
            'hs_code' => $pick('hs_code', $product->getAttribute('hs_code')),
            'standard_production_cost' => $pickFloat('standard_production_cost', $product->getAttribute('standard_production_cost')),
            'standard_packaging_cost' => $pickFloat('standard_packaging_cost', $product->getAttribute('standard_packaging_cost')),
            'standard_purchase_cost' => $pickFloat('standard_purchase_cost', $product->getAttribute('standard_purchase_cost')),
            'cost_currency' => $pick('cost_currency', $product->getAttribute('cost_currency')),
            'cost_note' => $pick('cost_note', $product->getAttribute('cost_note')),
            'notes' => $pick('notes', $product->getAttribute('notes')),
        ]);

        $product->refresh();
        $actorUserId = Auth::id();

        if ($weightKgChanged && $newWeightKg !== null && $newWeightKg > 0) {
            $this->weightEvidence->createAndAdoptManualEntry($product, 'product_weight', $newWeightKg, $actorUserId, confirmed: true);
        } elseif ($weightKgChanged && $newWeightKg === null) {
            $this->weightEvidence->clearWeight($product, 'product_weight', $actorUserId);
        }

        if ($shippingWeightKgChanged && $newShippingWeightKg !== null && $newShippingWeightKg > 0) {
            $this->weightEvidence->createAndAdoptManualEntry($product, 'shipping_reference_weight', $newShippingWeightKg, $actorUserId, confirmed: true);
        } elseif ($shippingWeightKgChanged && $newShippingWeightKg === null) {
            $this->weightEvidence->clearWeight($product, 'shipping_reference_weight', $actorUserId);
        }
    }

    private function assertLabelIdentityAvailable(?string $sku, ?string $labelSku, ?int $currentProductId): void
    {
        if ($sku !== null && trim($sku) !== '') {
            $labelOwner = $this->products->findByLabelSku(trim($sku));
            if ($labelOwner !== null && (int) $labelOwner->getKey() !== $currentProductId) {
                throw new \RuntimeException('SKU 已被其他产品作为蓝标 SKU 使用。');
            }
        }

        if ($labelSku !== null && trim($labelSku) !== '') {
            $canonicalOwner = $this->products->findBySku(trim($labelSku));
            if ($canonicalOwner !== null && (int) $canonicalOwner->getKey() !== $currentProductId) {
                throw new \RuntimeException('蓝标 SKU 已被其他产品的 SKU 使用。');
            }

            $labelOwner = $this->products->findByLabelSku(trim($labelSku));
            if ($labelOwner !== null && (int) $labelOwner->getKey() !== $currentProductId) {
                throw new \RuntimeException('蓝标 SKU 已被其他产品使用。');
            }
        }
    }

    public function deleteProduct(Model $product): void
    {
        $this->products->delete($product);
    }

    public function importFromUpload(string $tmpPath, ?string $connectionKey = null): array
    {
        return $this->preview($tmpPath, $connectionKey);
    }
}
