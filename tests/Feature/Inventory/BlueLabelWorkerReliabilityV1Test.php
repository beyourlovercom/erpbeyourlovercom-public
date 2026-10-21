<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\WarehouseRestockDocumentJobService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockDocumentJob;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BlueLabelWorkerReliabilityV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('services.blue_label_worker.lease_seconds', 30);
        config()->set('services.blue_label_worker.max_attempts', 3);
        config()->set('services.blue_label_worker.retry_base_seconds', 5);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_retryable_failure_schedules_backoff_cleans_partial_files_and_allows_second_attempt(): void
    {
        Carbon::setTestNow('2026-09-22 13:00:00');
        $job = $this->queuedJob();

        $service = app(WarehouseRestockDocumentJobService::class);
        $claimed = $service->claimNext('worker-one');
        $this->assertSame($job->reference, $claimed?->reference);
        $service->start($job->reference, 'worker-one');

        $partial = 'inventory/restock-documents/'.$job->reference.'/blue-labels/C549A.btw';
        Storage::disk('local')->put($partial, 'partial-attempt-one');
        Storage::disk('local')->assertExists($partial);

        $retry = $service->fail(
            $job->reference,
            'worker-one',
            'dispatch_failure',
            'simulated generator crash',
        );

        $this->assertSame('queued', $retry->status);
        $this->assertNull($retry->worker_id);
        $this->assertSame(1, $retry->attempt_count);
        $this->assertSame('2026-09-22 13:00:05', $retry->next_attempt_at?->format('Y-m-d H:i:s'));
        Storage::disk('local')->assertMissing($partial);

        $this->assertNull($service->claimNext('worker-two'));

        Carbon::setTestNow('2026-09-22 13:00:06');
        $second = $service->claimNext('worker-two');

        $this->assertSame($job->reference, $second?->reference);
        $this->assertSame('claimed', $second?->status);
        $this->assertSame('worker-two', $second?->worker_id);
        $this->assertSame(2, $second?->attempt_count);
        $this->assertNull($second?->next_attempt_at);
    }

    public function test_expired_worker_lease_is_recovered_then_reclaimed_after_backoff(): void
    {
        Carbon::setTestNow('2026-09-22 14:00:00');
        $job = $this->queuedJob();

        $service = app(WarehouseRestockDocumentJobService::class);
        $service->claimNext('worker-one');
        $service->start($job->reference, 'worker-one');

        $partial = 'inventory/restock-documents/'.$job->reference.'/sku-labels/C549A.pdf';
        Storage::disk('local')->put($partial, 'partial-pdf');

        Carbon::setTestNow('2026-09-22 14:00:31');
        $this->assertNull($service->claimNext('worker-two'));

        $recovered = WarehouseRestockDocumentJob::query()
            ->where('reference', $job->reference)
            ->firstOrFail();

        $this->assertSame('queued', $recovered->status);
        $this->assertNull($recovered->worker_id);
        $this->assertSame('worker_lease_expired', $recovered->last_error_code);
        $this->assertSame('2026-09-22 14:00:36', $recovered->next_attempt_at?->format('Y-m-d H:i:s'));
        Storage::disk('local')->assertMissing($partial);

        Carbon::setTestNow('2026-09-22 14:00:37');
        $second = $service->claimNext('worker-two');

        $this->assertSame($job->reference, $second?->reference);
        $this->assertSame(2, $second?->attempt_count);
        $this->assertSame('worker-two', $second?->worker_id);
    }

    public function test_expired_lease_becomes_terminal_after_max_attempts(): void
    {
        config()->set('services.blue_label_worker.max_attempts', 1);
        Carbon::setTestNow('2026-09-22 15:00:00');
        $job = $this->queuedJob();

        $service = app(WarehouseRestockDocumentJobService::class);
        $service->claimNext('worker-one');
        $service->start($job->reference, 'worker-one');

        Carbon::setTestNow('2026-09-22 15:00:31');
        $this->assertNull($service->claimNext('worker-two'));

        $failed = WarehouseRestockDocumentJob::query()
            ->where('reference', $job->reference)
            ->firstOrFail();

        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, $failed->attempt_count);
        $this->assertSame('worker_lease_exhausted', $failed->last_error_code);
        $this->assertNotNull($failed->failed_at);
        $this->assertNull($failed->next_attempt_at);
        $this->assertNull($failed->lease_expires_at);
    }

    private function queuedJob(): WarehouseRestockDocumentJob
    {
        $warehouse = Warehouse::query()->create([
            'slug' => 'm8-worker-reliability-'.uniqid(),
            'code' => 'M8-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'M8 Worker Reliability',
        ]);

        $plan = WarehouseRestockPlan::query()->create([
            'warehouse_id' => $warehouse->id,
            'reference' => 'RP-M8-'.strtoupper(substr(uniqid(), -8)),
            'status' => 'confirmed',
            'source_checksum' => hash('sha256', uniqid('source', true)),
            'snapshot_checksum' => hash('sha256', uniqid('snapshot', true)),
            'rule_snapshot' => ['history_days' => 120],
            'summary_snapshot' => ['snapshot_products' => 1, 'final_units' => 10],
            'confirmed_at' => now(),
        ]);

        $plan->items()->create([
            'product_id' => random_int(900000, 999999),
            'product_sku' => 'VC549A',
            'label_sku' => 'C549A',
            'label_description' => 'C549A 磁吸穿戴',
            'product_name' => 'VC549A 磁吸穿戴裸机玫红色成品 APP款',
            'final_restock_qty' => 10,
            'formula_restock_qty' => 10,
            'manual_restock_qty' => 0,
            'shipped_120d_qty' => 10,
            'quantity_available' => 0,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'units_per_carton' => 5,
            'carton_count' => 2,
            'packaged_weight_kg' => '0.300',
            'weight_source' => '包装档案',
            'sort_order' => 0,
        ]);

        return app(WarehouseRestockDocumentJobService::class)
            ->requestBlueLabels($warehouse, $plan->id, null)['job'];
    }
}
