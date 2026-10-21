<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\CustomerHistoricalShipmentBackfillService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerHistoricalShipmentBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_zero_write_and_apply_creates_only_canonical_shipment(): void
    {
        $order = $this->order('007-0128-c33213');
        $file = $this->csv([$this->row($order, 'CF333135750DE', 'C33213-1', 3)]);
        $service = app(CustomerHistoricalShipmentBackfillService::class);

        $preview = $service->preview($file);
        $this->assertSame(1, $preview['ready_count']);
        $this->assertSame(0, $preview['review_count']);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('shipment_attempts', 0);
        $this->assertDatabaseCount('manual_syncs', 0);

        $result = $service->apply($file);
        $this->assertSame(1, $result['shipments_created']);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('shipment_attempts', 0);
        $this->assertDatabaseCount('manual_syncs', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'shipped']);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'warehouse_selection_source' => 'historical_exact',
            'carrier' => 'DHL',
            'service' => 'DHL PAKET International',
            'carrier_selection_source' => 'historical_exact',
            'tracking_number' => 'CF333135750DE',
            'shipped_at' => '2026-01-29 18:57:22',
            'handled_by' => null,
        ]);
    }

    public function test_historical_scope_identity_and_time_fail_closed_without_writes(): void
    {
        $historicalOrder = $this->order('007-0128-c33215');
        $identityOrder = $this->order('007-0127-c33180');
        $timeOrder = $this->order('007-0126-c33096');
        $rows = [
            $this->row($historicalOrder, 'KOL-TRACK', 'KOL26012804-007', 2),
            array_replace($this->row($identityOrder, 'BAD-ID', 'C33180', 4), ['erp_order_id' => '007-0128-c99999']),
            array_replace($this->row($timeOrder, 'BAD-TIME', 'C33096', 5), ['shipped_at' => '2026-01-29 19:57:22']),
        ];

        $preview = app(CustomerHistoricalShipmentBackfillService::class)->preview($this->csv($rows));
        $this->assertSame(0, $preview['ready_count']);
        $this->assertSame(3, $preview['review_count']);
        $reasons = collect($preview['rows'])->pluck('reason_code')->sort()->values()->all();
        $this->assertSame(['historical_scope_excluded', 'order_identity_mismatch', 'shipped_at_invalid'], $reasons);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_existing_shipment_attempt_tracking_conflict_and_duplicate_source_are_review(): void
    {
        $existingOrder = $this->order('007-0128-c33213');
        $attemptOrder = $this->order('007-0128-c33215');
        $trackingOwner = $this->order('007-0127-c33180');
        $trackingConflictOrder = $this->order('007-0126-c33096');
        $duplicateOrder = $this->order('007-0125-c33070');
        $de = Warehouse::query()->where('code', 'DE')->firstOrFail();

        $existingOrder->shipment()->create([
            'shipment_status' => 'shipped', 'warehouse_id' => $de->id,
            'warehouse_selection_source' => 'manual', 'carrier' => 'DHL', 'service' => 'Other',
            'carrier_selection_source' => 'manual', 'tracking_number' => 'EXISTING', 'shipped_at' => '2026-01-29 18:00:00',
        ]);
        ShipmentAttempt::query()->create([
            'order_id' => $attemptOrder->id, 'attempt_number' => 1, 'shipment_status' => 'historical_evidence',
            'tracking_number' => 'ATTEMPT', 'snapshotted_at' => now(),
        ]);
        $trackingOwner->shipment()->create(['shipment_status' => 'shipped', 'tracking_number' => 'TAKEN']);

        $rows = [
            $this->row($existingOrder, 'NEW-EXISTING', 'C33213', 3),
            $this->row($attemptOrder, 'NEW-ATTEMPT', 'C33215', 4),
            $this->row($trackingConflictOrder, 'TAKEN', 'C33096', 5),
            $this->row($duplicateOrder, 'DUP-A', 'C33070', 6),
            $this->row($duplicateOrder, 'DUP-B', 'C33070', 7),
        ];
        $preview = app(CustomerHistoricalShipmentBackfillService::class)->preview($this->csv($rows));
        $this->assertSame(0, $preview['ready_count']);
        $this->assertSame(5, $preview['review_count']);
        $reasons = collect($preview['rows'])->pluck('reason_code')->all();
        $this->assertContains('existing_shipment_conflict', $reasons);
        $this->assertContains('shipment_attempt_exists', $reasons);
        $this->assertContains('tracking_conflict', $reasons);
        $this->assertSame(2, collect($reasons)->filter(fn ($r) => $r === 'duplicate_source_order')->count());
    }

    public function test_replay_is_idempotent_skip_when_source_backed_facts_match(): void
    {
        $order = $this->order('007-0128-c33213');
        $file = $this->csv([$this->row($order, 'CF333135750DE', 'C33213-1', 3)]);
        $service = app(CustomerHistoricalShipmentBackfillService::class);
        $first = $service->apply($file);
        $this->assertSame(1, $first['shipments_created']);

        $secondPreview = $service->preview($file);
        $this->assertSame(0, $secondPreview['ready_count']);
        $this->assertSame(1, $secondPreview['skip_count']);
        $this->assertSame('already_applied', $secondPreview['rows'][0]['reason_code']);
        $second = $service->apply($file);
        $this->assertSame(0, $second['shipments_created']);
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_command_is_dry_run_first_and_production_apply_requires_flag_and_exact_guards(): void
    {
        $order = $this->order('007-0128-c33213');
        $file = $this->csv([$this->row($order, 'CF333135750DE', 'C33213-1', 3)]);
        $service = app(CustomerHistoricalShipmentBackfillService::class);
        $plan = $service->preview($file);

        $this->artisan('logistics:backfill-customer-historical-shipments', ['file' => $file])->assertSuccessful();
        $this->assertDatabaseCount('shipments', 0);

        $old = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_LOGISTICS_CUSTOMER_HISTORICAL_SHIPMENT_BACKFILL_PRODUCTION_APPLY_ENABLED');
        $args = [
            'file' => $file, '--apply' => true,
            '--expect-source-sha' => $plan['source_checksum'], '--expect-plan-sha' => $plan['plan_checksum'],
            '--expect-ready' => '1', '--expect-review' => '0', '--expect-skip' => '0',
        ];
        try {
            $this->artisan('logistics:backfill-customer-historical-shipments', $args)->assertFailed();
            putenv('ERP_LOGISTICS_CUSTOMER_HISTORICAL_SHIPMENT_BACKFILL_PRODUCTION_APPLY_ENABLED=true');
            $wrong = $args; $wrong['--expect-ready'] = '2';
            $this->artisan('logistics:backfill-customer-historical-shipments', $wrong)->assertFailed();
            $this->assertDatabaseCount('shipments', 0);
            $this->artisan('logistics:backfill-customer-historical-shipments', $args)->assertSuccessful();
            $this->assertDatabaseCount('shipments', 1);
        } finally {
            putenv('ERP_LOGISTICS_CUSTOMER_HISTORICAL_SHIPMENT_BACKFILL_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $old;
        }
    }

    private function order(string $erpOrderId): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'HIST-BACKFILL-'.strtoupper(uniqid()),
            'erp_order_id' => $erpOrderId,
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => preg_replace('/^.*-c/', '', $erpOrderId).'-TEST',
            'order_date' => '2026-01-28',
            'order_status' => 'shipped',
            'currency' => 'EUR',
        ]);
    }

    private function row(Order $order, string $tracking, string $sourceReference, int $sourceRow): array
    {
        return [
            'order_id' => (string) $order->id,
            'erp_order_id' => (string) $order->erp_order_id,
            'tracking_number' => $tracking,
            'warehouse_code' => 'DE',
            'carrier' => 'DHL',
            'service' => 'DHL PAKET International',
            'shipped_at' => '2026-01-29T19:57:22+01:00',
            'source_reference' => $sourceReference,
            'provider_reference' => 'OBS0212601290RU',
            'source_row_number' => (string) $sourceRow,
        ];
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hist-customer-backfill-').'.csv';
        $h = fopen($path, 'wb');
        fputcsv($h, ['order_id','erp_order_id','tracking_number','warehouse_code','carrier','service','shipped_at','source_reference','provider_reference','source_row_number']);
        foreach ($rows as $row) fputcsv($h, array_values($row));
        fclose($h);
        return $path;
    }
}
