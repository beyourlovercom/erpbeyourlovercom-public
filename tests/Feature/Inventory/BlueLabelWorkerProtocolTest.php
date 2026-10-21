<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\WarehouseRestockDocumentJobService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BlueLabelWorkerProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-blue-label-worker-token';
    private const WORKER = 'company-win-label-01';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.blue_label_worker.token', self::TOKEN);
        config()->set('services.blue_label_worker.lease_seconds', 180);
        Storage::fake('local');
    }

    public function test_worker_protocol_claims_runs_and_completes_a_frozen_job(): void
    {
        [$warehouse, $plan] = $this->makePlan();
        $created = app(WarehouseRestockDocumentJobService::class)
            ->requestBlueLabels($warehouse, $plan->id, null);
        $reference = $created['job']->reference;

        $claim = $this->workerPost('/api/internal/blue-label-worker/claim');
        $claim->assertOk()
            ->assertJsonPath('job.reference', $reference)
            ->assertJsonPath('job.status', 'claimed')
            ->assertJsonPath('job.worker_id', self::WORKER)
            ->assertJsonPath('job.attempt_count', 1)
            ->assertJsonPath('job.payload.items.0.label_sku', 'C549A');

        $this->workerPost("/api/internal/blue-label-worker/jobs/{$reference}/start")
            ->assertOk()
            ->assertJsonPath('job.status', 'running');

        $this->workerPost("/api/internal/blue-label-worker/jobs/{$reference}/heartbeat")
            ->assertOk()
            ->assertJsonPath('job.status', 'running');

        $bad = $this->workerPost(
            "/api/internal/blue-label-worker/jobs/{$reference}/complete",
            ['manifest' => ['schema_version' => 'wrong'], 'output_root' => 'C:\\bad'],
        );
        $bad->assertStatus(422);
        $this->assertDatabaseHas('warehouse_restock_document_jobs', [
            'reference' => $reference,
            'status' => 'running',
        ]);

        $manifest = $this->manifest($reference, $plan);
        $btw = 'BTW-C549A-'.str_repeat('x', 1200);
        $pdf = '%PDF-1.4 C549A '.str_repeat('p', 1200);
        $manifest['artifacts'][0]['btw_sha256'] = hash('sha256', $btw);
        $manifest['artifacts'][0]['pdf_sha256'] = hash('sha256', $pdf);
        app(WarehouseRestockDocumentJobService::class)->storeArtifact(
            $reference,
            self::WORKER,
            'btw',
            'C549A',
            UploadedFile::fake()->createWithContent('C549A.btw', $btw),
            $manifest['artifacts'][0]['btw_sha256'],
        );
        app(WarehouseRestockDocumentJobService::class)->storeArtifact(
            $reference,
            self::WORKER,
            'pdf',
            'C549A',
            UploadedFile::fake()->createWithContent('C549A.pdf', $pdf),
            $manifest['artifacts'][0]['pdf_sha256'],
        );

        $complete = $this->workerPost(
            "/api/internal/blue-label-worker/jobs/{$reference}/complete",
            ['manifest' => $manifest, 'output_root' => 'C:\\BYL\\labels\\'.$reference],
        );

        $complete->assertOk()
            ->assertJsonPath('job.status', 'succeeded')
            ->assertJsonPath('job.output_root', 'inventory/restock-documents/'.$reference);

        $this->assertDatabaseHas('warehouse_restock_document_jobs', [
            'reference' => $reference,
            'status' => 'succeeded',
            'worker_id' => self::WORKER,
            'attempt_count' => 1,
        ]);
    }

    public function test_worker_token_and_worker_ownership_fail_closed(): void
    {
        [$warehouse, $plan] = $this->makePlan();
        $created = app(WarehouseRestockDocumentJobService::class)
            ->requestBlueLabels($warehouse, $plan->id, null);
        $reference = $created['job']->reference;

        $this->postJson(
            '/api/internal/blue-label-worker/claim',
            [],
            ['Authorization' => 'Bearer wrong', 'X-BYL-Worker-ID' => self::WORKER],
        )->assertStatus(401);

        $this->workerPost('/api/internal/blue-label-worker/claim')->assertOk();

        $this->postJson(
            "/api/internal/blue-label-worker/jobs/{$reference}/start",
            [],
            ['Authorization' => 'Bearer '.self::TOKEN, 'X-BYL-Worker-ID' => 'other-worker-01'],
        )->assertStatus(422);

        $this->assertDatabaseHas('warehouse_restock_document_jobs', [
            'reference' => $reference,
            'status' => 'claimed',
            'worker_id' => self::WORKER,
        ]);
    }

    public function test_empty_queue_returns_null_job_and_unconfigured_token_fails_closed(): void
    {
        $this->workerPost('/api/internal/blue-label-worker/claim')
            ->assertOk()
            ->assertExactJson(['job' => null]);

        config()->set('services.blue_label_worker.token', '');

        $this->postJson(
            '/api/internal/blue-label-worker/claim',
            [],
            ['Authorization' => 'Bearer '.self::TOKEN, 'X-BYL-Worker-ID' => self::WORKER],
        )->assertStatus(503);
    }

    private function workerPost(string $uri, array $payload = [])
    {
        return $this->postJson($uri, $payload, [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-BYL-Worker-ID' => self::WORKER,
        ]);
    }

    private function makePlan(): array
    {
        $warehouse = Warehouse::create([
            'slug' => 'worker-protocol-us-sm',
            'code' => 'T-WORKER-USSM',
            'name' => 'Worker Protocol US-SM',
        ]);

        $plan = WarehouseRestockPlan::query()->create([
            'warehouse_id' => $warehouse->id,
            'reference' => 'RP-WORKER-001',
            'status' => 'confirmed',
            'source_checksum' => hash('sha256', 'worker-source'),
            'snapshot_checksum' => hash('sha256', 'worker-snapshot'),
            'rule_snapshot' => ['history_days' => 120],
            'summary_snapshot' => ['snapshot_products' => 1, 'final_units' => 10],
            'confirmed_at' => now(),
        ]);

        $plan->items()->create([
            'product_id' => 991001,
            'product_sku' => 'VC549A',
            'label_sku' => 'C549A',
            'label_description' => '磁吸穿戴裸机玫红色成品 APP款',
            'product_name' => 'VC549A',
            'final_restock_qty' => 10,
            'formula_restock_qty' => 10,
            'manual_restock_qty' => 0,
            'shipped_120d_qty' => 10,
            'quantity_available' => 0,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'weight_source' => '待补充',
            'sort_order' => 0,
        ]);

        return [$warehouse, $plan->fresh('items')];
    }

    private function manifest(string $reference, WarehouseRestockPlan $plan): array
    {
        $hashA = str_repeat('a', 64);
        $hashB = str_repeat('b', 64);

        return [
            'schema_version' => 'byl.blue-label.output.v1',
            'generator_version' => 'blue-label-runtime-v1',
            'job_id' => $reference,
            'plan_reference' => $plan->reference,
            'plan_snapshot_checksum' => $plan->snapshot_checksum,
            'template_version' => 'blue-label-picture-master-v1',
            'template_sha256' => str_repeat('c', 64),
            'source_job_sha256' => str_repeat('d', 64),
            'generated_at_utc' => now()->toIso8601String(),
            'runtime' => ['bartender' => 'BT_OK'],
            'artifacts' => [[
                'product_sku' => 'VC549A',
                'label_sku' => 'C549A',
                'label_description' => '磁吸穿戴裸机玫红色成品 APP款',
                'restock_qty' => 10,
                'btw' => 'labels/C549A.btw',
                'pdf' => 'labels/C549A.pdf',
                'btw_sha256' => $hashA,
                'pdf_sha256' => $hashB,
                'label_size_mm' => [50, 30],
                'artwork_px' => [591, 354],
                'barcode' => 'Code128B',
            ]],
        ];
    }
}
