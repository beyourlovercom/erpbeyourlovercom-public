<?php

namespace Tests\Feature;

use App\Modules\Inventory\Application\PackagingScan\PackagingScanEvidencePreviewService;
use App\Modules\Inventory\Application\PackagingScan\PackagingScanXlsxReader;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\ProductWeightEvidenceService;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

final class InventoryPackagingScanEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) @unlink($path);
        parent::tearDown();
    }

    public function test_reader_accepts_bilingual_logistics_scan_headers(): void
    {
        $path = $this->xlsx([
            ['客户参考单号 Customer Reference Number','运单号 Tracking Number','预报重量 Forecast Weight','签入重量 Inbound Weight','体积重量 Volume Weight','计费重量 Billing Weight','重量差异 Weight Difference','长 length','宽 width','高 height'],
            ['ORDER-1','TRACK-1','1.300','1.360','0.001','1.360','0.060','20','10','8'],
        ]);
        $rows = app(PackagingScanXlsxReader::class)->parsePath($path);
        $this->assertCount(1, $rows);
        $this->assertSame('ORDER-1', $rows[0]['order_reference']);
        $this->assertSame('1.360', $rows[0]['inbound_weight_kg']);
        $this->assertSame('20', $rows[0]['length_cm']);
    }

    public function test_preview_separates_weight_and_dimension_evidence_for_single_unit_orders(): void
    {
        $p1 = $this->product('SKU-ONE');
        $p2 = $this->product('SKU-TWO');
        $single = $this->order('ORDER-1');
        $single->items()->create(['product_id'=>$p1->id,'product_summary'=>'One','sku'=>$p1->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
        $multiUnit = $this->order('ORDER-2');
        $multiUnit->items()->create(['product_id'=>$p1->id,'product_summary'=>'One','sku'=>$p1->sku,'quantity'=>2,'unit_price'=>10,'line_revenue'=>20]);
        $multiSku = $this->order('ORDER-3');
        $multiSku->items()->create(['product_id'=>$p1->id,'product_summary'=>'One','sku'=>$p1->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
        $multiSku->items()->create(['product_id'=>$p2->id,'product_summary'=>'Two','sku'=>$p2->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);

        $rows = [
            $this->row(2, 'ORDER-1', 1.36, 20, 10, 8),
            $this->row(3, 'ORDER-1', 1.37, 1, 1, 1),
            $this->row(4, 'ORDER-1', 1.38, null, null, null),
            $this->row(5, 'ORDER-1', null, 21, 11, 9),
            $this->row(6, 'ORDER-2', 2.70, 30, 20, 12),
            $this->row(7, 'ORDER-3', 1.80, 25, 18, 10),
            $this->row(8, 'UNKNOWN', 1.10, 20, 10, 8),
        ];
        $result = app(PackagingScanEvidencePreviewService::class)->preview($rows);

        $this->assertSame(7, $result['summary']['rows']);
        $this->assertSame(6, $result['summary']['matched_orders']);
        $this->assertSame(3, $result['summary']['ready_weight_evidence']);
        $this->assertSame(2, $result['summary']['ready_dimension_evidence']);
        $this->assertSame(1, $result['summary']['ready_weight_and_dimension_evidence']);
        $this->assertSame(2, $result['summary']['ready_weight_only_evidence']);
        $this->assertSame(1, $result['summary']['ready_dimension_only_evidence']);
        $this->assertSame(1, $result['summary']['placeholder_dimensions']);
        $this->assertSame(1, $result['summary']['missing_dimensions']);
        $this->assertSame(1, $result['summary']['missing_actual_weight']);
        $this->assertSame(1, $result['summary']['multi_unit']);
        $this->assertSame(1, $result['summary']['multi_sku']);
        $this->assertSame(1, $result['summary']['unmatched_orders']);

        $this->assertSame('ready_weight_and_dimension_evidence', $result['rows'][0]['evidence_status']);
        $this->assertTrue($result['rows'][0]['can_be_weight_evidence']);
        $this->assertTrue($result['rows'][0]['can_be_dimension_evidence']);
        $this->assertSame('shipping_reference_weight', $result['rows'][0]['weight_evidence_candidate']['weight_kind']);
        $this->assertSame('logistics_scan', $result['rows'][0]['weight_evidence_candidate']['source_type']);
        $this->assertSame(1600.0, $result['rows'][0]['dimension_evidence_candidate']['volume_cm3']);

        $this->assertSame('ready_weight_evidence_only', $result['rows'][1]['evidence_status']);
        $this->assertTrue($result['rows'][1]['can_be_weight_evidence']);
        $this->assertFalse($result['rows'][1]['can_be_dimension_evidence']);
        $this->assertSame('placeholder_dimensions', $result['rows'][1]['dimension_evidence_status']);

        $this->assertSame('ready_weight_evidence_only', $result['rows'][2]['evidence_status']);
        $this->assertSame('missing_dimensions', $result['rows'][2]['dimension_evidence_status']);
        $this->assertTrue($result['rows'][2]['can_be_product_evidence']);

        $this->assertSame('ready_dimension_evidence_only', $result['rows'][3]['evidence_status']);
        $this->assertFalse($result['rows'][3]['can_be_weight_evidence']);
        $this->assertTrue($result['rows'][3]['can_be_dimension_evidence']);
        $this->assertSame('missing_actual_weight', $result['rows'][3]['weight_evidence_status']);

        $this->assertSame('single_sku_multi_unit', $result['rows'][4]['classification']);
        $this->assertSame('multi_sku', $result['rows'][5]['classification']);
        $this->assertContains('logistics_scan', ProductWeightEvidenceService::SOURCE_TYPES);
        $this->assertDatabaseCount('packaging_measurement_records', 0);
        $this->assertDatabaseCount('product_weight_evidences', 0);
    }

    public function test_preview_aggregates_shipping_weight_candidates_and_flags_gross_outliers(): void
    {
        $stable = $this->product('SKU-STABLE');
        $review = $this->product('SKU-REVIEW');
        $rows = [];
        foreach ([0.750, 0.752, 0.748, 0.754, 0.756, 0.744, 0.562] as $index => $weight) {
            $order = $this->order('STABLE-'.$index);
            $order->items()->create(['product_id'=>$stable->id,'product_summary'=>'Stable','sku'=>$stable->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
            $rows[] = $this->row(20 + $index, 'STABLE-'.$index, $weight, 1, 1, 1);
        }
        foreach ([0.290, 0.292, 0.300] as $index => $weight) {
            $order = $this->order('REVIEW-'.$index);
            $order->items()->create(['product_id'=>$review->id,'product_summary'=>'Review','sku'=>$review->sku,'quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
            $rows[] = $this->row(40 + $index, 'REVIEW-'.$index, $weight, null, null, null);
        }

        $result = app(PackagingScanEvidencePreviewService::class)->preview($rows);
        $stableCandidate = collect($result['weight_candidates'])->firstWhere('sku', 'SKU-STABLE');
        $reviewCandidate = collect($result['weight_candidates'])->firstWhere('sku', 'SKU-REVIEW');

        $this->assertSame('high_confidence', $stableCandidate['status']);
        $this->assertSame(0.751, $stableCandidate['suggested_shipping_weight_kg']);
        $this->assertSame(7, $stableCandidate['raw_sample_count']);
        $this->assertSame(6, $stableCandidate['eligible_sample_count']);
        $this->assertSame(1, $stableCandidate['gross_outlier_count']);
        $this->assertTrue(collect($stableCandidate['samples'])->contains(fn (array $sample): bool => $sample['observed_weight_kg'] === 0.562 && $sample['gross_outlier']));

        $this->assertSame('review_required', $reviewCandidate['status']);
        $this->assertSame(0.292, $reviewCandidate['suggested_shipping_weight_kg']);
        $this->assertSame(3, $reviewCandidate['eligible_sample_count']);
        $this->assertDatabaseCount('product_weight_evidences', 0);
    }

    private function product(string $sku): Product
    {
        return Product::create(['source_type'=>'manual','sku'=>$sku,'name'=>'Fixture '.$sku,'is_active'=>true]);
    }

    private function order(string $number): Order
    {
        return Order::create(['internal_order_number'=>'ERP-'.$number,'source_type'=>'woocommerce','order_kind'=>'customer','external_order_number'=>$number,'order_date'=>'2026-09-08','order_status'=>'pending_shipment']);
    }

    private function row(int $row, string $order, ?float $weight, ?float $l, ?float $w, ?float $h): array
    {
        return [
            'source_row_number'=>$row,
            'order_reference'=>$order,
            'tracking_number'=>'TRACK-'.$row,
            'inbound_weight_kg'=>$weight === null ? null : (string)$weight,
            'length_cm'=>$l === null ? null : (string)$l,
            'width_cm'=>$w === null ? null : (string)$w,
            'height_cm'=>$h === null ? null : (string)$h,
        ];
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $path = tempnam(storage_path(), 'packaging-scan-');
        self::assertIsString($path); $this->paths[] = $path;
        $zip = new ZipArchive(); $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $sheet = '';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="'.($r+1).'">';
            foreach ($row as $c => $value) {
                $letters = ''; $n = $c + 1;
                while ($n > 0) { $n--; $letters = chr(65 + ($n % 26)).$letters; $n = intdiv($n, 26); }
                $sheet .= '<c r="'.$letters.($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="x"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="x"/></Types>');
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close(); return $path;
    }
}
