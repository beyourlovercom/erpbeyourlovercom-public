<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewCandidate;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationRow;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalReviewRescueService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalShipmentImportService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use ZipArchive;
use Tests\TestCase;

final class HistoricalImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        ini_set('sys_temp_dir', '/tmp');
        parent::setUp();
    }

    public function test_historical_import_migration_uses_mysql_safe_explicit_foreign_key_names(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_05_140000_create_logistics_historical_import_tables.php'));
        self::assertIsString($source);
        $names = [
            'log_hist_batch_recon_fk',
            'log_hist_batch_created_fk',
            'log_hist_batch_committed_fk',
            'log_hist_row_batch_fk',
            'log_hist_row_kol_fk',
            'log_hist_evidence_batch_fk',
            'log_hist_evidence_row_fk',
            'log_hist_evidence_kol_fk',
        ];
        foreach ($names as $name) {
            self::assertLessThanOrEqual(64, strlen($name), $name);
            self::assertStringContainsString("'{$name}'", $source);
        }
        self::assertStringNotContainsString('->constrained(', $source);
    }

    public function test_workspace_requires_view_permission_and_preview_requires_operate_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get('/logistics/historical-import')->assertForbidden();

        $viewer = $this->userWith(['logistics.view']);
        $this->actingAs($viewer)->get('/logistics/historical-import')->assertOk();
        $this->actingAs($viewer)->post('/logistics/historical-import/preview')->assertForbidden();
    }

    public function test_real_shape_preview_uses_exact_two_batch_kol_graph_and_classifies_ambiguity_without_pii(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        [$kolOld, $kolLate] = $this->seedExactKolGraph($operator, [6,7,9,10,12,18,19,28,32,34]);
        $file = $this->xlsx([
            6 => ['2.5 Published 已发布','Factory 国内仓','速达','4PX3002296509905CN','46358','KOL120204','VG522A','1','secret@example.test'],
            7 => ['2.1 Shipping 运输中','Factory 国内仓','','4PX3002302321354CN','46358','KOL120202','TW158','1','secret@example.test'],
            9 => ['2.1 Shipping 运输中','Factory 国内仓','佰事达','CF333158645DE','46359','KOL120302','VA617A','1','secret@example.test'],
            10 => ['3.0 Finished 完结','Factory 国内仓','佰事达','还在确认产品，不着急发货','46360','KOL120401','TL278A','1','secret@example.test'],
            12 => ['2.1 Shipping 运输中','Factory 国内仓','速达',"4PX3002327780501CN\nLR159817137NL",'46361','KOL120504','VA617A','1','secret@example.test'],
            18 => ['2.1 Shipping 运输中','Factory 国内仓','速达','4PX3002333294986CN','46365','DUP001','VA617A','1','secret@example.test'],
            19 => ['2.1 Shipping 运输中','Factory 国内仓','速达','4PX3002342650414CN','46365','DUP001','VM465A RED','1','secret@example.test'],
            28 => ['2.5 Published 已发布','Factory 国内仓','未对账','4PX3002342611651CN','46366','KOL121002','BW158','1','secret@example.test'],
            32 => ['2.1 Shipping 运输中','Factory 国内仓','速达','KOL120804 原单中转 https://t.17track.net/abc','46367','KOL120804','VA617A','1','secret@example.test'],
            34 => ['2.1 Shipping 运输中','Factory 国内仓','佰事达','WSHMX123456789YQCO987654321','46368','KOL121204','VA562A','1','secret@example.test'],
        ]);

        $batch = $this->app->make(HistoricalShipmentImportService::class)->preview($file, $operator);
        self::assertSame(10, $batch->total_rows);
        self::assertSame(3, $batch->ready_count);
        self::assertSame(5, $batch->review_count);
        self::assertSame(2, $batch->skip_count);
        self::assertNotNull($batch->reconciliation_batch_id);

        $byRow = HistoricalImportRow::query()->where('batch_id', $batch->id)->get()->keyBy('source_row_number');
        self::assertSame($kolLate->id, $byRow[6]->kol_id, 'Second activation batch created-KOL must win exactly.');
        self::assertSame($kolOld->id, $byRow[7]->kol_id);
        self::assertSame($kolOld->id, $byRow[9]->kol_id, 'Exact matched existing_guard fallback must resolve.');
        self::assertSame('ready', $byRow[6]->classification);
        self::assertSame('ready', $byRow[7]->classification, 'Blank source carrier is allowed.');
        self::assertSame('ready', $byRow[9]->classification);
        self::assertSame('no_tracking_candidate', $byRow[10]->classification_reason);
        self::assertSame('multiple_tracking_candidates', $byRow[12]->classification_reason);
        self::assertSame('duplicate_source_order_no', $byRow[18]->classification_reason);
        self::assertSame('duplicate_source_order_no', $byRow[19]->classification_reason);
        self::assertSame('carrier_requires_review', $byRow[28]->classification_reason);
        self::assertSame('no_tracking_candidate', $byRow[32]->classification_reason, 'KOL source order and transfer URL are never tracking candidates.');
        self::assertSame('suspicious_compound_tracking', $byRow[34]->classification_reason);
        self::assertStringNotContainsString('secret@example.test', json_encode(HistoricalImportRow::all()->toArray(), JSON_THROW_ON_ERROR));
        self::assertDatabaseCount('orders', 0);
        self::assertDatabaseCount('shipments', 0);
        self::assertDatabaseCount('kol_collaborations', 0);
    }

    public function test_unrelated_filename_never_fuzzy_matches_kol_graph(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $this->seedExactKolGraph($operator, [6]);
        $file = $this->xlsx([6 => ['2.1 Shipping 运输中','Factory 国内仓','速达','4PX3002296509905CN','46358','KOL120204','VG522A','1','none@example.test']], 'other-history.xlsx');
        $batch = $this->app->make(HistoricalShipmentImportService::class)->preview($file, $operator);
        $row = HistoricalImportRow::query()->where('batch_id', $batch->id)->firstOrFail();
        self::assertNull($batch->reconciliation_batch_id);
        self::assertNull($row->kol_id);
        self::assertSame('review', $row->classification);
        self::assertSame('kol_unresolved_exact_graph', $row->classification_reason);
    }

    public function test_commit_gate_is_off_by_default_and_never_creates_current_fulfillment_truth(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $batch = HistoricalImportBatch::create([
            'original_filename' => 'fixture.xlsx', 'source_checksum' => str_repeat('a', 64), 'source_sheet' => '工作表1',
            'status' => 'preview', 'total_rows' => 0, 'created_by' => $operator->id,
        ]);
        $this->actingAs($operator)->postJson("/logistics/historical-import/{$batch->id}/commit")->assertForbidden();
        self::assertDatabaseCount('logistics_historical_evidences', 0);
        self::assertDatabaseCount('orders', 0);
        self::assertDatabaseCount('shipments', 0);
        self::assertDatabaseCount('kol_collaborations', 0);
    }

    public function test_enabled_commit_is_idempotent_and_writes_only_ready_historical_evidence(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $this->seedExactKolGraph($operator, [6,7,9]);
        $file = $this->xlsx([
            6 => ['2.5 Published 已发布','Factory 国内仓','速达','4PX3002296509905CN','46358','KOL120204','VG522A','1','private@example.test'],
            7 => ['2.1 Shipping 运输中','Factory 国内仓','','4PX3002302321354CN','46358','KOL120202','TW158','1','private@example.test'],
            9 => ['2.1 Shipping 运输中','Factory 国内仓','佰事达','CF333158645DE','46359','KOL120302','VA617A','1','private@example.test'],
        ]);
        $service = $this->app->make(HistoricalShipmentImportService::class);
        $batch = $service->preview($file, $operator);
        self::assertSame(3, $batch->ready_count);
        $before = [
            'orders' => \DB::table('orders')->count(), 'shipments' => \DB::table('shipments')->count(),
            'collaborations' => \DB::table('kol_collaborations')->count(),
        ];
        config(['logistics.historical_import_commit_enabled' => true]);
        $service->commit($batch, $operator);
        $service->commit($batch->fresh(), $operator);
        self::assertSame(3, HistoricalEvidence::count());
        self::assertSame($before['orders'], \DB::table('orders')->count());
        self::assertSame($before['shipments'], \DB::table('shipments')->count());
        self::assertSame($before['collaborations'], \DB::table('kol_collaborations')->count());
        self::assertDatabaseHas('audit_records', ['action' => 'logistics.historical_import.committed']);
        self::assertStringNotContainsString('private@example.test', json_encode(HistoricalEvidence::all()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_review_rescue_plan_keeps_all_safe_multileg_tracking_and_unresolved_rows_manual(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $kol = Kol::create(['display_name' => 'Rescue Exact KOL', 'status' => 'active', 'owner_id' => $operator->id, 'relationship_stage' => 'prospect']);
        $batch = HistoricalImportBatch::create([
            'original_filename' => '0902-网红订单.xlsx', 'source_checksum' => str_repeat('b', 64), 'source_sheet' => '工作表1',
            'status' => 'committed', 'total_rows' => 4, 'review_count' => 4, 'created_by' => $operator->id,
        ]);
        $rows = [
            [24, 'KOL120911', $kol->id, '纬狮', "WSHMX0216843230YQ\nJMX101606052877", 'multiple_tracking_candidates'],
            [35, 'KOL121205', $kol->id, '纬狮', 'WSHCO3495625702YQCO3495625702', 'suspicious_compound_tracking'],
            [27, 'KOL120914', $kol->id, '更改地址成功，新单号请查收：KOL120914-1==WSHCO3465574711YQ=CO3465574711', 'WSHCO3455805884YQCO3455805884', 'replacement_or_transfer_ambiguity'],
            [109, 'KOL122101', null, '', 'JJD000030242945000000004367', 'kol_unresolved_exact_graph'],
        ];
        foreach ($rows as [$sourceRow, $orderNo, $kolId, $carrier, $tracking, $reason]) {
            HistoricalImportRow::create([
                'batch_id' => $batch->id, 'source_row_number' => $sourceRow, 'source_order_no' => $orderNo, 'kol_id' => $kolId,
                'source_status_raw' => '2.1 Shipping 运输中', 'source_carrier_raw' => $carrier, 'source_tracking_raw' => $tracking,
                'classification' => 'review', 'classification_reason' => $reason, 'evidence_hash' => hash('sha256', 'row-'.$sourceRow),
            ]);
        }

        $service = $this->app->make(HistoricalReviewRescueService::class);
        $plan = $service->plan($batch);
        self::assertSame(3, $plan['rescueable_rows']);
        self::assertSame(8, $plan['candidate_evidence_count']);
        self::assertSame(8, $plan['pending_evidence_count']);
        self::assertSame(1, $plan['manual_review_rows']);
        self::assertSame(['WSHMX0216843230YQ', 'JMX101606052877'], $service->rescueCandidates($batch->rows()->where('source_row_number', 24)->firstOrFail()));
        self::assertSame(['WSHCO3495625702YQ', 'CO3495625702'], $service->rescueCandidates($batch->rows()->where('source_row_number', 35)->firstOrFail()));
        self::assertSame(['WSHCO3455805884YQ', 'CO3455805884', 'WSHCO3465574711YQ', 'CO3465574711'], $service->rescueCandidates($batch->rows()->where('source_row_number', 27)->firstOrFail()));

        $this->actingAs($operator)->postJson("/logistics/historical-import/{$batch->id}/review-rescue/commit")->assertForbidden();
        self::assertDatabaseCount('logistics_historical_evidences', 0);
    }

    public function test_enabled_review_rescue_commit_is_idempotent_and_never_mutates_current_fulfillment_truth(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $kol = Kol::create(['display_name' => 'Rescue Exact KOL', 'status' => 'active', 'owner_id' => $operator->id, 'relationship_stage' => 'prospect']);
        $batch = HistoricalImportBatch::create([
            'original_filename' => '0902-网红订单.xlsx', 'source_checksum' => str_repeat('c', 64), 'source_sheet' => '工作表1',
            'status' => 'committed', 'total_rows' => 2, 'review_count' => 2, 'created_by' => $operator->id,
        ]);
        foreach ([
            [24, 'KOL120911', '纬狮', "WSHMX0216843230YQ\nJMX101606052877", 'multiple_tracking_candidates'],
            [35, 'KOL121205', '纬狮', 'WSHCO3495625702YQCO3495625702', 'suspicious_compound_tracking'],
        ] as [$sourceRow, $orderNo, $carrier, $tracking, $reason]) {
            HistoricalImportRow::create([
                'batch_id' => $batch->id, 'source_row_number' => $sourceRow, 'source_order_no' => $orderNo, 'kol_id' => $kol->id,
                'source_status_raw' => '2.1 Shipping 运输中', 'source_carrier_raw' => $carrier, 'source_tracking_raw' => $tracking,
                'classification' => 'review', 'classification_reason' => $reason, 'evidence_hash' => hash('sha256', 'rescue-'.$sourceRow),
            ]);
        }
        $before = ['orders' => \DB::table('orders')->count(), 'shipments' => \DB::table('shipments')->count(), 'collaborations' => \DB::table('kol_collaborations')->count()];
        config(['logistics.historical_review_rescue_commit_enabled' => true]);
        $service = $this->app->make(HistoricalReviewRescueService::class);
        $service->commit($batch, $operator);
        $service->commit($batch->fresh(), $operator);

        self::assertSame(4, HistoricalEvidence::count());
        self::assertSame('committed', $batch->fresh()->status);
        self::assertSame(0, $service->plan($batch->fresh())['pending_evidence_count']);
        self::assertSame($before['orders'], \DB::table('orders')->count());
        self::assertSame($before['shipments'], \DB::table('shipments')->count());
        self::assertSame($before['collaborations'], \DB::table('kol_collaborations')->count());
        self::assertDatabaseHas('audit_records', ['action' => 'logistics.historical_import.review_rescue_committed']);
        foreach (HistoricalEvidence::all() as $evidence) {
            self::assertSame('historical-review-rescue-v1', $evidence->evidence_snapshot['rescue']['version']);
            self::assertSame('review', $evidence->evidence_snapshot['rescue']['original_classification']);
        }
    }

    /** @return array{Kol,Kol} */
    private function seedExactKolGraph(User $actor, array $sourceRows): array
    {
        $old = Kol::create(['display_name' => 'Existing Exact KOL', 'status' => 'active', 'owner_id' => $actor->id, 'relationship_stage' => 'prospect']);
        $late = Kol::create(['display_name' => 'Late Exact KOL', 'status' => 'active', 'owner_id' => $actor->id, 'relationship_stage' => 'prospect']);
        $reconciliation = KolReconciliationBatch::create([
            'source_type' => 'csv', 'original_filename' => '0902-网红订单-replay.csv', 'checksum' => str_repeat('r', 64),
            'connection_key' => 'test.example', 'creator_user_id' => $actor->id, 'total_rows' => 1077, 'distinct_source_identities' => 970, 'status' => 'preview',
        ]);
        $rowModels = [];
        foreach ($sourceRows as $number) {
            $rowModels[$number] = KolReconciliationRow::create([
                'batch_id' => $reconciliation->id, 'source_row_number' => $number, 'row_hash' => hash('sha256', (string) $number),
                'raw_source' => ['safe_test_row' => $number], 'normalized_identity' => [], 'source_identity_key' => null,
                'kol_status' => 'unresolved', 'affiliate_status' => 'missing', 'reconciliation_status' => 'unresolved',
            ]);
        }
        $preview = KolImportPreviewBatch::create([
            'reconciliation_batch_id' => $reconciliation->id, 'creator_user_id' => $actor->id, 'status' => 'preview',
            'total_source_rows' => 1077, 'candidate_count' => 3, 'unresolved_source_rows' => 12,
        ]);
        $c6 = $this->candidate($preview, $rowModels[6]);
        $c7 = isset($rowModels[7]) ? $this->candidate($preview, $rowModels[7]) : null;
        $c9 = isset($rowModels[9]) ? $this->candidate($preview, $rowModels[9]) : null;

        $b1 = $this->activation($preview, $reconciliation, 1);
        $this->activationItem($b1, $c6, null, ['status' => 'none', 'kol_ids' => []]);
        if ($c7) $this->activationItem($b1, $c7, $old->id, ['status' => 'none', 'kol_ids' => []]);
        $b2 = $this->activation($preview, $reconciliation, 2);
        $this->activationItem($b2, $c6, $late->id, ['status' => 'none', 'kol_ids' => []]);
        if ($c7) $this->activationItem($b2, $c7, null, ['status' => 'matched', 'kol_ids' => [$old->id]]);
        if ($c9) $this->activationItem($b2, $c9, null, ['status' => 'matched', 'kol_ids' => [$old->id]]);
        return [$old, $late];
    }

    private function candidate(KolImportPreviewBatch $preview, KolReconciliationRow $row): KolImportPreviewCandidate
    {
        return KolImportPreviewCandidate::create([
            'import_preview_batch_id' => $preview->id, 'cluster_key' => hash('sha256', 'candidate-'.$row->source_row_number),
            'source_row_ids' => [$row->id], 'source_row_count' => 1, 'exact_identity' => [],
            'proposed_kol' => ['display_name' => 'Synthetic'], 'proposed_social_accounts' => [], 'owner_evidence' => [],
            'owner_resolution_status' => 'mapped', 'history_summary' => [], 'existing_kol_status' => 'none',
            'affiliate_account_ids' => [], 'preview_status' => 'needs_review',
        ]);
    }

    private function activation(KolImportPreviewBatch $preview, KolReconciliationBatch $reconciliation, int $version): KolImportActivationBatch
    {
        return KolImportActivationBatch::create([
            'import_preview_batch_id' => $preview->id, 'reconciliation_batch_id' => $reconciliation->id,
            'source_checksum' => $reconciliation->checksum, 'plan_version' => 1, 'status' => 'applied',
            'candidate_count' => 3, 'ready_count' => 1, 'blocked_count' => 0, 'existing_count' => 0, 'skipped_count' => 0,
            'plan_checksum' => hash('sha256', 'plan-'.$version), 'generated_by' => $preview->creator_user_id,
            'applied_by' => $preview->creator_user_id, 'generated_at' => now()->subMinutes(3 - $version), 'applied_at' => now()->subMinutes(3 - $version),
        ]);
    }

    private function activationItem(KolImportActivationBatch $batch, KolImportPreviewCandidate $candidate, ?int $createdKolId, array $guard): void
    {
        $batch->items()->create([
            'preview_candidate_id' => $candidate->id, 'cluster_key' => $candidate->cluster_key,
            'readiness_status' => $createdKolId ? 'applied' : (($guard['status'] ?? '') === 'matched' ? 'existing' : 'blocked'),
            'preview_status' => 'needs_review', 'block_reasons' => [], 'canonical_proposal' => ['display_name' => 'Synthetic'],
            'social_proposal' => [], 'exact_identity' => [], 'existing_guard' => $guard, 'source_owner_evidence' => [],
            'affiliate_evidence' => [], 'history_summary' => [], 'created_kol_id' => $createdKolId,
            'applied_by' => $createdKolId ? $batch->applied_by : null, 'applied_at' => $createdKolId ? now() : null,
        ]);
    }

    /** @param array<int,array<int,string>> $rows */
    private function xlsx(array $rows, string $filename = '0902-网红订单.xlsx'): UploadedFile
    {
        $path = tempnam('/tmp', 'hist-xlsx-');
        $zip = new ZipArchive(); self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="工作表1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $headers = ['Status 进度','Stock 仓库','代理 Carrier','追踪单号 Tracking No.','Input Time','单号 Order No.','SKU','数量Quantity','Contact Email'];
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$this->xlsxRow(1, $headers);
        foreach ($rows as $number => $values) $sheet .= $this->xlsxRow((int) $number, $values);
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet); $zip->close();
        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function xlsxRow(int $row, array $values): string
    {
        $xml = '<row r="'.$row.'">';
        foreach ($values as $index => $value) {
            $column = chr(ord('A') + $index); $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= '<c r="'.$column.$row.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
        }
        return $xml.'</row>';
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'historical-import-'.uniqid(), 'label' => 'Historical Import Test']);
        foreach ($permissions as $permission) $role->permissions()->attach(Permission::firstOrCreate(['key' => $permission], ['label' => $permission]));
        $user = User::factory()->create(['is_active' => true]); $user->roles()->attach($role); return $user;
    }
}
