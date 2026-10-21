<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\CarrierBilling\CarrierBillingImportService;
use App\Modules\Logistics\Infrastructure\Models\CarrierBillingImportBatch;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

final class CarrierBillingImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_dry_run_is_zero_write_and_apply_persists_grouped_actual_billing_evidence(): void
    {
        $this->shipment('TRACK-1');
        $file = $this->csv([
            ['TRACK-1', '10.2500', 'USD', '1.200', 'BILL-1', 'REF-1', '2026-09-14', 'base'],
            ['TRACK-1', '2.7500', 'USD', '1.200', 'BILL-1', 'REF-1', '2026-09-14', 'fuel'],
        ]);
        $service = app(CarrierBillingImportService::class);

        $preview = $service->preview($file, 'dhl');
        $this->assertSame(2, $preview['total_rows']);
        $this->assertSame(2, $preview['ready_count']);
        $this->assertSame(0, $preview['review_count']);
        $this->assertDatabaseCount('carrier_billing_import_batches', 0);
        $this->assertDatabaseCount('shipment_carrier_billing', 0);

        $applied = $service->apply($file, 'dhl');
        $this->assertFalse($applied['already_applied']);
        $this->assertSame(1, $applied['billing_created']);
        $this->assertDatabaseCount('carrier_billing_import_batches', 1);
        $this->assertDatabaseCount('carrier_billing_import_rows', 2);
        $this->assertDatabaseHas('shipment_carrier_billing', [
            'actual_cost' => '13.0000', 'currency' => 'USD', 'billing_document_number' => 'BILL-1', 'data_source' => 'carrier_bill_import:dhl',
        ]);
        $this->assertSame([2, 3], DB::table('shipment_carrier_billing')->first()->raw_evidence ? json_decode(DB::table('shipment_carrier_billing')->first()->raw_evidence, true)['source_row_numbers'] : []);

        $again = $service->apply($file, 'dhl');
        $this->assertTrue($again['already_applied']);
        $this->assertDatabaseCount('shipment_carrier_billing', 1);
        $this->assertDatabaseCount('carrier_billing_import_batches', 1);
    }

    public function test_unmatched_ambiguous_conflicting_and_existing_different_billing_are_review_not_writes(): void
    {
        $this->shipment('DUP');
        $this->shipment('DUP');
        $existing = $this->shipment('EXISTING');
        $existing->billingEvidence()->create(['actual_cost' => '9.0000', 'currency' => 'USD', 'billing_document_number' => 'OLD', 'data_source' => 'carrier_bill']);
        $file = $this->csv([
            ['MISSING', '1.0000', 'USD', null, null, null, null, null],
            ['DUP', '2.0000', 'USD', null, null, null, null, null],
            ['CONFLICT', '3.0000', 'USD', null, null, null, null, null],
            ['CONFLICT', '4.0000', 'EUR', null, null, null, null, null],
            ['EXISTING', '10.0000', 'USD', null, 'NEW', null, null, null],
        ]);
        $this->shipment('CONFLICT');

        $preview = app(CarrierBillingImportService::class)->preview($file, 'carrier');
        $this->assertSame(5, $preview['review_count']);
        $reasons = collect($preview['rows'])->pluck('reason_code')->unique()->sort()->values()->all();
        $this->assertContains('shipment_not_found', $reasons);
        $this->assertContains('shipment_tracking_ambiguous', $reasons);
        $this->assertContains('conflicting_group_currency', $reasons);
        $this->assertContains('existing_billing_conflict', $reasons);
    }

    public function test_existing_identical_billing_is_skip_and_xlsx_matches_csv_plan_semantics(): void
    {
        $shipment = $this->shipment('TRACK-X');
        $shipment->billingEvidence()->create([
            'actual_cost' => '6.5000', 'currency' => 'EUR', 'chargeable_weight_kg' => '0.750',
            'billing_document_number' => 'B-X', 'provider_reference' => 'P-X', 'data_source' => 'carrier_bill',
        ]);
        $rows = [['TRACK-X', '6.5000', 'EUR', '0.750', 'B-X', 'P-X', null, 'base']];
        $csv = app(CarrierBillingImportService::class)->preview($this->csv($rows), 'dhl');
        $xlsx = app(CarrierBillingImportService::class)->preview($this->xlsx($rows), 'dhl');
        $this->assertSame(1, $csv['skip_count']);
        $this->assertSame(1, $xlsx['skip_count']);
        $this->assertSame($csv['rows'][0]['normalized_data'], $xlsx['rows'][0]['normalized_data']);
        $this->assertSame('already_applied', $xlsx['rows'][0]['reason_code']);
    }

    public function test_invalid_canonical_rows_and_legacy_xls_fail_closed_without_writes(): void
    {
        $file = $this->csv([
            ['', '1.0000', 'USD', null, null, null, null, null],
            ['BAD-MONEY', 'not-money', 'USD', null, null, null, null, null],
            ['BAD-CURRENCY', '1.0000', 'ZZZ', null, null, null, null, null],
            ['BAD-DATE', '1.0000', 'USD', null, null, null, 'not-a-date', null],
        ]);
        $preview = app(CarrierBillingImportService::class)->preview($file, 'dhl');
        $this->assertSame(4, $preview['review_count']);
        $this->assertSame(0, $preview['ready_count']);
        $reasons = collect($preview['rows'])->pluck('reason_code')->sort()->values()->all();
        $this->assertSame(['actual_cost_invalid', 'billed_at_invalid', 'currency_invalid', 'tracking_missing'], $reasons);
        $this->assertDatabaseCount('shipment_carrier_billing', 0);

        $xls = tempnam(sys_get_temp_dir(), 'p6b-xls-').'.xls';
        file_put_contents($xls, 'legacy-binary-placeholder');
        try {
            app(CarrierBillingImportService::class)->preview($xls, 'dhl');
            $this->fail('Legacy XLS must fail closed.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Legacy binary XLS is not supported', $e->getMessage());
        }
        $this->assertDatabaseCount('carrier_billing_import_batches', 0);
    }

    public function test_command_is_dry_run_first_and_production_apply_requires_flag_and_exact_guards(): void
    {
        $this->shipment('CMD-1');
        $file = $this->csv([['CMD-1', '7.0000', 'USD', null, 'B-CMD', null, null, null]]);
        $service = app(CarrierBillingImportService::class);
        $plan = $service->preview($file, 'dhl');
        $this->artisan('logistics:import-carrier-bill', ['file' => $file, '--provider' => 'dhl'])->assertSuccessful();
        $this->assertDatabaseCount('shipment_carrier_billing', 0);

        $old = $this->app['env']; $this->app['env'] = 'production'; putenv('ERP_CARRIER_BILL_IMPORT_PRODUCTION_APPLY_ENABLED');
        $args = ['file' => $file, '--provider' => 'dhl', '--apply' => true,
            '--expect-source-sha' => $plan['source_checksum'], '--expect-plan-sha' => $plan['plan_checksum'],
            '--expect-ready' => '1', '--expect-review' => '0', '--expect-skip' => '0'];
        try {
            $this->artisan('logistics:import-carrier-bill', $args)->assertFailed();
            putenv('ERP_CARRIER_BILL_IMPORT_PRODUCTION_APPLY_ENABLED=true');
            $wrong = $args; $wrong['--expect-ready'] = '2';
            $this->artisan('logistics:import-carrier-bill', $wrong)->assertFailed();
            $this->assertDatabaseCount('shipment_carrier_billing', 0);
            $this->artisan('logistics:import-carrier-bill', $args)->assertSuccessful();
            $this->assertDatabaseCount('shipment_carrier_billing', 1);
            $this->artisan('logistics:import-carrier-bill', $args)->assertSuccessful();
            $this->assertDatabaseCount('shipment_carrier_billing', 1);
        } finally { putenv('ERP_CARRIER_BILL_IMPORT_PRODUCTION_APPLY_ENABLED'); $this->app['env'] = $old; }
    }

    private function shipment(string $tracking): Shipment
    {
        $order = Order::query()->create([
            'internal_order_number' => 'P6B-'.strtoupper(uniqid()), 'source_type' => 'manual', 'order_kind' => 'customer',
            'order_date' => '2026-09-14', 'order_status' => 'shipped', 'currency' => 'USD',
        ]);
        return $order->shipment()->create(['shipment_status' => 'shipped', 'tracking_number' => $tracking]);
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p6b-').'.csv';
        $h = fopen($path, 'wb');
        fputcsv($h, ['tracking_number','actual_cost','currency','chargeable_weight_kg','billing_document_number','provider_reference','billed_at','charge_description']);
        foreach ($rows as $row) fputcsv($h, $row);
        fclose($h); return $path;
    }

    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p6b-').'.xlsx';
        $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $headers = ['tracking_number','actual_cost','currency','chargeable_weight_kg','billing_document_number','provider_reference','billed_at','charge_description'];
        $all = [$headers, ...$rows]; $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($all as $ri => $row) {
            $sheet .= '<row r="'.($ri+1).'">';
            foreach ($row as $ci => $value) {
                if ($value === null) continue;
                $letters=''; $n=$ci+1; while($n>0){$n--; $letters=chr(65+($n%26)).$letters; $n=intdiv($n,26);}
                $sheet .= '<c r="'.$letters.($ri+1).'" t="inlineStr"><is><t>'.htmlspecialchars((string)$value, ENT_XML1).'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml',$sheet); $zip->close(); return $path;
    }
}
