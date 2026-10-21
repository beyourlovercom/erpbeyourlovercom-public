<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalOrderDateCorrectionService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderDateCorrectionBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationRow;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class HistoricalOrderDateCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_classifies_exact_ready_rows_and_future_outlier(): void
    {
        $fixture = $this->fixture();
        $preview = app(HistoricalOrderDateCorrectionService::class)->preview($fixture['materialization'], $fixture['user']);

        self::assertSame(3, $preview->total_rows);
        self::assertSame(2, $preview->ready_count);
        self::assertSame(1, $preview->review_count);
        self::assertSame(['ready', 'ready', 'review'], $preview->rows->pluck('decision')->all());
        self::assertSame('2025-12-01', $preview->rows[0]->proposed_order_date->toDateString());
        self::assertSame('future_date_manual_review', $preview->rows[2]->decision_reason);
    }

    public function test_missing_anchor_prevents_auto_ready_and_preview_is_idempotent(): void
    {
        $fixture = $this->fixture(false);
        $service = app(HistoricalOrderDateCorrectionService::class);
        $first = $service->preview($fixture['materialization'], $fixture['user']);
        $auditCount = DB::table('audit_records')->where('action', 'logistics.historical_order_date_correction.previewed')->count();
        $second = $service->preview($fixture['materialization'], $fixture['user']);

        self::assertSame(0, $first->ready_count);
        self::assertSame('rollover_anchor_required', $first->rows->first()->decision_reason);
        self::assertSame($first->id, $second->id);
        self::assertSame($auditCount, DB::table('audit_records')->where('action', 'logistics.historical_order_date_correction.previewed')->count());
    }

    public function test_gate_off_and_apply_only_patches_dates_preserving_manifests_and_related_truth(): void
    {
        $fixture = $this->fixture();
        $service = app(HistoricalOrderDateCorrectionService::class);
        $preview = $service->preview($fixture['materialization'], $fixture['user']);
        $beforeManifest = $fixture['rows'][0]->fresh()->materialization_manifest;
        $beforeAttempt = ShipmentAttempt::query()->create(['order_id' => $fixture['orders'][0]->id, 'attempt_number' => 1, 'shipment_status' => 'historical_evidence', 'snapshotted_at' => now()])->fresh()->toArray();
        $beforeEvidence = $fixture['evidence'][0]->fresh()->toArray();

        try {
            $service->apply($preview, $fixture['user']);
            self::fail('Expected the correction apply gate to block writes.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame($beforeManifest, $fixture['rows'][0]->fresh()->materialization_manifest);
        self::assertSame($beforeEvidence, $fixture['evidence'][0]->fresh()->toArray());
        self::assertSame($beforeAttempt, ShipmentAttempt::query()->first()->toArray());
    }

    public function test_apply_aborts_on_drift_and_reapply_is_inert(): void
    {
        $fixture = $this->fixture();
        $service = app(HistoricalOrderDateCorrectionService::class);
        $preview = $service->preview($fixture['materialization'], $fixture['user']);
        $fixture['orders'][0]->update(['order_date' => '2026-12-02']);
        config()->set('logistics.historical_order_date_correction_apply_enabled', true);

        $this->expectException(ValidationException::class);
        $service->apply($preview, $fixture['user']);
    }

    public function test_apply_updates_order_and_materialization_manifest_without_changing_erp_identity(): void
    {
        $fixture = $this->fixture();
        $service = app(HistoricalOrderDateCorrectionService::class);
        $preview = $service->preview($fixture['materialization'], $fixture['user']);
        config()->set('logistics.historical_order_date_correction_apply_enabled', true);

        $applied = $service->apply($preview, $fixture['user']);
        self::assertSame('applied', $applied->status);
        self::assertSame(2, $applied->applied_count);
        self::assertSame('2025-12-01', $fixture['orders'][0]->fresh()->order_date->toDateString());
        self::assertSame($fixture['orders'][0]->erp_order_id, $fixture['orders'][0]->fresh()->erp_order_id);
        self::assertSame('2025-12-01', data_get($fixture['rows'][0]->fresh()->materialization_manifest, 'order_date'));
        self::assertSame('keep-me', data_get($fixture['rows'][0]->fresh()->materialization_manifest, 'unrelated_key'));
        $auditCount = DB::table('audit_records')->where('action', 'logistics.historical_order_date_correction.applied')->count();
        $service->apply($applied, $fixture['user']);
        self::assertSame($auditCount, DB::table('audit_records')->where('action', 'logistics.historical_order_date_correction.applied')->count());
    }

    public function test_preview_recomputes_canonical_erp_identity_and_preserves_collision_suffix(): void
    {
        $fixture = $this->fixture();
        $identity = app(OrderIdentityService::class);
        $suffixed = $identity->previewCandidate('k', $fixture['kol']->id, '2026-12-01', 'A');
        $fixture['orders'][0]->update(['erp_order_id' => $suffixed]);

        $preview = app(HistoricalOrderDateCorrectionService::class)->preview($fixture['materialization'], $fixture['user']);
        $row = $preview->rows->firstWhere('source_row_number', 3);

        self::assertSame('ready', $row->decision);
        self::assertSame($suffixed, $row->correction_manifest['current_erp_order_id']);
        self::assertSame($suffixed, $row->correction_manifest['proposed_erp_order_id']);
    }

    public function test_preview_rejects_noncanonical_erp_identity_instead_of_guessing(): void
    {
        $fixture = $this->fixture();
        $fixture['orders'][0]->update(['erp_order_id' => '007erp-9999-k999999']);

        $preview = app(HistoricalOrderDateCorrectionService::class)->preview($fixture['materialization'], $fixture['user']);
        $row = $preview->rows->firstWhere('source_row_number', 3);

        self::assertSame('review', $row->decision);
        self::assertSame('erp_identity_change_requires_review', $row->decision_reason);
        self::assertNull($row->proposed_order_date);
    }

    public function test_future_date_outside_exact_2026_december_cluster_is_review(): void
    {
        $fixture = $this->fixture();
        $futureDate = '2027-12-02';
        $serial = \Carbon\CarbonImmutable::create(1899, 12, 30)->diffInDays(\Carbon\CarbonImmutable::parse($futureDate));
        $fixture['orders'][1]->update(['order_date' => $futureDate]);
        $manifest = $fixture['rows'][1]->materialization_manifest;
        $manifest['order_date'] = $futureDate;
        $fixture['rows'][1]->update(['materialization_manifest' => $manifest]);
        $fixture['evidence'][1]->row->update(['source_input_time_raw' => (string) $serial]);

        $preview = app(HistoricalOrderDateCorrectionService::class)->preview($fixture['materialization'], $fixture['user']);
        $row = $preview->rows->firstWhere('source_row_number', 4);

        self::assertNotNull($row);
        self::assertSame('review', $row->decision);
        self::assertSame('future_date_manual_review', $row->decision_reason);
        self::assertNull($row->proposed_order_date);
    }

    public function test_correction_migration_uses_explicit_short_mysql_names(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_06_100000_create_historical_order_date_correction_tables.php'));
        self::assertIsString($source);
        self::assertStringNotContainsString('->constrained(', $source);
        preg_match_all("/'(log_hist_odc_[^']+)'/", $source, $matches);
        foreach (array_unique($matches[1] ?? []) as $name) self::assertLessThanOrEqual(64, strlen($name), $name);
    }

    /** @return array<string,mixed> */
    private function fixture(bool $anchor = true): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['key' => 'hist-date-'.uniqid(), 'label' => 'Historical date']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => 'logistics.operate'], ['label' => 'logistics.operate']));
        $user->roles()->attach($role);
        $checksum = hash('sha256', uniqid('', true));
        $import = HistoricalImportBatch::query()->create(['original_filename' => 'history.xlsx', 'source_checksum' => $checksum, 'status' => 'committed']);
        $kol = Kol::query()->create(['display_name' => 'KOL date', 'status' => 'active', 'relationship_stage' => 'prospect']);
        $materialization = HistoricalOrderMaterializationBatch::query()->create(['historical_import_batch_id' => $import->id, 'source_checksum' => $checksum, 'status' => 'applied']);
        $orders = $rows = $evidence = [];
        $identity = app(OrderIdentityService::class);
        foreach ([3 => 'KOL120101', 4 => 'KOL120202', 87 => 'KOL120303'] as $number => $orderNo) {
            $day = $number === 3 ? 1 : ($number === 4 ? 2 : 3);
            $source = HistoricalImportRow::query()->create(['batch_id' => $import->id, 'source_row_number' => $number, 'source_order_no' => $orderNo, 'kol_id' => $kol->id, 'source_input_time_raw' => (string) (46356 + $day), 'source_tracking_raw' => 'TRACK-'.$number, 'normalized_tracking_candidate' => 'TRACK-'.$number, 'classification' => 'ready', 'classification_reason' => 'fixture', 'evidence_hash' => hash('sha256', $orderNo)]);
            $ev = HistoricalEvidence::query()->create(['batch_id' => $import->id, 'historical_row_id' => $source->id, 'source_row_number' => $number, 'kol_id' => $kol->id, 'source_checksum' => $checksum, 'normalized_tracking_candidate' => 'TRACK-'.$number, 'evidence_hash' => $source->evidence_hash, 'evidence_snapshot' => ['source_order_no' => $orderNo, 'source_input_time_raw' => $source->source_input_time_raw]]);
            $orderDate = '2026-12-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            $order = Order::query()->create(['internal_order_number' => 'INT-'.$number, 'erp_order_id' => $identity->previewCandidate('k', $kol->id, $orderDate), 'source_type' => 'kol', 'order_kind' => 'kol_fulfillment', 'external_order_number' => $orderNo, 'order_date' => $orderDate, 'currency' => 'USD', 'order_status' => 'pending_shipment']);
            $rows[] = HistoricalOrderMaterializationRow::query()->create(['materialization_batch_id' => $materialization->id, 'historical_evidence_id' => $ev->id, 'source_row_number' => $number, 'source_order_no' => $orderNo, 'decision' => 'applied', 'decision_reason' => 'fixture', 'materialization_manifest' => ['kol_id' => $kol->id, 'order_date' => $order->order_date->toDateString(), 'unrelated_key' => 'keep-me'], 'order_id' => $order->id]);
            $orders[] = $order; $evidence[] = $ev;
        }
        if ($anchor) HistoricalImportRow::query()->create(['batch_id' => $import->id, 'source_row_number' => 78, 'source_order_no' => 'KOL010101', 'kol_id' => $kol->id, 'source_input_time_raw' => '46023', 'classification' => 'review', 'classification_reason' => 'anchor', 'evidence_hash' => hash('sha256', 'KOL010101')]);
        return compact('user', 'kol', 'materialization', 'orders', 'rows', 'evidence');
    }
}
