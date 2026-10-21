<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\HistoricalFulfillmentShipmentBackfillService;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HistoricalFulfillmentShipmentBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_zero_write_and_apply_creates_only_class_a_shipment(): void
    {
        $order = $this->order('007-0915-c50001');
        $file = $this->source([$this->candidate($order, 'HFR3-A-1')]);
        $service = app(HistoricalFulfillmentShipmentBackfillService::class);

        $preview = $service->preview($file);
        $this->assertSame(1, $preview['ready_count']);
        $this->assertSame(0, $preview['review_count']);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('shipment_attempts', 0);

        $result = $service->apply($file);
        $this->assertSame('apply', $result['mode']);
        $this->assertSame(1, $result['shipments_created']);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('shipment_attempts', 0);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'warehouse_selection_source' => 'historical_exact',
            'carrier_selection_source' => 'historical_exact',
            'tracking_number' => 'HFR3-A-1',
        ]);
    }

    public function test_missing_warehouse_service_stays_review_and_apply_is_blocked(): void
    {
        $order = $this->order('007-0915-c50002');
        $candidate = $this->candidate($order, 'HFR3-MISSING');
        $candidate['evidence']['warehouse_id'] = null;
        $candidate['evidence']['warehouse_approved'] = false;
        $candidate['evidence']['service'] = null;
        $candidate['evidence']['carrier_service_approved'] = false;
        $file = $this->source([$candidate]);
        $service = app(HistoricalFulfillmentShipmentBackfillService::class);

        $preview = $service->preview($file);
        $this->assertSame(0, $preview['ready_count']);
        $this->assertSame(1, $preview['review_count']);
        $this->expectExceptionMessage('hfr3_apply_blocked:no_ready_candidates');
        $service->apply($file);
    }

    public function test_db_conflicts_fail_closed(): void
    {
        $attemptOrder = $this->order('007-0915-c50003');
        $trackingOwner = $this->order('007-0915-c50004');
        $trackingOrder = $this->order('007-0915-c50005');
        ShipmentAttempt::query()->create([
            'order_id' => $attemptOrder->id, 'attempt_number' => 1,
            'shipment_status' => 'historical_evidence', 'tracking_number' => 'OLD-ATTEMPT',
            'snapshotted_at' => now(),
        ]);
        $trackingOwner->shipment()->create(['shipment_status' => 'shipped', 'tracking_number' => 'TAKEN-HFR3']);

        $preview = app(HistoricalFulfillmentShipmentBackfillService::class)->preview($this->source([
            $this->candidate($attemptOrder, 'NEW-HFR3'),
            $this->candidate($trackingOrder, 'TAKEN-HFR3'),
        ]));
        $this->assertSame(0, $preview['ready_count']);
        $this->assertSame(2, $preview['review_count']);
        $reasons = collect($preview['rows'])->pluck('reason_code')->all();
        $this->assertContains('shipment_attempt_exists', $reasons);
        $this->assertContains('tracking_conflict', $reasons);
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_apply_is_idempotent(): void
    {
        $order = $this->order('007-0915-c50006');
        $file = $this->source([$this->candidate($order, 'HFR3-IDEMPOTENT')]);
        $service = app(HistoricalFulfillmentShipmentBackfillService::class);
        $this->assertSame(1, $service->apply($file)['shipments_created']);
        $second = $service->preview($file);
        $this->assertSame(0, $second['ready_count']);
        $this->assertSame(1, $second['skip_count']);
        $this->assertSame('already_applied', $second['rows'][0]['reason_code']);
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_duplicate_ready_candidates_for_one_order_fail_closed(): void
    {
        $order = $this->order('007-0915-c50007');
        $preview = app(HistoricalFulfillmentShipmentBackfillService::class)->preview($this->source([
            $this->candidate($order, 'HFR3-MULTI-A'),
            $this->candidate($order, 'HFR3-MULTI-B'),
        ]));
        $this->assertSame(0, $preview['ready_count']);
        $this->assertSame(2, $preview['review_count']);
        $this->assertSame(2, collect($preview['rows'])->where('reason_code', 'plan_multiple_ready_candidates_for_order')->count());
    }

    public function test_command_requires_production_flag_and_exact_guards(): void
    {
        $order = $this->order('007-0915-c50008');
        $file = $this->source([$this->candidate($order, 'HFR3-CMD')]);
        $plan = app(HistoricalFulfillmentShipmentBackfillService::class)->preview($file);
        $this->artisan('logistics:hfr-backfill-customer-shipments', ['file' => $file])->assertSuccessful();
        $this->assertDatabaseCount('shipments', 0);

        $old = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_LOGISTICS_HFR3_PRODUCTION_APPLY_ENABLED');
        $args = [
            'file' => $file, '--apply' => true,
            '--expect-source-sha' => $plan['source_checksum'], '--expect-plan-sha' => $plan['plan_checksum'],
            '--expect-ready' => '1', '--expect-review' => '0', '--expect-skip' => '0',
        ];
        try {
            $this->artisan('logistics:hfr-backfill-customer-shipments', $args)->assertFailed();
            putenv('ERP_LOGISTICS_HFR3_PRODUCTION_APPLY_ENABLED=true');
            $wrong = $args;
            $wrong['--expect-ready'] = '2';
            $this->artisan('logistics:hfr-backfill-customer-shipments', $wrong)->assertFailed();
            $this->assertDatabaseCount('shipments', 0);
            $this->artisan('logistics:hfr-backfill-customer-shipments', $args)->assertSuccessful();
            $this->assertDatabaseCount('shipments', 1);
        } finally {
            putenv('ERP_LOGISTICS_HFR3_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $old;
        }
    }

    public function test_command_hard_blocks_apply_when_ready_is_zero(): void
    {
        $order = $this->order('007-0915-c50009');
        $candidate = $this->candidate($order, 'HFR3-NO-READY');
        $candidate['evidence']['warehouse_id'] = null;
        $candidate['evidence']['warehouse_approved'] = false;
        $file = $this->source([$candidate]);
        putenv('ERP_LOGISTICS_HFR3_PRODUCTION_APPLY_ENABLED=true');
        try {
            $this->artisan('logistics:hfr-backfill-customer-shipments', ['file' => $file, '--apply' => true])->assertFailed();
            $this->assertDatabaseCount('shipments', 0);
        } finally {
            putenv('ERP_LOGISTICS_HFR3_PRODUCTION_APPLY_ENABLED');
        }
    }

    private function order(string $erpOrderId): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'HFR3-'.strtoupper(uniqid()),
            'erp_order_id' => $erpOrderId,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => preg_replace('/^.*-c/', '', $erpOrderId),
            'order_date' => '2026-09-01',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
    }

    /** @return array<string,mixed> */
    private function candidate(Order $order, string $tracking): array
    {
        $warehouse = Warehouse::query()->where('code', 'DE')->firstOrFail();

        return [
            'candidate_key' => 'hfr3:'.(int) $order->id.':'.$tracking,
            'evidence_source' => 'test_exact_source',
            'historical_non_customer' => false,
            'order' => [
                'id' => (int) $order->id, 'erp_order_id' => (string) $order->erp_order_id,
                'order_kind' => 'customer', 'order_status' => 'shipped',
            ],
            'identity' => ['exact' => true, 'candidate_order_ids' => [(int) $order->id]],
            'existing_shipment' => null,
            'attempts' => ['contradictory' => false],
            'conflicts' => ['tracking_collision' => false, 'duplicate_tracking' => false, 'ambiguous_order' => false, 'ambiguous_shipment' => false],
            'evidence' => [
                'tracking_number' => $tracking, 'tracking_exact' => true,
                'warehouse_id' => (int) $warehouse->id, 'warehouse_approved' => true,
                'carrier' => 'DHL', 'service' => 'DHL PAKET International', 'carrier_service_approved' => true,
                'shipped_at' => '2026-09-02T12:00:00+00:00', 'shipped_at_source_backed' => true,
            ],
        ];
    }

    private function source(array $candidates): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hfr3-source-').'.json';
        file_put_contents($path, json_encode([
            'source_scan_sha256' => str_repeat('a', 64),
            'candidates' => array_values($candidates),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }
}
