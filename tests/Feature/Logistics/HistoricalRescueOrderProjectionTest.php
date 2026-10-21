<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalRescueOrderProjectionService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HistoricalRescueOrderProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_groups_multiple_rescue_legs_into_one_order_projection_row(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();        $this->rescueRow($batch, 200, 'KOL26010101-007', ['TRACK-A', 'TRACK-B']);

        $beforeOrders = Order::count();
        $beforeAttempts = ShipmentAttempt::count();
        $preview = $this->service()->preview($batch, $this->operator());

        self::assertSame(1, $preview->total_rows);
        self::assertSame(1, $preview->ready_count);
        self::assertSame(0, $preview->review_count);
        self::assertCount(1, $preview->rows);
        self::assertSame(2, $preview->rows->first()->projection_manifest['tracking_leg_count']);
        self::assertSame(['TRACK-A', 'TRACK-B'], $preview->rows->first()->projection_manifest['tracking_candidates']);
        self::assertSame($beforeOrders, Order::count());
        self::assertSame($beforeAttempts, ShipmentAttempt::count());
        self::assertSame(0, DB::table('shipments')->count());
    }

    public function test_preview_rejects_order_number_duplicated_anywhere_in_full_import_batch(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $this->rescueRow($batch, 210, 'KOL-DUP-FULL', ['TRACK-C']);
        $this->plainRow($batch, 211, 'KOL-DUP-FULL');

        $preview = $this->service()->preview($batch, $this->operator());

        self::assertSame(0, $preview->ready_count);
        self::assertSame(1, $preview->review_count);
        self::assertSame('duplicate_source_order_no', $preview->rows->first()->decision_reason);
    }
    public function test_preview_rejects_tracking_conflicts_and_cross_source_duplicates(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $this->rescueRow($batch, 220, 'KOL-TRACK-CONFLICT', ['TRACK-D']);
        ShipmentAttempt::create([
            'order_id' => $this->existingOrder('EXISTING-TRACK')->id,
            'shipment_id' => null,
            'attempt_number' => 1,
            'shipment_status' => 'historical_evidence',
            'tracking_number' => 'TRACK-D',
            'snapshotted_at' => now(),
        ]);

        $preview = $this->service()->preview($batch, $this->operator());
        self::assertSame('tracking_conflict_shipment_attempt', $preview->rows->first()->decision_reason);

        $batch2 = $this->batch();
        $this->rescueRow($batch2, 230, 'KOL-CROSS-A', ['TRACK-SAME']);
        $this->rescueRow($batch2, 231, 'KOL-CROSS-B', ['TRACK-SAME']);
        $preview2 = $this->service()->preview($batch2, $this->operator());

        self::assertSame(0, $preview2->ready_count);
        self::assertSame(['tracking_cross_source_conflict'], $preview2->rows->pluck('decision_reason')->unique()->values()->all());
    }

    public function test_preview_rollover_requires_exact_anchor_and_replays_materialization_with_2025_date(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $this->plainRow($batch, 78, 'KOL010101', '2026-01-01');
        $this->rescueRow($batch, 24, 'KOL120911', ['TRACK-E', 'TRACK-F'], date: '2026-12-09');
        $preview = $this->service()->preview($batch, $this->operator());
        $row = $preview->rows->first();
        self::assertSame('ready', $row->decision);
        self::assertSame('rollover_2025', $row->projection_manifest['materialization_mode']);
        self::assertSame('2025-12-09', $row->projection_manifest['order_date']);

        $batch2 = $this->batch();
        $this->plainRow($batch2, 78, 'KOL010101', '2026-01-02');
        $this->rescueRow($batch2, 24, 'KOL120911', ['TRACK-G'], date: '2026-12-09');
        $preview2 = $this->service()->preview($batch2, $this->operator());
        self::assertSame('order_date_future_requires_review', $preview2->rows->first()->decision_reason);
    }

    public function test_preview_keeps_sku_and_quantity_failures_in_review(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $this->rescueRow($batch, 240, 'KOL-BAD-SKU', ['TRACK-H'], sku: 'UNKNOWN-SKU');
        $this->rescueRow($batch, 241, 'KOL-BAD-QTY', ['TRACK-I'], qty: '0');

        $preview = $this->service()->preview($batch, $this->operator());
        self::assertSame(0, $preview->ready_count);
        self::assertSame(2, $preview->review_count);
        self::assertSame(
            ['quantity_not_positive_integer', 'sku_unmatched_or_invalid'],
            $preview->rows->pluck('decision_reason')->sort()->values()->all(),
        );
    }

    public function test_preview_is_idempotent_and_apply_gate_defaults_off(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $this->rescueRow($batch, 250, 'KOL-IDEMPOTENT', ['TRACK-J']);        $operator = $this->operator();
        $service = $this->service();
        $first = $service->preview($batch, $operator);
        $audit = DB::table('audit_records')->where('action', 'logistics.historical_rescue_order_projection.previewed')->count();
        $second = $service->preview($batch, $operator);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, $second->rows->count());
        self::assertSame($audit, DB::table('audit_records')->where('action', 'logistics.historical_rescue_order_projection.previewed')->count());

        try {
            $service->apply($second, $operator);
            self::fail('Expected rescue order apply gate to be disabled.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(0, Order::query()->where('external_order_number', 'KOL-IDEMPOTENT')->count());
    }

    public function test_apply_creates_only_one_order_per_source_row_and_is_idempotent(): void
    {
        $product = $this->product('TL278A');
        $batch = $this->batch();
        $this->rescueRow($batch, 260, 'KOL-APPLY', ['TRACK-K', 'TRACK-L'], qty: '2');
        $operator = $this->operator();
        $preview = $this->service()->preview($batch, $operator);
        config()->set('logistics.historical_rescue_order_apply_enabled', true);
        $attemptsBefore = ShipmentAttempt::count();
        $shipmentsBefore = DB::table('shipments')->count();

        $applied = $this->service()->apply($preview, $operator);
        $order = Order::query()->where('external_order_number', 'KOL-APPLY')->firstOrFail();
        self::assertSame('applied', $applied->status);
        self::assertSame(1, $applied->applied_count);
        self::assertSame($product->id, $order->items->first()->product_id);
        self::assertSame(2, $order->items->first()->quantity);        self::assertSame($preview->rows->first()->projection_manifest['erp_order_id'], $order->erp_order_id);
        self::assertSame(1, $order->externalReferences()->where('provider', 'historical_logistics')->count());
        self::assertSame($attemptsBefore, ShipmentAttempt::count());
        self::assertSame($shipmentsBefore, DB::table('shipments')->count());

        $ordersAfter = Order::count();
        $refsAfter = DB::table('order_external_references')->where('provider', 'historical_logistics')->count();
        $auditAfter = DB::table('audit_records')->where('action', 'logistics.historical_rescue_order_projection.applied')->count();
        $this->service()->apply($applied, $operator);
        self::assertSame($ordersAfter, Order::count());
        self::assertSame($refsAfter, DB::table('order_external_references')->where('provider', 'historical_logistics')->count());
        self::assertSame($auditAfter, DB::table('audit_records')->where('action', 'logistics.historical_rescue_order_projection.applied')->count());
        self::assertSame($attemptsBefore, ShipmentAttempt::count());
    }

    public function test_apply_aborts_whole_transaction_when_frozen_identity_drifts(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $this->rescueRow($batch, 270, 'KOL-ROLLBACK-A', ['TRACK-M']);
        $this->rescueRow($batch, 271, 'KOL-ROLLBACK-B', ['TRACK-N']);
        $operator = $this->operator();
        $preview = $this->service()->preview($batch, $operator);
        $frozen = $preview->rows->last()->projection_manifest['erp_order_id'];
        $this->existingOrder('IDENTITY-COLLISION', $frozen);
        config()->set('logistics.historical_rescue_order_apply_enabled', true);

        try {
            $this->service()->apply($preview, $operator);
            self::fail('Expected frozen ERP identity drift to abort apply.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('batch', $exception->errors());
        }

        self::assertSame(0, Order::query()->whereIn('external_order_number', ['KOL-ROLLBACK-A', 'KOL-ROLLBACK-B'])->count());
        self::assertSame(0, DB::table('order_external_references')->where('provider', 'historical_logistics')->count());
    }
    public function test_preview_rejects_exact_external_reference_and_requires_permission(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $this->rescueRow($batch, 280, 'KOL-EXTERNAL-REF', ['TRACK-O']);
        $order = $this->existingOrder('EXTERNAL-OWNER');
        $order->externalReferences()->create([
            'provider' => 'historical_logistics',
            'connection_key' => 'historical:'.$batch->id.':'.substr($batch->source_checksum, 0, 32),
            'reference' => 'KOL-EXTERNAL-REF',
        ]);

        $preview = $this->service()->preview($batch, $this->operator());
        self::assertSame('exact_external_reference_exists', $preview->rows->first()->decision_reason);

        $unauthorized = User::factory()->create(['is_active' => true]);
        try {
            $this->service()->preview($this->batch(), $unauthorized);
            self::fail('Expected logistics.operate permission to be required.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_preview_uses_deterministic_suffix_when_base_erp_identity_is_taken(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $row = $this->rescueRow($batch, 290, 'KOL-SUFFIX', ['TRACK-P']);
        $base = app(\App\Modules\Orders\Application\OrderIdentityService::class)
            ->previewCandidate('k', $row->kol_id, '2026-08-01');
        $this->existingOrder('BASE-COLLISION', $base);

        $preview = $this->service()->preview($batch, $this->operator());

        self::assertSame(1, $preview->ready_count);
        self::assertSame($base.'A', $preview->rows->first()->projection_manifest['erp_order_id']);
    }

    public function test_preview_reviews_when_all_erp_identity_suffixes_are_exhausted(): void
    {
        $this->product('TL278A');
        $batch = $this->batch();
        $row = $this->rescueRow($batch, 291, 'KOL-EXHAUST', ['TRACK-Q']);
        $identities = app(\App\Modules\Orders\Application\OrderIdentityService::class);
        foreach (array_merge([null], range('A', 'Z')) as $suffix) {
            $this->existingOrder('COLLISION-'.($suffix ?? 'BASE'), $identities->previewCandidate('k', $row->kol_id, '2026-08-01', $suffix));
        }

        $preview = $this->service()->preview($batch, $this->operator());

        self::assertSame(0, $preview->ready_count);
        self::assertSame(1, $preview->review_count);
        self::assertSame('erp_order_identity_exhausted', $preview->rows->first()->decision_reason);
    }

    public function test_projection_migration_uses_mysql_safe_constraint_names(): void
    {
        $path = database_path('migrations/2026_09_06_153000_create_historical_rescue_order_projection_tables.php');
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        preg_match_all("/'(log_hist_rop_[^']+)'/", $contents, $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $name) self::assertLessThanOrEqual(64, strlen($name), $name);
    }

    private function service(): HistoricalRescueOrderProjectionService
    {
        return app(HistoricalRescueOrderProjectionService::class);
    }

    private function product(string $sku): Product
    {
        return Product::create(['source_type' => 'manual', 'sku' => $sku, 'name' => 'Fixture '.$sku, 'is_active' => true]);
    }
    private function batch(): HistoricalImportBatch
    {
        return HistoricalImportBatch::create([
            'original_filename' => 'rescue-history.xlsx',
            'source_checksum' => hash('sha256', uniqid('rescue', true)),
            'status' => 'committed',
            'total_rows' => 1077,
            'ready_count' => 788,
            'review_count' => 144,
            'skip_count' => 145,
        ]);
    }

    private function plainRow(HistoricalImportBatch $batch, int $rowNumber, string $orderNo, string $date = '2026-08-01'): HistoricalImportRow
    {
        return HistoricalImportRow::create([
            'batch_id' => $batch->id,
            'source_row_number' => $rowNumber,
            'source_order_no' => $orderNo,
            'kol_id' => $this->kol()->id,
            'source_sku_raw' => 'TL278A',
            'source_quantity_raw' => '1',
            'source_input_time_raw' => $date,
            'classification' => 'ready',
            'classification_reason' => 'fixture',
            'evidence_hash' => hash('sha256', $batch->id.'|plain|'.$rowNumber),
        ]);
    }

    private function rescueRow(
        HistoricalImportBatch $batch,
        int $rowNumber,
        string $orderNo,
        array $trackings,
        string $sku = 'TL278A',        string $qty = '1',
        string $date = '2026-08-01',
        string $reason = 'multiple_tracking_candidates',
    ): HistoricalImportRow {
        $kol = $this->kol();
        $row = HistoricalImportRow::create([
            'batch_id' => $batch->id,
            'source_row_number' => $rowNumber,
            'source_order_no' => $orderNo,
            'kol_id' => $kol->id,
            'source_sku_raw' => $sku,
            'source_quantity_raw' => $qty,
            'source_input_time_raw' => $date,
            'source_tracking_raw' => implode(' ', $trackings),
            'classification' => 'review',
            'classification_reason' => $reason,
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
                'evidence_snapshot' => [                    'source_row_number' => $rowNumber,
                    'source_order_no' => $orderNo,
                    'kol_id' => $kol->id,
                    'source_sku_raw' => $sku,
                    'source_quantity_raw' => $qty,
                    'source_input_time_raw' => $date,
                    'normalized_tracking_candidate' => $tracking,
                    'rescue' => [
                        'version' => 'historical-review-rescue-v1',
                        'original_classification' => 'review',
                        'original_reason' => $reason,
                        'leg_index' => $index + 1,
                        'leg_count' => count($trackings),
                        'all_tracking_candidates' => array_values($trackings),
                    ],
                ],
            ]);
        }
        return $row;
    }

    private function kol(): Kol
    {
        return Kol::create([
            'display_name' => 'Rescue KOL '.uniqid(),
            'status' => 'active',
            'relationship_stage' => 'prospect',
        ]);
    }
    private function existingOrder(string $externalOrderNo, ?string $erpOrderId = null): Order
    {
        return Order::create([
            'internal_order_number' => 'FIXTURE-'.uniqid(),
            'erp_order_id' => $erpOrderId,
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
        $role = Role::create(['key' => 'historical_rescue_'.uniqid(), 'label' => 'Historical rescue']);
        foreach (['logistics.view', 'logistics.operate'] as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
