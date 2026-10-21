<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\AccountingContinuousDeltaService;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLotConsumption;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseStorageCostImportService;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Logistics\Application\Contracts\OverseasHandlingEvidenceReader;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\Contracts\ProductCostEvidenceReader;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use App\Modules\Sync\Application\Contracts\SyncRunTracker;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccountingContinuousDeltaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        config([
            'erp_accounting.continuous_delta.enabled' => false,
            'erp_accounting.continuous_delta.kol_commission_enabled' => false,
        ]);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("SET time_zone = '+00:00'");
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_uses_initial_lookback_and_writes_nothing(): void
    {
        $order = $this->readyOrder();
        $this->stampOrder($order, '2026-09-10 10:30:00');

        $summary = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame('dry_run', $summary['mode']);
        self::assertSame('2026-09-10T09:00:00+00:00', $summary['window_start']);
        self::assertSame('2026-09-10T12:00:00+00:00', $summary['window_end']);
        self::assertSame(1, $summary['candidate_count']);
        self::assertArrayHasKey('last_mile_estimate', $summary['stages']);
        self::assertArrayHasKey('last_mile_actual', $summary['stages']);
        self::assertSame(0, SyncRun::query()->count());
        self::assertSame(0, $order->costEntries()->count());
    }

    public function test_apply_persists_checkpoint_and_idempotent_overlap_rerun(): void
    {
        $order = $this->readyOrder();
        $first = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertTrue($first['checkpoint_advanced']);
        self::assertSame('2026-09-10T12:00:00+00:00', $first['checkpoint_after']);
        self::assertSame(0, $first['failures']);
        self::assertSame(4, $order->costEntries()->count());
        self::assertSame('3.50', $order->costEntries()->where('cost_type', 'payment_fee')->firstOrFail()->amount);

        $second = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:01:00Z'));
        self::assertSame('2026-09-10T11:55:00+00:00', $second['window_start']);
        self::assertSame(4, $order->costEntries()->count());
        self::assertSame(0, $second['stages']['payment_fee']['applied']);
        self::assertGreaterThanOrEqual(1, $second['stages']['payment_fee']['noop']);
    }

    public function test_five_minute_overlap_recovers_late_change_before_checkpoint(): void
    {
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $late = $this->readyOrder();
        $this->stampOrderAndRelations($late, '2026-09-10 11:57:00');

        $summary = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));

        self::assertSame('2026-09-10T11:55:00+00:00', $summary['window_start']);
        self::assertSame(1, $summary['candidate_count']);
    }

    public function test_hard_cap_fails_before_any_materialization_or_sync_run_write(): void
    {
        $one = $this->readyOrder();
        $two = $this->readyOrder();

        $summary = $this->service()->apply(1, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame('blocked_candidate_limit_exceeded', $summary['gate']);
        self::assertTrue($summary['candidate_overflow']);
        self::assertSame(2, $summary['candidate_count']);
        self::assertSame(0, SyncRun::query()->count());
        self::assertSame(0, $one->costEntries()->count());
        self::assertSame(0, $two->costEntries()->count());
    }

    public function test_business_blockers_are_nonfatal_and_checkpoint_advances(): void
    {
        $order = $this->bareOrder();

        $summary = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame(0, $summary['failures']);
        self::assertTrue($summary['checkpoint_advanced']);
        self::assertGreaterThan(0, $summary['stages']['product_cost']['blocked']);
        self::assertGreaterThan(0, $summary['stages']['payment_fee']['blocked']);
        self::assertSame('0.00', $order->costEntries()->where('cost_type', 'customs_tax')->firstOrFail()->amount);
    }

    public function test_runtime_failure_does_not_advance_checkpoint(): void
    {
        $this->readyOrder();
        $this->app->instance(ProductCostEvidenceReader::class, new class implements ProductCostEvidenceReader
        {
            public function read(int|string $orderId): array
            {
                throw new \RuntimeException('continuous_delta_runtime_failure_fixture');
            }
        });

        $summary = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame(1, $summary['failures']);
        self::assertFalse($summary['checkpoint_advanced']);
        self::assertNull($summary['checkpoint_after']);
        self::assertNull(app(SyncRunTracker::class)->latestContextString(
            'accounting_continuous_delta', 'internal', 'finance', 'checkpoint_at'
        ));
    }

    public function test_shipment_change_reenters_scope_after_checkpoint(): void
    {
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');
        DB::table('shipments')->where('order_id', $order->id)->update([
            'updated_at' => '2026-09-10 12:30:00',
        ]);

        $summary = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));

        self::assertSame(1, $summary['candidate_count']);
    }

    public function test_carrier_billing_change_reenters_scope_and_materializes_actual_last_mile(): void
    {
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');
        DB::table('orders')->where('id', $order->id)->update([
            'order_status' => 'shipped',
            'updated_at' => '2026-09-10 10:00:00',
        ]);
        DB::table('shipments')->where('order_id', $order->id)->update([
            'shipment_status' => 'shipped',
            'tracking_number' => 'P6C-BILL-'.$order->id,
            'updated_at' => '2026-09-10 10:00:00',
        ]);
        $shipment = $order->shipment()->firstOrFail();
        $order->costEntries()->create([
            'cost_type' => 'last_mile_cost', 'amount' => '9.99', 'currency' => 'USD',
            'provenance' => 'estimated_reference', 'status' => 'estimated',
            'source_reference' => 'last-mile-estimate:v1:order:'.$order->id,
        ]);
        $billing = $shipment->billingEvidence()->create([
            'actual_cost' => '12.3400', 'currency' => 'USD',
            'billing_document_number' => 'P6C-BILL-'.$order->id,
            'data_source' => 'carrier_bill_import:p6c-test',
            'billed_at' => '2026-09-10 12:30:00',
        ]);
        DB::table('shipment_carrier_billing')->where('id', $billing->id)->update([
            'created_at' => '2026-09-10 12:30:00',
            'updated_at' => '2026-09-10 12:30:00',
        ]);

        $preview = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $preview['candidate_count']);
        self::assertSame(1, $preview['stages']['last_mile_actual']['applied']);
        self::assertSame(1, $preview['stages']['last_mile_estimate']['blocked']);

        $applied = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $applied['stages']['last_mile_actual']['applied']);
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->fresh();
        self::assertSame('12.34', $entry->amount);
        self::assertSame('provider_import', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
    }

    public function test_p9_consumption_change_reenters_scope_without_touching_order_or_shipment_timestamp(): void
    {
        config([
            'warehouse_storage_aging.order_cost_enabled' => true,
            'warehouse_storage_aging.canonical_from' => '2026-09-10',
        ]);
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');

        $warehouse = Warehouse::query()->firstOrCreate(
            ['slug' => 'us-la'],
            ['code' => 'US-LA-P9-DELTA', 'name' => 'US LA P9 Delta', 'type' => 'warehouse', 'is_active' => true, 'priority' => 10],
        );
        $item = $order->items()->firstOrFail();
        $batch = InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id,
            'source_type' => 'api',
            'sync_mode' => 'full',
            'checksum' => hash('sha256', 'p9-delta-'.$order->id),
            'status' => 'confirmed',
            'confirmed_at' => '2026-09-10 10:00:00',
        ]);
        $consumption = WarehouseInventoryLotConsumption::query()->create([
            'shipment_id' => $order->shipment()->firstOrFail()->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $item->product_id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-06-01',
            'source_sync_batch_id' => $batch->id,
            'quantity' => 1,
            'aging_months_charged' => 1,
            'unit_storage_cost_cny' => '1.00',
            'allocated_storage_cost_cny' => '1.00',
            'consumed_at' => '2026-09-10 12:30:00',
            'lot_business_key' => hash('sha256', 'p9-delta-lot-'.$order->id),
            'source_reference' => 'p9-delta-'.$order->id,
            'captured_at' => '2026-09-10 12:30:00',
        ]);
        DB::table('warehouse_inventory_lot_consumptions')->where('id', $consumption->id)->update([
            'created_at' => '2026-09-10 12:30:00',
            'updated_at' => '2026-09-10 12:30:00',
        ]);

        $preview = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));

        self::assertSame(1, $preview['candidate_count']);
        self::assertSame(1, $preview['stages']['warehouse_cost']['applied']);

        $applied = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $applied['stages']['warehouse_cost']['applied']);
        $entry = $order->costEntries()->where('cost_type', 'warehouse_cost')->firstOrFail();
        self::assertSame('allocated_actual', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
        self::assertStringStartsWith('p9-storage-aging-allocation:v1:order:', $entry->source_reference);
    }

    public function test_warehouse_allocation_change_reenters_scope_and_materializes_warehouse_cost(): void
    {
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');
        DB::table('orders')->where('id', $order->id)->update(['order_status' => 'shipped']);
        DB::table('shipments')->where('order_id', $order->id)->update([
            'shipment_status' => 'shipped',
            'tracking_number' => 'P7-DELTA-'.$order->id,
            'updated_at' => '2026-09-10 10:00:00',
        ]);
        $shipment = $order->shipment()->firstOrFail();
        $warehouse = $shipment->warehouse()->firstOrFail();
        $file = tempnam(sys_get_temp_dir(), 'p7-delta-').'.csv';
        $handle = fopen($file, 'wb');
        fputcsv($handle, ['warehouse_slug', 'billing_period_start', 'billing_period_end', 'storage_pool_amount', 'currency', 'reference_number', 'tracking_number', 'outbound_units']);
        fputcsv($handle, [$warehouse->slug, '2026-09-01', '2026-09-30', '1.0000', 'USD', 'C-P7', $shipment->tracking_number, 1]);
        fclose($handle);
        app(WarehouseStorageCostImportService::class)->apply($file, 'p7-test');
        DB::table('warehouse_storage_cost_import_rows')->where('order_id', $order->id)->update([
            'created_at' => '2026-09-10 12:30:00',
            'updated_at' => '2026-09-10 12:30:00',
        ]);

        $preview = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $preview['candidate_count']);
        self::assertSame(1, $preview['stages']['warehouse_cost']['applied']);

        $applied = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $applied['stages']['warehouse_cost']['applied']);
        $entry = $order->costEntries()->where('cost_type', 'warehouse_cost')->firstOrFail()->fresh();
        self::assertSame('1.00', $entry->amount);
        self::assertSame('allocated_actual', $entry->provenance);
    }

    public function test_referral_change_does_not_reenter_scope_while_kol_commission_stage_is_disabled(): void
    {
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');
        $referral = KolAffiliateReferral::query()->create([
            'connection_key' => 'beyourlover.com', 'referral_id' => 87999, 'affiliate_id' => 98999,
            'order_id' => $order->id, 'order_reference' => (string) $order->externalReferences()->where('provider', 'woocommerce')->value('reference'),
            'commission_amount' => '12.340000', 'currency' => 'USD', 'provider_status' => 'unpaid',
            'context' => 'woocommerce', 'referral_date' => '2026-09-10 10:00:00',
            'affiliate_resolution_status' => 'bound', 'order_resolution_status' => 'resolved',
            'reconciliation_status' => 'present', 'sync_status' => 'synced',
        ]);
        DB::table('kol_affiliate_referrals')->where('id', $referral->id)->update([
            'created_at' => '2026-09-10 12:30:00', 'updated_at' => '2026-09-10 12:30:00',
        ]);

        $preview = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(0, $preview['candidate_count']);
        self::assertSame(0, $preview['stages']['kol_commission']['applied']);
        self::assertFalse($order->costEntries()->where('cost_type', 'kol_commission')->exists());
    }

    public function test_referral_change_reenters_scope_and_materializes_kol_commission(): void
    {
        config(['erp_accounting.continuous_delta.kol_commission_enabled' => true]);
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');
        $referral = KolAffiliateReferral::query()->create([
            'connection_key' => 'beyourlover.com', 'referral_id' => 88001, 'affiliate_id' => 99001,
            'order_id' => $order->id, 'order_reference' => (string) $order->externalReferences()->where('provider', 'woocommerce')->value('reference'),
            'commission_amount' => '12.340000', 'currency' => 'USD', 'provider_status' => 'unpaid',
            'context' => 'woocommerce', 'referral_date' => '2026-09-10 10:00:00',
            'affiliate_resolution_status' => 'bound', 'order_resolution_status' => 'resolved',
            'reconciliation_status' => 'present', 'sync_status' => 'synced',
        ]);
        DB::table('kol_affiliate_referrals')->where('id', $referral->id)->update([
            'created_at' => '2026-09-10 12:30:00', 'updated_at' => '2026-09-10 12:30:00',
        ]);

        $preview = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $preview['candidate_count']);
        self::assertSame(1, $preview['stages']['kol_commission']['applied']);

        $applied = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $applied['stages']['kol_commission']['applied']);
        $entry = $order->costEntries()->where('cost_type', 'kol_commission')->firstOrFail();
        self::assertSame('12.34', $entry->amount);
        self::assertSame('provider_import', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
    }

    public function test_referral_delta_still_works_when_overseas_handling_capability_is_not_installed(): void
    {
        config(['erp_accounting.continuous_delta.kol_commission_enabled' => true]);
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');
        $referral = KolAffiliateReferral::query()->create([
            'connection_key' => 'beyourlover.com', 'referral_id' => 88002, 'affiliate_id' => 99002,
            'order_id' => $order->id, 'order_reference' => (string) $order->externalReferences()->where('provider', 'woocommerce')->value('reference'),
            'commission_amount' => '8.500000', 'currency' => 'USD', 'provider_status' => 'paid',
            'context' => 'woocommerce', 'referral_date' => '2026-09-10 10:00:00',
            'affiliate_resolution_status' => 'bound', 'order_resolution_status' => 'resolved',
            'reconciliation_status' => 'present', 'sync_status' => 'synced',
        ]);
        DB::table('kol_affiliate_referrals')->where('id', $referral->id)->update([
            'created_at' => '2026-09-10 12:30:00', 'updated_at' => '2026-09-10 12:30:00',
        ]);
        $this->app->offsetUnset(OverseasHandlingEvidenceReader::class);
        self::assertFalse($this->app->bound(OverseasHandlingEvidenceReader::class));

        $preview = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $preview['candidate_count']);
        self::assertSame(1, $preview['stages']['kol_commission']['applied']);
        self::assertSame(0, $preview['stages']['overseas_handling_actual']['applied']);
        self::assertSame(0, $preview['stages']['overseas_handling_actual']['blocked']);
    }

    public function test_strong_facts_are_protected_and_p6_through_p9_cost_types_are_untouched(): void
    {
        $order = $this->readyOrder();
        $order->costEntries()->create([
            'cost_type' => 'product_cost', 'amount' => '99.00', 'currency' => 'USD',
            'provenance' => 'actual', 'status' => 'confirmed', 'source_reference' => 'owner:actual-product',
        ]);
        $protected = [
            'last_mile_cost' => '11.11',
            'warehouse_cost' => '22.22',
            'overseas_handling_cost' => '33.33',
            'kol_commission' => '44.44',
            'refund_replacement_loss' => '55.55',
            'other_variable_cost' => '66.66',
        ];
        foreach ($protected as $type => $amount) {
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => $amount, 'currency' => 'USD',
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'owner:'.$type,
            ]);
        }

        $summary = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame('99.00', $order->costEntries()->where('cost_type', 'product_cost')->firstOrFail()->amount);
        self::assertGreaterThan(0, $summary['stages']['product_cost']['blocked']);
        foreach ($protected as $type => $amount) {
            self::assertSame($amount, $order->costEntries()->where('cost_type', $type)->firstOrFail()->amount, $type);
        }
    }

    public function test_noop_overlap_recovery_does_not_self_touch_financials_forever(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $firstUpdatedAt = $order->financials()->firstOrFail()->updated_at?->toIso8601String();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        $overlap = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $overlap['candidate_count']);
        self::assertSame(0, $overlap['stages']['product_cost']['applied']);
        self::assertSame(0, $overlap['stages']['fixed_costs']['applied']);
        self::assertSame(0, $overlap['stages']['payment_fee']['applied']);
        self::assertSame($firstUpdatedAt, $order->financials()->firstOrFail()->fresh()->updated_at?->toIso8601String());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10T14:00:00Z'));
        $settled = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T14:00:00Z'));
        self::assertSame(0, $settled['candidate_count']);
    }

    public function test_production_apply_is_blocked_when_continuous_delta_flag_is_false(): void
    {
        $this->readyOrder();
        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';
        config(['erp_accounting.continuous_delta.enabled' => false]);

        try {
            $this->artisan('finance:materialize-continuous-delta', ['--apply' => true])->assertFailed();
            self::assertSame(0, SyncRun::query()->count());
            self::assertSame(0, DB::table('order_cost_entries')->count());
        } finally {
            $this->app['env'] = $previousEnv;
        }
    }

    public function test_mysql_candidate_query_normalizes_session_timezone_to_utc(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('MySQL-only session timezone contract.');
        }

        DB::statement("SET time_zone = 'SYSTEM'");
        $this->readyOrder();

        $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        $session = DB::selectOne('select @@session.time_zone as timezone');
        self::assertSame('+00:00', $session->timezone);
    }

    public function test_scheduler_is_disabled_by_default_and_enabled_at_ten_past_without_changing_woo_hourly_cadence(): void
    {
        $before = collect(app(Schedule::class)->events())
            ->where('description', 'finance.accounting.continuous-delta.hourly');
        self::assertCount(0, $before);

        config([
            'erp_accounting.continuous_delta.enabled' => true,
            'services.woocommerce.order_sync_enabled' => true,
        ]);
        require base_path('routes/console.php');

        $events = collect(app(Schedule::class)->events());
        $finance = $events->where('description', 'finance.accounting.continuous-delta.hourly');
        self::assertCount(1, $finance);
        $financeEvent = $finance->first();
        self::assertSame('10 * * * *', $financeEvent->expression);
        self::assertTrue($financeEvent->withoutOverlapping);
        self::assertSame(50, $financeEvent->expiresAt);
        self::assertTrue($financeEvent->onOneServer);
        self::assertStringContainsString('finance:materialize-continuous-delta --apply --limit=10000 --no-interaction', $financeEvent->command);

        $woo = $events->where('description', 'orders.woocommerce.inbound.hourly');
        self::assertCount(1, $woo);
        self::assertSame('0 * * * *', $woo->first()->expression);
    }

    private function service(): AccountingContinuousDeltaService
    {
        return $this->app->make(AccountingContinuousDeltaService::class);
    }

    private function bareOrder(): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'C1-'.strtoupper(uniqid()),
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function readyOrder(): Order
    {
        $order = $this->bareOrder();
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'beyourlover.com',
            'reference' => 'C1-WOO-'.strtoupper(uniqid()),
            'provider_modified_at' => now(),
        ]);
        $order->financials()->create([
            'merchandise_list_amount' => '100.00',
            'discount_amount' => '0.00',
            'shipping_revenue' => '0.00',
            'amount_paid' => '100.00',
            'refund_amount' => '0.00',
            'tax_collected' => '0.00',
            'provider_order_total' => '100.00',
            'refund_tax' => '0.00',
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => (string) $order->id,
            'provider_order_status' => 'processing',
            'provider_modified_at' => now(),
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
        $product = Product::query()->create([
            'source_type' => 'woocommerce',
            'product_type' => 'simple',
            'sku' => 'C1-SKU-'.strtoupper(uniqid()),
            'name' => 'C1 Product',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_summary' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => '100.00',
            'line_revenue' => '100.00',
        ]);
        ProductCostSource::query()->create([
            'product_id' => $product->id,
            'source_key' => 'C1-'.$product->id,
            'unit_cost' => '20.00',
            'currency' => 'USD',
            'provenance' => 'confirmed_source_file',
            'status' => 'confirmed',
            'source_type' => 'inventory_cost_book',
            'source_reference' => 'c1-fixture.csv',
            'effective_from' => '2026-09-01',
            'source_at' => '2026-09-09 00:00:00',
            'confirmed_at' => '2026-09-09 00:00:00',
            'source_fingerprint' => hash('sha256', 'C1|'.$product->id.'|'.uniqid('', true)),
        ]);
        $warehouse = Warehouse::query()->firstOrCreate(
            ['slug' => 'factory'],
            ['code' => 'FACTORY-C1', 'name' => 'China Factory', 'type' => 'warehouse',
                'ownership_type' => 'owned', 'is_active' => true, 'priority' => 100],
        );
        $shipment = new Shipment([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'c1_test',
        ]);
        $order->shipment()->save($shipment);

        return $order->fresh();
    }

    private function stampOrder(Order $order, string $timestamp): void
    {
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
    }

    private function stampOrderAndRelations(Order $order, string $timestamp): void
    {
        $this->stampOrder($order, $timestamp);
        DB::table('order_external_references')->where('order_id', $order->id)->update(['updated_at' => $timestamp]);
        DB::table('order_financials')->where('order_id', $order->id)->update(['updated_at' => $timestamp]);
        DB::table('shipments')->where('order_id', $order->id)->update(['updated_at' => $timestamp]);
    }
}
