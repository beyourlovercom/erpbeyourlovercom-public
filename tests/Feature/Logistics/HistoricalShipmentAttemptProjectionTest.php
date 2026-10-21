<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\EloquentInventoryRepository;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalShipmentAttemptProjectionService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationRow;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class HistoricalShipmentAttemptProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_is_off_and_preview_is_idempotent(): void
    {
        $f = $this->fixture();
        $service = app(HistoricalShipmentAttemptProjectionService::class);
        $first = $service->preview($f['batch'], $f['user']);
        $auditCount = $this->auditCount('logistics.historical_shipment_attempt_projection.previewed');
        $second = $service->preview($f['batch'], $f['user']);

        self::assertFalse((bool) config('logistics.historical_shipment_attempt_apply_enabled'));
        self::assertSame($first->id, $second->id);
        self::assertSame(1, $second->ready_count);
        self::assertSame(1, $second->rows()->count());
        self::assertSame($auditCount, $this->auditCount('logistics.historical_shipment_attempt_projection.previewed'));

        $this->expectException(HttpException::class);
        $service->apply($first, $f['user']);
    }

    public function test_preview_freezes_exact_and_unresolved_warehouse_semantics_without_carrier_authority(): void
    {
        $exact = $this->fixture('USA 美西仓', '速达');
        $exactRow = app(HistoricalShipmentAttemptProjectionService::class)->preview($exact['batch'], $exact['user'])->rows->firstOrFail();
        self::assertSame('ready', $exactRow->decision);
        self::assertSame('exact', $exactRow->projection_manifest['warehouse_resolution']);
        self::assertSame('US-LA', $exactRow->projection_manifest['warehouse_code']);
        self::assertNotNull($exactRow->projection_manifest['warehouse_id']);
        self::assertSame('historical_exact', $exactRow->projection_manifest['warehouse_selection_source']);
        self::assertSame('速达', $exactRow->projection_manifest['raw_agent']);
        self::assertNull($exactRow->projection_manifest['carrier']);
        self::assertNull($exactRow->projection_manifest['service']);
        self::assertNull($exactRow->projection_manifest['shipped_at']);
        self::assertSame('historical-shipment-attempt-projection-v1', $exactRow->projection_manifest['version']);

        $unresolved = $this->fixture('Europe 欧洲仓', '佰事达');
        $unresolvedRow = app(HistoricalShipmentAttemptProjectionService::class)->preview($unresolved['batch'], $unresolved['user'])->rows->firstOrFail();
        self::assertSame('ready', $unresolvedRow->decision);
        self::assertSame('unresolved', $unresolvedRow->projection_manifest['warehouse_resolution']);
        self::assertNull($unresolvedRow->projection_manifest['warehouse_code']);
        self::assertNull($unresolvedRow->projection_manifest['warehouse_id']);
        self::assertNull($unresolvedRow->projection_manifest['warehouse_selection_source']);
        self::assertSame('Europe 欧洲仓', $unresolvedRow->projection_manifest['raw_warehouse']);
        self::assertSame('佰事达', $unresolvedRow->projection_manifest['raw_agent']);
    }

    public function test_missing_exact_warehouse_master_requires_review_instead_of_false_exact_authority(): void
    {
        $f = $this->fixture('USA 美西仓');
        Warehouse::query()->where('code', 'US-LA')->delete();
        $row = app(HistoricalShipmentAttemptProjectionService::class)->preview($f['batch'], $f['user'])->rows->firstOrFail();

        self::assertSame('review', $row->decision);
        self::assertSame('warehouse_master_missing', $row->decision_reason);
        self::assertSame('master_missing', $row->projection_manifest['warehouse_resolution']);
        self::assertNull($row->projection_manifest['warehouse_id']);
        self::assertNull($row->projection_manifest['warehouse_selection_source']);
    }

    public function test_tracking_conflict_with_active_shipment_requires_review(): void
    {
        $f = $this->fixture();
        $other = $this->order('OTHER');
        $other->shipment()->create(['shipment_status' => 'shipped', 'tracking_number' => $f['tracking'], 'shipped_at' => now()]);
        $row = app(HistoricalShipmentAttemptProjectionService::class)->preview($f['batch'], $f['user'])->rows->firstOrFail();

        self::assertSame('review', $row->decision);
        self::assertSame('tracking_conflict_active_shipment', $row->decision_reason);
    }

    public function test_preexisting_attempt_on_same_order_requires_review(): void
    {
        $f = $this->fixture();
        ShipmentAttempt::query()->create([
            'order_id' => $f['order']->id, 'shipment_id' => null, 'attempt_number' => 1,
            'shipment_status' => 'exception', 'tracking_number' => 'OTHER-'.$f['tracking'],
            'snapshotted_at' => now(),
        ]);
        $row = app(HistoricalShipmentAttemptProjectionService::class)->preview($f['batch'], $f['user'])->rows->firstOrFail();

        self::assertSame('review', $row->decision);
        self::assertSame('preexisting_shipment_attempt', $row->decision_reason);
    }

    public function test_active_shipment_on_same_order_requires_review_even_with_different_tracking(): void
    {
        $f = $this->fixture();
        $f['order']->shipment()->create(['shipment_status' => 'shipped', 'tracking_number' => 'OTHER-'.$f['tracking'], 'shipped_at' => now()]);
        $row = app(HistoricalShipmentAttemptProjectionService::class)->preview($f['batch'], $f['user'])->rows->firstOrFail();

        self::assertSame('review', $row->decision);
        self::assertSame('active_shipment', $row->decision_reason);
    }

    public function test_apply_aborts_whole_batch_when_materialization_source_drifts(): void
    {
        $f = $this->fixture();
        $service = app(HistoricalShipmentAttemptProjectionService::class);
        $preview = $service->preview($f['batch'], $f['user']);
        $f['materializationRow']->forceFill(['decision' => 'review'])->save();
        config()->set('logistics.historical_shipment_attempt_apply_enabled', true);

        try {
            $service->apply($preview, $f['user']);
            self::fail('Expected frozen-source drift to abort apply.');
        } catch (ValidationException $e) {
            self::assertSame(0, ShipmentAttempt::query()->count());
            self::assertSame('preview', $preview->fresh()->status);
            self::assertSame(0, $this->auditCount('logistics.historical_shipment_attempt_projection.applied'));
        }
    }

    public function test_apply_accepts_semantically_identical_manifest_with_reordered_json_keys(): void
    {
        $f = $this->fixture();
        $service = app(HistoricalShipmentAttemptProjectionService::class);
        $preview = $service->preview($f['batch'], $f['user']);
        $row = $preview->rows()->firstOrFail();
        $row->forceFill(['projection_manifest' => array_reverse($row->projection_manifest, true)])->save();
        config()->set('logistics.historical_shipment_attempt_apply_enabled', true);

        $applied = $service->apply($preview->fresh(), $f['user']);

        self::assertSame('applied', $applied->status);
        self::assertSame(1, ShipmentAttempt::query()->count());
    }

    public function test_apply_creates_only_historical_attempt_is_idempotent_and_inventory_demand_stays_zero(): void
    {
        $f = $this->fixture('USA 美西仓', '速达');
        $product = Product::query()->create(['sku' => 'HIST-AT-'.$f['suffix'], 'name' => 'Historical Attempt Product']);
        $f['order']->items()->create(['product_id' => $product->id, 'product_summary' => $product->name, 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => '0.00', 'line_revenue' => '0.00']);
        $warehouse = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $service = app(HistoricalShipmentAttemptProjectionService::class);
        $preview = $service->preview($f['batch'], $f['user']);
        config()->set('logistics.historical_shipment_attempt_apply_enabled', true);
        $before = [
            'orders' => Order::query()->count(),
            'shipments' => DB::table('shipments')->count(),
            'reviews' => DB::table('logistics_reviews')->count(),
            'manual_syncs' => DB::table('manual_syncs')->count(),
            'reservations' => DB::table('inventory_reservations')->count(),
        ];

        $applied = $service->apply($preview, $f['user']);
        $attempt = ShipmentAttempt::query()->firstOrFail();
        self::assertSame('applied', $applied->status);
        self::assertSame(1, $applied->applied_count);
        self::assertSame($f['order']->id, $attempt->order_id);
        self::assertNull($attempt->shipment_id);
        self::assertSame(1, $attempt->attempt_number);
        self::assertSame('historical_evidence', $attempt->shipment_status);
        self::assertSame('US-LA', $attempt->warehouse->code);
        self::assertSame('historical_exact', $attempt->warehouse_selection_source);
        self::assertNull($attempt->carrier);
        self::assertNull($attempt->service);
        self::assertNull($attempt->carrier_selection_source);
        self::assertSame($f['tracking'], $attempt->tracking_number);
        self::assertNull($attempt->shipped_at);
        self::assertNull($attempt->handled_by);
        self::assertSame($f['user']->id, $attempt->snapshotted_by);
        self::assertSame('速达', data_get($attempt->evidence_snapshot, 'projection_manifest.raw_agent'));
        self::assertSame('pending_shipment', $f['order']->fresh()->order_status->value);
        self::assertSame($before['orders'], Order::query()->count());
        self::assertSame($before['shipments'], DB::table('shipments')->count());
        self::assertSame($before['reviews'], DB::table('logistics_reviews')->count());
        self::assertSame($before['manual_syncs'], DB::table('manual_syncs')->count());
        self::assertSame($before['reservations'], DB::table('inventory_reservations')->count());

        $detail = app(EloquentInventoryRepository::class)->warehouseDetail($warehouse);
        $inventory = $detail->inventories->firstWhere('product_id', $product->id);
        self::assertSame(0, (int) $inventory->getAttribute('shipped_90d_qty'));

        $auditCount = $this->auditCount('logistics.historical_shipment_attempt_projection.applied');
        $service->apply($preview, $f['user']);
        self::assertSame(1, ShipmentAttempt::query()->count());
        self::assertSame($auditCount, $this->auditCount('logistics.historical_shipment_attempt_projection.applied'));
    }

    public function test_projection_migration_uses_explicit_short_mysql_names(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_05_180000_create_historical_shipment_attempt_projection_tables.php'));
        self::assertIsString($source);
        self::assertStringNotContainsString('->constrained(', $source);
        preg_match_all("/'(log_hist_atp_[^']+)'/", $source, $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        self::assertNotEmpty($names);
        foreach ($names as $name) self::assertLessThanOrEqual(64, strlen($name), $name);
    }

    private function fixture(string $warehouse = 'USA 美西仓', string $agent = 'Agent Carrier', ?string $tracking = null): array
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $tracking ??= 'TRACK-'.$suffix;
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['key' => 'hist-attempt-'.$suffix, 'label' => 'Historical attempt']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => 'logistics.operate'], ['label' => 'logistics.operate']));
        $user->roles()->attach($role);
        $checksum = hash('sha256', 'source-'.$suffix);
        $import = HistoricalImportBatch::query()->create(['original_filename' => 'history.xlsx', 'source_checksum' => $checksum, 'status' => 'committed']);
        $kol = Kol::query()->create(['display_name' => 'Historical KOL '.$suffix, 'status' => 'active', 'relationship_stage' => 'prospect']);
        $source = HistoricalImportRow::query()->create([
            'batch_id' => $import->id, 'source_row_number' => 2, 'source_order_no' => 'HIST-'.$suffix, 'kol_id' => $kol->id,
            'source_warehouse_raw' => $warehouse, 'source_carrier_raw' => $agent, 'source_tracking_raw' => $tracking,
            'normalized_tracking_candidate' => $tracking, 'classification' => 'ready', 'classification_reason' => 'fixture',
            'evidence_hash' => hash('sha256', 'evidence-'.$suffix),
        ]);
        $evidence = HistoricalEvidence::query()->create([
            'batch_id' => $import->id, 'historical_row_id' => $source->id, 'source_row_number' => 2, 'kol_id' => $kol->id,
            'source_checksum' => $checksum, 'normalized_tracking_candidate' => $tracking, 'evidence_hash' => $source->evidence_hash,
            'evidence_snapshot' => ['source_warehouse_raw' => $warehouse, 'source_carrier_raw' => $agent, 'source_order_no' => 'HIST-'.$suffix],
        ]);
        $order = $this->order($suffix, 'HIST-'.$suffix);
        $materialization = HistoricalOrderMaterializationBatch::query()->create([
            'historical_import_batch_id' => $import->id, 'source_checksum' => $checksum, 'status' => 'applied', 'total_rows' => 1, 'ready_count' => 1,
        ]);
        $materializationRow = HistoricalOrderMaterializationRow::query()->create([
            'materialization_batch_id' => $materialization->id, 'historical_evidence_id' => $evidence->id, 'source_row_number' => 2,
            'source_order_no' => 'HIST-'.$suffix, 'decision' => 'applied', 'decision_reason' => 'fixture', 'materialization_manifest' => [], 'order_id' => $order->id,
        ]);

        return compact('suffix', 'tracking', 'user', 'import', 'source', 'evidence', 'order', 'materializationRow') + ['batch' => $materialization->fresh()];
    }

    private function order(string $suffix, ?string $external = null): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'HIST-ERP-'.$suffix, 'source_type' => 'kol', 'order_kind' => 'kol_fulfillment',
            'external_order_number' => $external ?? 'ORDER-'.$suffix, 'order_date' => '2026-08-01', 'currency' => 'USD', 'order_status' => 'pending_shipment',
        ]);
    }

    private function auditCount(string $action): int
    {
        return DB::table('audit_records')->where('action', $action)->count();
    }
}
