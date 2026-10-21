<?php

namespace App\Modules\Products\Experience\Console;

use App\Modules\Products\Application\ProductService;
use Illuminate\Console\Command;

final class ImportWooCsvCommand extends Command
{
    protected $signature = 'products:import-woo-csv {path : Absolute path to the Woo export CSV}';
    protected $description = 'Preview and explicitly confirm a WooCommerce Product Master CSV import';

    public function handle(ProductService $service): int
    {
        $path = (string) $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $this->info("Previewing: {$path}");
        $preview = $service->preview($path);
        $result = $preview['result'];
        $summary = $result->toArray();

        $this->table(
            ['Rows', 'Candidates', 'New', 'Updates', 'Unchanged', 'Active', 'Inactive', 'No-SKU variations', 'Reconcile', 'Invalid', 'SKU conflicts', 'Identity conflicts'],
            [[
                $summary['totalRows'], $summary['candidates'], $summary['newCandidates'], $summary['updates'], $summary['unchanged'],
                $summary['active'], $summary['inactive'], $summary['skippedNoSkuVariations'], $summary['noSkuNonVariationReconciliation'],
                $summary['invalid'], $summary['duplicateSkuConflicts'], $summary['identityConflicts'],
            ]],
        );

        foreach ($result->errors as $error) {
            $this->warn($error);
        }

        if ($summary['canConfirm'] !== true) {
            $this->error('Confirm blocked by Product identity conflicts. No Product rows were mutated.');
            return self::FAILURE;
        }

        if ($summary['candidates'] === 0) {
            $this->info('No canonical Product candidates. No Product rows were mutated.');
            return self::SUCCESS;
        }

        if (! $this->confirm("Confirm import of {$summary['candidates']} canonical Product candidates?", false)) {
            $this->info('Import cancelled. No Product rows were mutated.');
            return self::SUCCESS;
        }

        $confirmed = $service->confirm($preview['candidates']);
        $this->table(
            ['Inserted', 'Updated', 'Unchanged'],
            [[$confirmed->inserted, $confirmed->updated, $confirmed->skipped]],
        );
        $this->info('Product import confirmed.');

        return self::SUCCESS;
    }
}
