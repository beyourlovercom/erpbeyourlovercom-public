<?php

namespace App\Modules\Products\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Application\Contracts\ConfirmedProductCostSourceRepository;
use App\Modules\Products\Application\Contracts\ProductRepository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ConfirmedProductCostSourceImportService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ConfirmedProductCostSourceRepository $sources,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param list<array<string,string|null>> $rows */
    public function plan(array $rows, string $sourceReference, string $effectiveFrom, ?string $sourceAt = null): array
    {
        $sourceReference = trim($sourceReference);
        if ($sourceReference === '') {
            throw new InvalidArgumentException('source_reference_required');
        }
        if (! $this->validDate($effectiveFrom)) {
            throw new InvalidArgumentException('effective_from_must_be_yyyy_mm_dd');
        }
        if ($sourceAt !== null && trim($sourceAt) !== '' && strtotime($sourceAt) === false) {
            throw new InvalidArgumentException('source_at_invalid');
        }

        $planned = [];
        foreach ($rows as $index => $row) {
            $planned[] = $this->planRow($row, $index + 2, $sourceReference, $effectiveFrom, $sourceAt);
        }

        $groups = [];
        foreach ($planned as $i => $row) {
            if (($row['status'] ?? null) === 'blocked' || ! isset($row['product_id'])) {
                continue;
            }
            $key = $row['product_id'].'|'.$effectiveFrom;
            $groups[$key][] = $i;
        }
        foreach ($groups as $indexes) {
            $distinct = [];
            foreach ($indexes as $i) {
                $distinct[$planned[$i]['currency'].'|'.$planned[$i]['unit_cost']] = true;
            }
            if (count($distinct) > 1) {
                foreach ($indexes as $i) {
                    $planned[$i]['status'] = 'blocked';
                    $planned[$i]['blocker'] = 'multiple_confirmed_costs_for_product_period';
                }
            }
        }

        $counts = ['create' => 0, 'noop' => 0, 'blocked' => 0];
        foreach ($planned as $row) {
            $status = (string) ($row['status'] ?? 'blocked');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'mode' => 'dry_run',
            'schema_ready' => $this->sources->schemaReady(),
            'source_reference' => $sourceReference,
            'effective_from' => $effectiveFrom,
            'source_at' => $sourceAt,
            'seen' => count($planned),
            ...$counts,
            'rows' => $planned,
        ];
    }

    public function apply(array $plan): array
    {
        if (! $this->sources->schemaReady()) {
            throw new RuntimeException('product_cost_sources_schema_missing');
        }
        if (($plan['blocked'] ?? 0) > 0) {
            throw new RuntimeException('blocked_rows_must_be_resolved_before_apply');
        }

        $created = 0;
        DB::transaction(function () use ($plan, &$created): void {
            foreach ($plan['rows'] as $row) {
                if (($row['status'] ?? null) !== 'create') {
                    continue;
                }
                $source = $this->sources->create([
                    'product_id' => $row['product_id'],
                    'source_key' => $row['source_key'],
                    'unit_cost' => $row['unit_cost'],
                    'currency' => $row['currency'],
                    'provenance' => 'confirmed_source_file',
                    'status' => 'confirmed',
                    'source_type' => 'inventory_cost_book',
                    'source_reference' => $plan['source_reference'],
                    'effective_from' => $plan['effective_from'],
                    'effective_to' => null,
                    'source_at' => $plan['source_at'],
                    'confirmed_at' => now(),
                    'metadata' => ['erp_sku' => $row['erp_sku'], 'csv_row' => $row['csv_row']],
                    'source_fingerprint' => $row['source_fingerprint'],
                ]);
                $created++;
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'product.cost_source.confirmed',
                    'product_cost_source',
                    $source->getKey(),
                    after: [
                        'product_id' => $row['product_id'],
                        'erp_sku' => $row['erp_sku'],
                        'source_key' => $row['source_key'],
                        'unit_cost' => $row['unit_cost'],
                        'currency' => $row['currency'],
                        'source_reference' => $plan['source_reference'],
                        'effective_from' => $plan['effective_from'],
                    ],
                    reason: 'confirmed_product_cost_import',
                );
            }
        });

        return ['mode' => 'apply', 'created' => $created, 'noop' => (int) ($plan['noop'] ?? 0), 'blocked' => 0];
    }

    private function planRow(array $row, int $csvRow, string $sourceReference, string $effectiveFrom, ?string $sourceAt): array
    {
        $id = trim((string) ($row['erp_product_id'] ?? ''));
        $sku = trim((string) ($row['erp_sku'] ?? ''));
        $sourceKey = trim((string) ($row['source_variant_key'] ?? ''));
        $rawCost = trim((string) ($row['unit_cost'] ?? ''));
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        $base = ['csv_row' => $csvRow, 'erp_product_id' => $id, 'erp_sku' => $sku, 'source_key' => $sourceKey, 'raw_unit_cost' => $rawCost, 'currency' => $currency];

        if ($id === '' || ! ctype_digit($id) || $sku === '' || $sourceKey === '' || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            return $base + ['status' => 'blocked', 'blocker' => 'required_identity_or_currency_invalid'];
        }
        if (! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $rawCost) || bccomp($rawCost, '0', 12) < 0) {
            return $base + ['status' => 'blocked', 'blocker' => 'unit_cost_invalid'];
        }

        $product = $this->products->findById((int) $id);
        if ($product === null) {
            return $base + ['status' => 'blocked', 'blocker' => 'erp_product_not_found'];
        }
        if (strcasecmp(trim((string) $product->getAttribute('sku')), $sku) !== 0) {
            return $base + ['status' => 'blocked', 'blocker' => 'erp_sku_mismatch', 'actual_erp_sku' => $product->getAttribute('sku')];
        }

        $unitCost = bcadd($rawCost, '0', 12);
        $periodSources = $this->sources->findConfirmedForProductPeriod((int) $product->getKey(), $effectiveFrom);
        foreach ($periodSources as $periodSource) {
            if (bccomp((string) $periodSource->getAttribute('unit_cost'), $unitCost, 12) !== 0
                || strtoupper((string) $periodSource->getAttribute('currency')) !== $currency) {
                return $base + [
                    'product_id' => (int) $product->getKey(), 'unit_cost' => $unitCost,
                    'status' => 'blocked', 'blocker' => 'existing_period_cost_conflict',
                    'existing_source_id' => (int) $periodSource->getKey(),
                ];
            }
        }

        $fingerprint = hash('sha256', implode('|', [(string) $product->getKey(), $sourceReference, $sourceKey, $effectiveFrom]));
        $existing = $this->sources->findByFingerprint($fingerprint);
        if ($existing !== null) {
            $same = bccomp((string) $existing->getAttribute('unit_cost'), $unitCost, 12) === 0
                && strtoupper((string) $existing->getAttribute('currency')) === $currency
                && (string) $existing->getAttribute('status') === 'confirmed';
            return $base + [
                'product_id' => (int) $product->getKey(), 'unit_cost' => $unitCost,
                'source_fingerprint' => $fingerprint, 'status' => $same ? 'noop' : 'blocked',
                'blocker' => $same ? null : 'existing_confirmed_source_conflict',
                'existing_source_id' => (int) $existing->getKey(),
            ];
        }

        return $base + [
            'product_id' => (int) $product->getKey(), 'unit_cost' => $unitCost,
            'source_fingerprint' => $fingerprint, 'status' => 'create',
            'source_reference' => $sourceReference, 'effective_from' => $effectiveFrom, 'source_at' => $sourceAt,
        ];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
