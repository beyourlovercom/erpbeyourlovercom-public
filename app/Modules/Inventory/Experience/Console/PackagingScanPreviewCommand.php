<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Inventory\Application\PackagingScan\PackagingScanEvidencePreviewService;
use App\Modules\Inventory\Application\PackagingScan\PackagingScanXlsxReader;
use Illuminate\Console\Command;

final class PackagingScanPreviewCommand extends Command
{
    protected $signature = 'inventory:packaging-scan-preview {file : XLSX file path} {--json : Emit JSON output}';
    protected $description = 'Read-only preview of logistics scan rows as packaging weight/dimension evidence candidates';

    public function handle(PackagingScanXlsxReader $reader, PackagingScanEvidencePreviewService $preview): int
    {
        $path = (string) $this->argument('file');
        try { $result = $preview->preview($reader->parsePath($path)); }
        catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        if ($this->option('json')) { $this->line(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); return self::SUCCESS; }

        $s = $result['summary'];
        $this->info("Rows={$s['rows']} Matched={$s['matched_orders']} WeightReady={$s['ready_weight_evidence']} DimReady={$s['ready_dimension_evidence']} Both={$s['ready_weight_and_dimension_evidence']} WeightOnly={$s['ready_weight_only_evidence']} PlaceholderDims={$s['placeholder_dimensions']} MultiUnit={$s['multi_unit']} MultiSku={$s['multi_sku']} Unmatched={$s['unmatched_orders']} Ambiguous={$s['ambiguous_orders']}");
        $this->table(['Row','Order','SKU','Qty','Inbound kg','L×W×H cm','Class','Weight Evidence','Dimension Evidence'], array_map(function (array $row): array {
            $dims = implode('×', array_map(fn ($v) => $v === null ? '—' : (string)$v, [$row['length_cm'],$row['width_cm'],$row['height_cm']]));
            return [$row['source_row_number'],$row['order_reference'] ?? '—',$row['product']['sku'] ?? '—',$row['product']['quantity'] ?? '—',$row['inbound_weight_kg'] ?? '—',$dims,$row['classification'],$row['weight_evidence_status'],$row['dimension_evidence_status']];
        }, $result['rows']));

        if (($result['weight_candidates'] ?? []) !== []) {
            $this->newLine();
            $this->info('Shipping Weight Standard Candidates (preview only)');
            $this->table(['SKU','Suggested kg','Eligible/Raw','Outliers','Range kg','Spread','Status'], array_map(fn (array $row): array => [
                $row['sku'],
                $row['suggested_shipping_weight_kg'],
                $row['eligible_sample_count'].'/'.$row['raw_sample_count'],
                $row['gross_outlier_count'],
                $row['eligible_min_kg'].'–'.$row['eligible_max_kg'],
                number_format((float) $row['eligible_spread_ratio'] * 100, 1).'%',
                $row['status'],
            ], $result['weight_candidates']));
        }
        return self::SUCCESS;
    }
}
