<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalRescueOrderProjectionService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalRescueShipmentAttemptProjectionService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueOrderProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HistoricalRescueShipmentAttemptProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_projects_multileg_attempt_numbers_and_warehouse_semantics(): void
    {
        $this->factoryWarehouse();
        [$orderBatch, $operator] = $this->appliedOrderBatch([
            [300, 'KOL-MULTI-4', ['TRACK-A', 'TRACK-B', 'TRACK-C', 'TRACK-D'], 'Factory 国内仓'],
            [301, 'KOL-UNKNOWN-WH', ['TRACK-E'], 'Tingon外采仓'],
        ]);

        $preview = $this->service()->preview($orderBatch, $operator);

        self::assertSame(5, $preview->total_rows);
        self::assertSame(5, $preview->ready_count);
        self::assertSame(0, $preview->review_count);
        self::assertSame([1, 2, 3, 4], $preview->rows->where('source_row_number', 300)->pluck('attempt_number')->values()->all());
        self::assertSame(['exact'], $preview->rows->where('source_row_number', 300)->pluck('projection_manifest.warehouse_resolution')->unique()->values()->all());
        $unknown = $preview->rows->firstWhere('source_row_number', 301);
        self::assertSame('unresolved', $unknown->projection_manifest['warehouse_resolution']);
        self::assertNull($unknown->projection_manifest['warehouse_id']);
        self::assertNull($unknown->projection_manifest['warehouse_selection_source']);
        self::assertSame(0, ShipmentAttempt::count());
        self::assertSame(0, Shipment::count());
    }

    public function test_preview_is_idempotent_and_apply_gate_defaults_off(): void
    {
        $this->factoryWarehouse();
        [$orderBatch, $operator] = $this->appliedOrderBatch([
            [310, 'KOL-IDEMPOTENT-ATTEMPT', ['TRACK-F', 'TRACK-G'], 'Factory 国内仓'],
        ]);
        $service = $this->service();
        $first = $service->preview($orderBatch, $operator);
        $audit = DB::table('audit_records')->where('action', 'logistics.historical_rescue_shipment_attempt_projection.previewed')->count();
        $second = $service->preview($orderBatch, $operator);

        self::assertSame($first->id, $second->id);
        self::assertSame(2, $second->rows->count());
        self::assertSame($audit, DB::table('audit_records')->where('action', 'logistics.historical_rescue_shipment_attempt_projection.previewed')->count());
        try {
            $service->apply($second, $operator);
            self::fail('Expected rescue attempt apply gate to be disabled.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(0, ShipmentAttempt::count());
    }

    public function test_apply_creates_all_legs_without_shipments_and_is_idempotent(): void
    {
        $factory = $this->factoryWarehouse();
        [$orderBatch, $operator] = $this->appliedOrderBatch([
            [320, 'KOL-APPLY-LEGS', ['TRACK-H', 'TRACK-I', 'TRACK-J'], 'Factory 国内仓'],
            [321, 'KOL-APPLY-NULL-WH', ['TRACK-K'], 'Tingon外采仓'],
        ]);
        $preview = $this->service()->preview($orderBatch, $operator);
        config()->set('logistics.historical_rescue_shipment_attempt_apply_enabled', true);

        $applied = $this->service()->apply($preview, $operator);

        self::assertSame('applied', $applied->status);
        self::assertSame(4, $applied->applied_count);
        self::assertSame(4, ShipmentAttempt::count());
        self::assertSame(0, Shipment::count());
        $firstOrder = $orderBatch->rows()->where('source_row_number', 320)->firstOrFail()->order_id;
        self::assertSame([1, 2, 3], ShipmentAttempt::where('order_id', $firstOrder)->orderBy('attempt_number')->pluck('attempt_number')->all());
        self::assertSame([$factory->id], ShipmentAttempt::where('order_id', $firstOrder)->pluck('warehouse_id')->unique()->values()->all());
        self::assertSame(0, ShipmentAttempt::whereNotNull('shipment_id')->count());
        self::assertSame(0, ShipmentAttempt::whereNotNull('shipped_at')->count());
        self::assertSame(0, ShipmentAttempt::whereNotNull('carrier')->count());
        self::assertSame(0, ShipmentAttempt::whereNotNull('service')->count());
        $nullWarehouse = ShipmentAttempt::where('tracking_number', 'TRACK-K')->firstOrFail();
        self::assertNull($nullWarehouse->warehouse_id);
        self::assertNull($nullWarehouse->warehouse_selection_source);

        $attempts = ShipmentAttempt::count();
        $audit = DB::table('audit_records')->where('action', 'logistics.historical_rescue_shipment_attempt_projection.applied')->count();
        $this->service()->apply($applied, $operator);
        self::assertSame($attempts, ShipmentAttempt::count());
        self::assertSame($audit, DB::table('audit_records')->where('action', 'logistics.historical_rescue_shipment_attempt_projection.applied')->count());
        self::assertSame(0, Shipment::count());
    }

    public function test_preview_fails_closed_on_tracking_and_attempt_number_conflicts(): void
    {
        $this->factoryWarehouse();
        [$orderBatch, $operator] = $this->appliedOrderBatch([
            [330, 'KOL-CONFLICT-LEGS', ['TRACK-L', 'TRACK-M'], 'Factory 国内仓'],
        ]);
        $orderId = $orderBatch->rows()->firstOrFail()->order_id;
        ShipmentAttempt::create([
            'order_id' => $orderId, 'shipment_id' => null, 'attempt_number' => 1,
            'shipment_status' => 'historical_evidence', 'tracking_number' => 'PREEXISTING', 'snapshotted_at' => now(),
        ]);

        $preview = $this->service()->preview($orderBatch, $operator);
        self::assertSame(1, $preview->ready_count);
        self::assertSame(1, $preview->review_count);
        self::assertSame('attempt_number_conflict', $preview->rows->firstWhere('attempt_number', 1)->decision_reason);
        self::assertSame('ready', $preview->rows->firstWhere('attempt_number', 2)->decision);

        $batch2 = $this->batch();
        $this->rescueRow($batch2, 331, 'KOL-TRACK-CONFLICT', ['TRACK-N'], 'Factory 国内仓');
        [$orderBatch2, $operator2] = $this->applyOrderBatch($batch2);
        ShipmentAttempt::create([
            'order_id' => $this->existingOrder('OTHER')->id, 'shipment_id' => null, 'attempt_number' => 1,
            'shipment_status' => 'historical_evidence', 'tracking_number' => 'TRACK-N', 'snapshotted_at' => now(),
        ]);
        $preview2 = $this->service()->preview($orderBatch2, $operator2);
        self::assertSame('tracking_conflict_shipment_attempt', $preview2->rows->first()->decision_reason);
    }

    public function test_apply_aborts_whole_batch_when_frozen_evidence_drifts(): void
    {
        $this->factoryWarehouse();
        [$orderBatch, $operator] = $this->appliedOrderBatch([
            [340, 'KOL-DRIFT-A', ['TRACK-O', 'TRACK-P'], 'Factory 国内仓'],
        ]);
        $preview = $this->service()->preview($orderBatch, $operator);
        $evidenceId = $preview->rows->first()->historical_evidence_id;
        HistoricalEvidence::findOrFail($evidenceId)->update(['normalized_tracking_candidate' => 'TRACK-DRIFTED']);
        config()->set('logistics.historical_rescue_shipment_attempt_apply_enabled', true);

        try {
            $this->service()->apply($preview, $operator);
            self::fail('Expected frozen rescue attempt evidence drift to abort apply.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('batch', $exception->errors());
        }

        self::assertSame(0, ShipmentAttempt::count());
        self::assertSame(0, Shipment::count());
        self::assertSame('preview', $preview->fresh()->status);
    }

    public function test_preview_fails_closed_when_r4_order_identity_or_reference_drifts(): void
    {
        $this->factoryWarehouse();
        [$orderBatch, $operator] = $this->appliedOrderBatch([
            [340, 'KOL-REF-DRIFT', ['TRACK-Q1'], 'Factory 国内仓'],
            [341, 'KOL-ID-DRIFT', ['TRACK-Q2'], 'Factory 国内仓'],
        ]);
        $rows = $orderBatch->rows()->where('decision', 'applied')->orderBy('source_row_number')->get();
        $referenceOrder = Order::query()->findOrFail($rows->first()->order_id);
        $referenceOrder->externalReferences()->where('provider', 'historical_logistics')->delete();
        $identityOrder = Order::query()->findOrFail($rows->last()->order_id);
        $identityOrder->update(['erp_order_id' => $identityOrder->erp_order_id.'X']);

        $preview = $this->service()->preview($orderBatch, $operator);

        self::assertSame(0, $preview->ready_count);
        self::assertSame(2, $preview->review_count);
        self::assertSame('historical_external_reference_missing', $preview->rows->firstWhere('source_row_number', 340)->decision_reason);
        self::assertSame('source_order_identity_drift', $preview->rows->firstWhere('source_row_number', 341)->decision_reason);
    }

    public function test_missing_exact_warehouse_master_reviews_only_affected_legs(): void
    {
        [$orderBatch, $operator] = $this->appliedOrderBatch([
            [350, 'KOL-WH-MISSING', ['TRACK-Q', 'TRACK-R'], 'Factory 国内仓'],
            [351, 'KOL-WH-UNRESOLVED', ['TRACK-S'], 'Tingon外采仓'],
        ]);
        Warehouse::query()->where('code', 'FACTORY')->delete();
        $preview = $this->service()->preview($orderBatch, $operator);

        self::assertSame(1, $preview->ready_count);
        self::assertSame(2, $preview->review_count);
        self::assertSame(['warehouse_master_missing'], $preview->rows->where('source_row_number', 350)->pluck('decision_reason')->unique()->values()->all());
        self::assertSame('ready', $preview->rows->firstWhere('source_row_number', 351)->decision);
        self::assertNull($preview->rows->firstWhere('source_row_number', 351)->projection_manifest['warehouse_id']);
    }

    public function test_permission_and_migration_contract(): void
    {
        $this->factoryWarehouse();
        [$orderBatch] = $this->appliedOrderBatch([
            [360, 'KOL-PERMISSION', ['TRACK-T'], 'Factory 国内仓'],
        ]);
        $unauthorized = User::factory()->create(['is_active' => true]);
        try {
            $this->service()->preview($orderBatch, $unauthorized);
            self::fail('Expected logistics.operate permission to be required.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $contents = file_get_contents(database_path('migrations/2026_09_07_020000_create_historical_rescue_attempt_projection_tables.php'));
        self::assertIsString($contents);
        preg_match_all("/'(log_hist_rap_[^']+)'/", $contents, $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $name) {
            self::assertLessThanOrEqual(64, strlen($name), $name);
        }
    }

    private function service(): HistoricalRescueShipmentAttemptProjectionService
    {
        return app(HistoricalRescueShipmentAttemptProjectionService::class);
    }

    private function orderService(): HistoricalRescueOrderProjectionService
    {
        return app(HistoricalRescueOrderProjectionService::class);
    }

    private function appliedOrderBatch(array $definitions): array
    {
        $this->product('TL278A');
        $batch = $this->batch();
        foreach ($definitions as [$row, $orderNo, $trackings, $warehouse]) {
            $this->rescueRow($batch, $row, $orderNo, $trackings, $warehouse);
        }
        return $this->applyOrderBatch($batch);
    }

    private function applyOrderBatch(HistoricalImportBatch $batch): array
    {
        $operator = $this->operator();
        $preview = $this->orderService()->preview($batch, $operator);
        config()->set('logistics.historical_rescue_order_apply_enabled', true);
        $applied = $this->orderService()->apply($preview, $operator);
        config()->set('logistics.historical_rescue_order_apply_enabled', false);
        return [$applied, $operator];
    }

    private function product(string $sku): Product
    {
        return Product::create(['source_type' => 'manual', 'sku' => $sku, 'name' => 'Fixture '.$sku, 'is_active' => true]);
    }

    private function batch(): HistoricalImportBatch
    {
        return HistoricalImportBatch::create([
            'original_filename' => 'rescue-attempt-history.xlsx',
            'source_checksum' => hash('sha256', uniqid('rescue-attempt', true)),
            'status' => 'committed',
            'total_rows' => 1077,
            'ready_count' => 788,
            'review_count' => 144,
            'skip_count' => 145,
        ]);
    }

    private function rescueRow(HistoricalImportBatch $batch, int $rowNumber, string $orderNo, array $trackings, string $warehouse): HistoricalImportRow
    {
        $kol = $this->kol();
        $row = HistoricalImportRow::create([
            'batch_id' => $batch->id,
            'source_row_number' => $rowNumber,
            'source_order_no' => $orderNo,
            'kol_id' => $kol->id,
            'source_warehouse_raw' => $warehouse,
            'source_carrier_raw' => 'Fixture Agent',
            'source_sku_raw' => 'TL278A',
            'source_quantity_raw' => '1',
            'source_input_time_raw' => '2026-08-01',
            'source_tracking_raw' => implode(' ', $trackings),
            'classification' => 'review',
            'classification_reason' => 'multiple_tracking_candidates',
            'evidence_hash' => hash('sha256', $batch->id.'|row|'.$rowNumber),
        ]);

        foreach (array_values($trackings) as $index => $tracking) {
            $hash = hash('sha256', $batch->id.'|'.$rowNumber.'|'.$tracking);
            HistoricalEvidence::create([
                'batch_id' => $batch->id,
                'historical_row_id' => $row->id,
                'source_row_number' => $rowNumber,
                'kol_id' => $kol->id,
                'source_checksum' => $batch->source_checksum,
                'normalized_tracking_candidate' => $tracking,
                'evidence_hash' => $hash,
                'evidence_snapshot' => [
                    'source_row_number' => $rowNumber,
                    'source_order_no' => $orderNo,
                    'kol_id' => $kol->id,
                    'source_warehouse_raw' => $warehouse,
                    'source_carrier_raw' => 'Fixture Agent',
                    'source_sku_raw' => 'TL278A',
                    'source_quantity_raw' => '1',
                    'source_input_time_raw' => '2026-08-01',
                    'normalized_tracking_candidate' => $tracking,
                    'rescue' => [
                        'version' => 'historical-review-rescue-v1',
                        'original_classification' => 'review',
                        'original_reason' => 'multiple_tracking_candidates',
                        'leg_index' => $index + 1,
                        'leg_count' => count($trackings),
                        'all_tracking_candidates' => array_values($trackings),
                    ],
                ],
            ]);
        }
        return $row;
    }

    private function factoryWarehouse(): Warehouse
    {
        return Warehouse::query()->where('code', 'FACTORY')->first()
            ?? Warehouse::create([
                'slug' => 'fixture-factory-'.uniqid(),
                'code' => 'FACTORY',
                'name' => 'Fixture Factory',
                'country_code' => 'CN',
                'is_active' => true,
            ]);
    }

    private function kol(): Kol
    {
        return Kol::create([
            'display_name' => 'Rescue Attempt KOL '.uniqid(),
            'status' => 'active',
            'relationship_stage' => 'prospect',
        ]);
    }

    private function existingOrder(string $externalOrderNo): Order
    {
        return Order::create([
            'internal_order_number' => 'FIXTURE-'.uniqid(),
            'source_type' => 'kol',
            'order_kind' => 'kol_fulfillment',
            'external_order_number' => $externalOrderNo,
            'order_date' => '2026-08-01',
            'currency' => 'USD',
            'order_status' => 'pending_shipment',
        ]);
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'historical_rescue_attempt_'.uniqid(), 'label' => 'Historical rescue attempt']);
        foreach (['logistics.view', 'logistics.operate'] as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
