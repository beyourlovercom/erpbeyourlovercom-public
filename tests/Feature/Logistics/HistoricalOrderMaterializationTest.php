<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalOrderMaterializationService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HistoricalOrderMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_requires_committed_evidence(): void
    {
        $batch = HistoricalImportBatch::create([
            'original_filename' => 'history.xlsx',
            'source_checksum' => str_repeat('a', 64),
            'status' => 'preview',
        ]);

        $this->expectException(ValidationException::class);
        app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());
    }

    public function test_preview_resolves_whole_canonical_and_approved_alias_before_token_fallback(): void
    {
        $canonical = $this->product('TL278A');
        $aliasProduct = $this->product('VX007H');
        ProductSkuAlias::create([
            'product_id' => $aliasProduct->id,
            'alias_sku' => 'VX007-OLD',
            'match_key' => SkuMatchKey::compute('VX007-OLD'),
            'source_type' => 'test',
            'source_reference' => 'fixture',
        ]);

        $batch = $this->committedBatch([
            ['sku' => 'TL278A', 'qty' => '2', 'order' => 'KOL-EXACT'],
            ['sku' => 'VX007-OLD', 'qty' => '1', 'order' => 'KOL-ALIAS'],
        ]);
        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());

        self::assertSame(2, $preview->ready_count);
        self::assertSame(0, $preview->review_count);
        self::assertSame($canonical->id, $preview->rows[0]->materialization_manifest['items'][0]['product_id']);
        self::assertSame($aliasProduct->id, $preview->rows[1]->materialization_manifest['items'][0]['product_id']);
        self::assertSame('VX007H', $preview->rows[1]->materialization_manifest['items'][0]['sku']);
        $firstErpId = $preview->rows[0]->materialization_manifest['erp_order_id'];
        $secondErpId = $preview->rows[1]->materialization_manifest['erp_order_id'];
        self::assertNotSame('', $firstErpId);
        self::assertSame($firstErpId.'A', $secondErpId);
    }

    public function test_description_suffix_and_chinese_parenthesis_are_deterministic_token_fallbacks(): void
    {
        $product = $this->product('TL278A');
        $batch = $this->committedBatch([
            ['sku' => 'TL278A可拆分吮吸伸缩震动加热棒', 'qty' => '1', 'order' => 'KOL-DESC'],
            ['sku' => 'TL278A（内含说明）', 'qty' => '1', 'order' => 'KOL-PAREN'],
        ]);

        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());

        self::assertSame(2, $preview->ready_count);
        self::assertSame($product->id, $preview->rows[0]->materialization_manifest['items'][0]['product_id']);
        self::assertSame($product->id, $preview->rows[1]->materialization_manifest['items'][0]['product_id']);
    }

    public function test_multi_sku_requires_every_token_and_explicit_per_item_one_quantity(): void
    {
        $a = $this->product('VA617A');
        $b = $this->product('VX007H');
        $batch = $this->committedBatch([
            ['sku' => "VA617A\nVX007H", 'qty' => "各1\n共2", 'order' => 'KOL-MULTI-OK'],
            ['sku' => "VA617A\nVX007H", 'qty' => '共2', 'order' => 'KOL-MULTI-BAD'],
        ]);

        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());

        self::assertSame(1, $preview->ready_count);
        self::assertSame(1, $preview->review_count);
        self::assertSame([$a->id, $b->id], array_column($preview->rows[0]->materialization_manifest['items'], 'product_id'));
        self::assertSame([1, 1], array_column($preview->rows[0]->materialization_manifest['items'], 'quantity'));
        self::assertSame('ambiguous_multi_item_quantity', $preview->rows[1]->decision_reason);
    }

    public function test_whitespace_only_segments_between_real_skus_are_ignored(): void
    {
        $a = $this->product('BX033B');
        $b = $this->product('VX357D');
        $batch = $this->committedBatch([
            ['sku' => "BX033B\n  \nVX357D", 'qty' => '各1个', 'order' => 'KOL-WHITESPACE'],
        ]);

        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());

        self::assertSame(1, $preview->ready_count);
        self::assertSame([$a->id, $b->id], array_column($preview->rows->first()->materialization_manifest['items'], 'product_id'));
    }

    public function test_variant_like_or_free_text_suffixes_do_not_fall_back_to_base_sku(): void
    {
        $this->product('TL278A');
        $this->product('VA617A');
        $this->product('VM465A');
        $batch = $this->committedBatch([
            ['sku' => 'TL278A-Black', 'qty' => '1', 'order' => 'KOL-BLACK'],
            ['sku' => 'VA617A-yellow', 'qty' => '1', 'order' => 'KOL-YELLOW'],
            ['sku' => 'VM465A RED', 'qty' => '1', 'order' => 'KOL-RED'],
        ]);

        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());

        self::assertSame(0, $preview->ready_count);
        self::assertSame(3, $preview->review_count);
        self::assertSame(['sku_unmatched_or_invalid'], $preview->rows->pluck('decision_reason')->unique()->values()->all());
    }

    public function test_duplicate_product_tokens_are_review_not_silently_collapsed(): void
    {
        $this->product('VA617A');
        $batch = $this->committedBatch([
            ['sku' => "VA617A\nVA617A", 'qty' => '各1共2', 'order' => 'KOL-DUP-TOKEN'],
        ]);

        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());

        self::assertSame(1, $preview->review_count);
        self::assertSame('duplicate_product_token', $preview->rows->first()->decision_reason);
    }

    public function test_unparseable_and_future_dates_are_review_with_fixed_historical_boundary(): void
    {
        $this->product('TL278A');
        $batch = $this->committedBatch([
            ['sku' => 'TL278A', 'qty' => '1', 'order' => 'KOL-DATE-OK', 'date' => '46357'],
            ['sku' => 'TL278A', 'qty' => '1', 'order' => 'KOL-DATE-BAD', 'date' => 'not-a-date'],
        ]);

        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());

        self::assertSame(0, $preview->ready_count);
        self::assertSame('order_date_future_requires_review', $preview->rows[0]->decision_reason);
        self::assertSame('order_date_unparseable', $preview->rows[1]->decision_reason);
    }

    public function test_preview_external_reference_lookup_is_connection_scoped(): void
    {
        $this->product('TL278A');
        $batch = $this->committedBatch([
            ['sku' => 'TL278A', 'qty' => '1', 'order' => 'KOL-SCOPE'],
        ]);
        $other = Order::create([
            'internal_order_number' => 'KOL-OTHER',
            'source_type' => 'kol',
            'order_kind' => 'kol_fulfillment',
            'external_order_number' => 'OTHER',
            'order_date' => '2026-08-01',
            'currency' => 'USD',
            'order_status' => 'pending_shipment',
        ]);
        $other->externalReferences()->create([
            'provider' => 'historical_logistics',
            'connection_key' => 'different-connection',
            'reference' => 'KOL-SCOPE',
        ]);

        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());

        self::assertSame(1, $preview->ready_count);
        self::assertSame(0, $preview->already_applied_count);
    }

    public function test_apply_gate_defaults_off_and_creates_nothing(): void
    {
        $this->product('TL278A');
        $batch = $this->committedBatch([
            ['sku' => 'TL278A中文描述', 'qty' => '1', 'order' => 'KOL-GATE'],
        ]);
        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $this->operator());
        $ordersBefore = Order::count();
        $shipmentsBefore = DB::table('shipments')->count();

        try {
            app(HistoricalOrderMaterializationService::class)->apply($preview, $this->operator());
            self::fail('Expected the materialization apply gate to block writes.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        self::assertSame($ordersBefore, Order::count());
        self::assertSame($shipmentsBefore, DB::table('shipments')->count());
    }

    public function test_apply_materializes_exact_order_without_inventing_missing_address_and_is_idempotent(): void
    {
        $product = $this->product('TL278A');
        $batch = $this->committedBatch([
            ['sku' => 'TL278A', 'qty' => '2', 'order' => 'KOL-LEGACY-001', 'date' => '2026-08-01'],
        ]);
        $user = $this->operator();
        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $user);
        config()->set('logistics.historical_order_materialization_apply_enabled', true);
        $shipmentsBefore = DB::table('shipments')->count();

        $applied = app(HistoricalOrderMaterializationService::class)->apply($preview, $user);
        $order = Order::query()->where('external_order_number', 'KOL-LEGACY-001')->firstOrFail();

        self::assertSame('applied', $applied->status);
        self::assertSame('kol', $order->source_type->value);
        self::assertSame('kol_fulfillment', $order->order_kind->value);
        self::assertSame('2026-08-01', $order->order_date->toDateString());
        self::assertSame($preview->rows->first()->materialization_manifest['erp_order_id'], $order->erp_order_id);
        self::assertNull($order->address);
        self::assertSame($product->id, $order->items->first()->product_id);
        self::assertSame(2, $order->items->first()->quantity);
        self::assertSame(1, $order->externalReferences()->where('provider', 'historical_logistics')->count());
        self::assertSame($shipmentsBefore, DB::table('shipments')->count());

        $ordersAfterFirst = Order::count();
        $refsAfterFirst = DB::table('order_external_references')->where('provider', 'historical_logistics')->count();
        $applyAuditsAfterFirst = DB::table('audit_records')->where('action', 'logistics.historical_order_materialization.applied')->count();
        app(HistoricalOrderMaterializationService::class)->apply($applied, $user);

        self::assertSame($ordersAfterFirst, Order::count());
        self::assertSame($refsAfterFirst, DB::table('order_external_references')->where('provider', 'historical_logistics')->count());
        self::assertSame($applyAuditsAfterFirst, DB::table('audit_records')->where('action', 'logistics.historical_order_materialization.applied')->count());
        self::assertSame($shipmentsBefore, DB::table('shipments')->count());
    }

    public function test_apply_stops_if_frozen_erp_identity_drifted_after_preview(): void
    {
        $this->product('TL278A');
        $batch = $this->committedBatch([
            ['sku' => 'TL278A', 'qty' => '1', 'order' => 'KOL-IDENTITY-DRIFT', 'date' => '2026-08-01'],
        ]);
        $user = $this->operator();
        $preview = app(HistoricalOrderMaterializationService::class)->preview($batch, $user);
        $frozen = $preview->rows->first()->materialization_manifest['erp_order_id'];
        Order::create([
            'internal_order_number' => 'KOL-COLLISION',
            'erp_order_id' => $frozen,
            'source_type' => 'kol',
            'order_kind' => 'kol_fulfillment',
            'external_order_number' => 'OTHER-COLLISION',
            'order_date' => '2026-08-01',
            'currency' => 'USD',
            'order_status' => 'pending_shipment',
        ]);
        config()->set('logistics.historical_order_materialization_apply_enabled', true);

        try {
            app(HistoricalOrderMaterializationService::class)->apply($preview, $user);
            self::fail('Expected ERP identity drift to stop apply.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('batch', $exception->errors());
        }

        self::assertSame(0, DB::table('order_external_references')->where('provider', 'historical_logistics')->count());
        self::assertSame(0, DB::table('shipments')->count());
    }

    public function test_preview_is_idempotent_and_does_not_duplicate_rows_or_audit(): void
    {
        $this->product('TL278A');
        $batch = $this->committedBatch([
            ['sku' => 'TL278A', 'qty' => '1', 'order' => 'KOL-PREVIEW-IDEM'],
        ]);
        $user = $this->operator();
        $service = app(HistoricalOrderMaterializationService::class);
        $first = $service->preview($batch, $user);
        $auditCount = DB::table('audit_records')->where('action', 'logistics.historical_order_materialization.previewed')->count();
        $second = $service->preview($batch, $user);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, $second->rows->count());
        self::assertSame($auditCount, DB::table('audit_records')->where('action', 'logistics.historical_order_materialization.previewed')->count());
    }

    public function test_materialization_migration_uses_mysql_safe_explicit_constraint_names(): void
    {
        $path = database_path('migrations/2026_09_05_150000_create_historical_order_materialization_tables.php');
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        preg_match_all("/'(log_hist_mat_[^']+)'/", $contents, $matches);
        $names = array_values(array_unique($matches[1]));

        self::assertNotEmpty($names);
        foreach ($names as $name) self::assertLessThanOrEqual(64, strlen($name), $name);
        self::assertStringNotContainsString('->constrained(', $contents);
    }

    private function product(string $sku): Product
    {
        return Product::create([
            'source_type' => 'manual',
            'sku' => $sku,
            'name' => 'Fixture '.$sku,
            'is_active' => true,
        ]);
    }

    /** @param list<array{sku:string,qty:string,order:string,date?:string}> $definitions */
    private function committedBatch(array $definitions): HistoricalImportBatch
    {
        $batch = HistoricalImportBatch::create([
            'original_filename' => 'history.xlsx',
            'source_checksum' => hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR)),
            'status' => 'committed',
            'total_rows' => count($definitions),
            'ready_count' => count($definitions),
        ]);
        $kol = Kol::create([
            'display_name' => 'Historical KOL '.uniqid(),
            'status' => 'active',
            'relationship_stage' => 'prospect',
        ]);

        foreach ($definitions as $index => $definition) {
            $rowNumber = $index + 2;
            $hash = hash('sha256', $batch->id.'|'.$rowNumber.'|'.$definition['order']);
            $row = HistoricalImportRow::create([
                'batch_id' => $batch->id,
                'source_row_number' => $rowNumber,
                'source_order_no' => $definition['order'],
                'kol_id' => $kol->id,
                'source_sku_raw' => $definition['sku'],
                'source_quantity_raw' => $definition['qty'],
                'source_input_time_raw' => $definition['date'] ?? '2026-08-01',
                'classification' => 'ready',
                'classification_reason' => 'fixture',
                'evidence_hash' => $hash,
            ]);
            HistoricalEvidence::create([
                'batch_id' => $batch->id,
                'historical_row_id' => $row->id,
                'source_row_number' => $rowNumber,
                'kol_id' => $kol->id,
                'source_checksum' => $batch->source_checksum,
                'normalized_tracking_candidate' => 'TRACK-'.$rowNumber,
                'evidence_hash' => $hash,
                'evidence_snapshot' => [
                    'source_order_no' => $definition['order'],
                    'source_sku_raw' => $definition['sku'],
                    'source_quantity_raw' => $definition['qty'],
                    'source_input_time_raw' => $definition['date'] ?? '2026-08-01',
                ],
            ]);
        }

        return $batch->fresh();
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'historical_materialization_'.uniqid(), 'label' => 'Historical materialization']);
        foreach (['logistics.view', 'logistics.operate'] as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
