<?php

namespace App\Modules\Products\Experience\Console;

use App\Modules\Products\Application\ConfirmedProductCostSourceImportService;
use Illuminate\Console\Command;
use RuntimeException;

final class ImportConfirmedProductCostsCommand extends Command
{
    protected $signature = 'finance:import-confirmed-product-costs
        {--file= : Canonical CSV containing erp_product_id,erp_sku,source_variant_key,unit_cost,currency}
        {--effective-from= : YYYY-MM-DD applicability start date}
        {--source-reference= : Human-readable authoritative source reference; defaults to CSV basename}
        {--source-at= : Optional evidence timestamp/date}
        {--apply : Persist confirmed cost sources; Production requires explicit single-process gate}';

    protected $description = 'Dry-run or locally import owner-confirmed product unit-cost source evidence.';

    public function handle(ConfirmedProductCostSourceImportService $service): int
    {
        $file = trim((string) $this->option('file'));
        $effectiveFrom = trim((string) $this->option('effective-from'));
        if ($file === '' || $effectiveFrom === '') {
            $this->error('--file and --effective-from are required.');
            return self::FAILURE;
        }
        if (! is_file($file) || ! is_readable($file)) {
            $this->error('CSV file is not readable.');
            return self::FAILURE;
        }
        if ($this->option('apply') && ! $this->applyAllowed()) {
            $this->error('The --apply option requires local/testing or the explicit Production apply gate.');
            return self::FAILURE;
        }

        try {
            $rows = $this->readCsv($file);
            $sourceReference = trim((string) $this->option('source-reference')) ?: basename($file);
            $sourceAt = trim((string) $this->option('source-at')) ?: null;
            $plan = $service->plan($rows, $sourceReference, $effectiveFrom, $sourceAt);
            $summary = $plan;
            unset($summary['rows']);
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            foreach ($plan['rows'] as $row) {
                if (($row['status'] ?? null) === 'blocked') {
                    $this->line(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
            if (! $this->option('apply')) {
                return ($plan['blocked'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
            }
            $result = $service->apply($plan);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (RuntimeException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }


    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }
        if (! app()->environment('production')) {
            return false;
        }

        return filter_var(
            getenv('ERP_ACCOUNTING_CONFIRMED_PRODUCT_COST_PRODUCTION_APPLY_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOL,
        );
    }

    /** @return list<array<string,string|null>> */
    private function readCsv(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('csv_open_failed');
        }
        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new RuntimeException('csv_header_missing');
            }
            $header = array_map(static fn ($v): string => trim((string) $v), $header);
            foreach (['erp_product_id', 'erp_sku', 'source_variant_key', 'unit_cost', 'currency'] as $required) {
                if (! in_array($required, $header, true)) {
                    throw new RuntimeException('csv_required_column_missing:'.$required);
                }
            }
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($rows) >= 5000) {
                    throw new RuntimeException('csv_row_limit_exceeded');
                }
                if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                    continue;
                }
                $values = array_pad($values, count($header), null);
                $rows[] = array_combine($header, array_slice($values, 0, count($header)));
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
