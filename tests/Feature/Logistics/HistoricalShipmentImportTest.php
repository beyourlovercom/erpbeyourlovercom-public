<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationItem;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewCandidate;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationRow;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalShipmentImportService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalShipmentXlsxReader;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class HistoricalShipmentImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixturePaths = [];

    protected function tearDown(): void
    {
        foreach ($this->fixturePaths as $path) if (is_file($path)) unlink($path);
        parent::tearDown();
    }

    public function test_reader_whitelists_logistics_columns_and_extracts_safe_tracking_tokens(): void
    {
        $file = $this->xlsx([
            ['Status', 'Tracking No.', 'Order No.', 'Email', 'Shipping Address', 'Carrier'],
            ['2.1 Shipping', 'DHL-12345678', 'KOL0001', 'pii@example.com', 'private address', 'DHL'],
        ]);

        $rows = app(HistoricalShipmentXlsxReader::class)->parse($file);

        self::assertSame([[
            'source_row_number' => 2, 'status' => '2.1 Shipping', 'warehouse' => null, 'carrier' => 'DHL',
            'tracking' => 'DHL-12345678', 'input_time' => null, 'order_no' => 'KOL0001', 'sku' => null, 'quantity' => null,
        ]], $rows);
    }

    public function test_preview_resolves_only_the_applied_exact_activation_graph_and_commit_is_idempotent(): void
    {
        config(['logistics.historical_import_commit_enabled' => true]);
        $admin = $this->operator();
        $kol = Kol::create(['display_name' => 'Exact KOL', 'status' => 'active', 'relationship_stage' => 'prospect']);
        $file = $this->xlsx([
            ['Status', 'Tracking No.', 'Order No.'],
            ['2.1 Shipping', 'DHL-12345678', 'KOL0001'],
        ]);
        $checksum = hash_file('sha256', $file->getRealPath());
        $reconciliation = KolReconciliationBatch::create(['source_type' => 'xlsx', 'original_filename' => 'historical.xlsx', 'checksum' => $checksum, 'connection_key' => 'local', 'status' => 'preview', 'total_rows' => 1]);
        $sourceRow = KolReconciliationRow::create(['batch_id' => $reconciliation->id, 'source_row_number' => 2, 'row_hash' => hash('sha256', 'row'), 'raw_source' => ['Order No.' => 'KOL0001'], 'normalized_identity' => [], 'kol_status' => 'unresolved', 'affiliate_status' => 'missing', 'reconciliation_status' => 'unresolved']);
        $preview = KolImportPreviewBatch::create(['reconciliation_batch_id' => $reconciliation->id, 'status' => 'preview']);
        $candidate = KolImportPreviewCandidate::create(['import_preview_batch_id' => $preview->id, 'cluster_key' => hash('sha256', 'candidate'), 'source_row_ids' => [$sourceRow->id], 'source_row_count' => 1, 'exact_identity' => [], 'proposed_kol' => [], 'preview_status' => 'ready']);
        $activation = KolImportActivationBatch::create(['import_preview_batch_id' => $preview->id, 'reconciliation_batch_id' => $reconciliation->id, 'source_checksum' => $checksum, 'plan_checksum' => hash('sha256', 'plan'), 'status' => 'applied', 'generated_at' => now()]);
        KolImportActivationItem::create(['activation_batch_id' => $activation->id, 'preview_candidate_id' => $candidate->id, 'cluster_key' => $candidate->cluster_key, 'readiness_status' => 'applied', 'preview_status' => 'ready', 'canonical_proposal' => [], 'existing_guard' => [], 'created_kol_id' => $kol->id]);

        $service = app(HistoricalShipmentImportService::class);
        $batch = $service->preview($file, $admin);
        $row = $batch->rows->first();
        self::assertSame('ready', $row->classification);
        self::assertSame($kol->id, $row->kol_id);
        self::assertSame('DHL-12345678', $row->normalized_tracking_candidate);
        self::assertSame('2.1 Shipping', $row->source_status_raw);

        $service->commit($batch, $admin);
        $service->commit($batch->fresh(), $admin);
        self::assertSame(1, HistoricalEvidence::count());
        self::assertDatabaseCount('shipments', 0);
        self::assertDatabaseCount('orders', 0);
    }

    public function test_cancelled_multiple_and_unresolved_rows_fail_closed(): void
    {
        $admin = $this->operator();
        $file = $this->xlsx([
            ['Status', 'Tracking No.', 'Order No.'],
            ['Cancelled', 'DHL-12345678', 'KOL0001'],
            ['2.1 Shipping', 'DHL-12345678 UPS-87654321', 'KOL0002'],
            ['2.1 Shipping', 'DHL-12345678', ''],
        ]);

        $batch = app(HistoricalShipmentImportService::class)->preview($file, $admin);
        self::assertSame(0, $batch->ready_count);
        self::assertSame(2, $batch->skip_count);
        self::assertSame(1, $batch->review_count);
        self::assertSame(['cancelled_or_no_shipment', 'multiple_tracking_candidates', 'missing_order_no'], $batch->rows->pluck('classification_reason')->all());
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'historical_import_'.uniqid(), 'label' => 'Historical import test']);
        foreach (['logistics.view', 'logistics.operate'] as $key) $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(base_path('storage'), 'historical-import-');
        self::assertIsString($path);
        $this->fixturePaths[] = $path;
        $zip = new ZipArchive();
        $zip->open($path);
        $sheet = '';
        foreach ($rows as $rowNumber => $row) {
            $sheet .= '<row r="'.($rowNumber + 1).'">';
            foreach ($row as $column => $value) $sheet .= '<c r="'.chr(65 + $column).($rowNumber + 1).'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            $sheet .= '</row>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="x"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="x"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="工作表1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close();
        return new UploadedFile($path, 'historical.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
