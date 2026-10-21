<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\WarehouseRestockDocumentJobService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

final class BlueLabelPackageV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_worker_artifacts_finalize_into_pi_and_downloadable_package(): void
    {
        Storage::fake('local');
        config()->set('services.blue_label_worker.token', 'uat');
        config()->set('services.blue_label_worker.lease_seconds', 180);

        $warehouse = Warehouse::create([
            'slug' => 'package-v1-us-sm',
            'code' => 'PKG-USSM',
            'name' => 'Package V1 US-SM',
        ]);

        $plan = WarehouseRestockPlan::query()->create([
            'warehouse_id' => $warehouse->id,
            'reference' => 'RP-PACKAGE-001',
            'status' => 'confirmed',
            'source_checksum' => hash('sha256', 'package-source'),
            'snapshot_checksum' => hash('sha256', 'package-snapshot'),
            'rule_snapshot' => ['history_days' => 120],
            'summary_snapshot' => ['snapshot_products' => 2, 'final_units' => 13],
            'confirmed_at' => '2026-09-22 10:00:00',
        ]);

        $plan->items()->create([
            'product_id' => 880001,
            'product_sku' => 'VC549A',
            'label_sku' => 'C549A',
            'label_description' => 'C549A 磁吸穿戴',
            'product_name' => 'VC549A 磁吸穿戴裸机玫红色成品 APP款',
            'final_restock_qty' => 10,
            'formula_restock_qty' => 10,
            'manual_restock_qty' => 0,
            'shipped_120d_qty' => 12,
            'quantity_available' => 2,
            'quantity_on_hand' => 2,
            'quantity_reserved' => 0,
            'units_per_carton' => 5,
            'carton_count' => 2,
            'packaging_length_cm' => '20.00',
            'packaging_width_cm' => '10.00',
            'packaging_height_cm' => '5.00',
            'packaged_weight_kg' => '0.300',
            'weight_source' => '包装档案',
            'sort_order' => 0,
        ]);

        $plan->items()->create([
            'product_id' => 880002,
            'product_sku' => 'CA755B',
            'label_sku' => 'CA755B',
            'label_description' => 'CA755B DOMINUS',
            'product_name' => 'CA755B DOMINUS',
            'final_restock_qty' => 3,
            'formula_restock_qty' => 3,
            'manual_restock_qty' => 0,
            'shipped_120d_qty' => 3,
            'quantity_available' => 0,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'units_per_carton' => null,
            'carton_count' => null,
            'packaged_weight_kg' => null,
            'weight_source' => '待补充',
            'sort_order' => 1,
        ]);

        $service = app(WarehouseRestockDocumentJobService::class);
        $created = $service->requestBlueLabels($warehouse, $plan->id, null);
        $job = $service->claimNext('company-win-label-01');
        $this->assertNotNull($job);
        $job = $service->start($job->reference, 'company-win-label-01');

        $manifestArtifacts = [];
        foreach ([
            ['product_sku' => 'VC549A', 'label_sku' => 'C549A', 'qty' => 10],
            ['product_sku' => 'CA755B', 'label_sku' => 'CA755B', 'qty' => 3],
        ] as $row) {
            $btw = 'BTW-'.$row['label_sku'].'-'.str_repeat('x', 1200);
            $pdf = '%PDF-1.4 '.$row['label_sku'].' '.str_repeat('p', 1200);

            foreach (['btw' => $btw, 'pdf' => $pdf] as $kind => $bytes) {
                $service->storeArtifact(
                    $job->reference,
                    'company-win-label-01',
                    $kind,
                    $row['label_sku'],
                    UploadedFile::fake()->createWithContent($row['label_sku'].'.'.$kind, $bytes),
                    hash('sha256', $bytes),
                );
            }

            $manifestArtifacts[] = [
                'product_sku' => $row['product_sku'],
                'label_sku' => $row['label_sku'],
                'label_description' => $row['label_sku'],
                'restock_qty' => $row['qty'],
                'btw' => 'labels/'.$row['label_sku'].'.btw',
                'pdf' => 'labels/'.$row['label_sku'].'.pdf',
                'btw_sha256' => hash('sha256', $btw),
                'pdf_sha256' => hash('sha256', $pdf),
                'label_size_mm' => [50, 30],
                'artwork_px' => [591, 354],
                'barcode' => 'Code128B',
            ];
        }

        $manifest = [
            'schema_version' => 'byl.blue-label.output.v1',
            'generator_version' => 'blue-label-runtime-v1',
            'job_id' => $job->reference,
            'plan_reference' => $plan->reference,
            'plan_snapshot_checksum' => $plan->snapshot_checksum,
            'template_version' => 'blue-label-picture-master-v1',
            'template_sha256' => str_repeat('c', 64),
            'source_job_sha256' => str_repeat('d', 64),
            'generated_at_utc' => now()->toIso8601String(),
            'runtime' => ['bartender' => 'BT_OK'],
            'artifacts' => $manifestArtifacts,
        ];

        $done = $service->complete($job->reference, 'company-win-label-01', $manifest, 'C:\\worker\\output');
        $this->assertSame('succeeded', $done->status);
        $this->assertSame('inventory/restock-documents/'.$job->reference, $done->output_root);

        $root = 'inventory/restock-documents/'.$job->reference;
        Storage::disk('local')->assertExists($root.'/PI.xlsx');
        Storage::disk('local')->assertExists($root.'/'.$job->reference.'.zip');
        Storage::disk('local')->assertExists($root.'/blue-labels/C549A.btw');
        Storage::disk('local')->assertExists($root.'/sku-labels/C549A.pdf');

        $piPath = Storage::disk('local')->path($root.'/PI.xlsx');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($piPath) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('r="B5"', $sheet);
        $this->assertStringContainsString('C549A', $sheet);
        $this->assertStringContainsString('VC549A 磁吸穿戴裸机玫红色成品 APP款', $sheet);
        $this->assertStringContainsString('20×10×5 cm', $sheet);
        $this->assertStringContainsString('<f>SUM(D5:D22)</f><v>13</v>', $sheet);

        $package = new ZipArchive();
        $this->assertTrue($package->open(Storage::disk('local')->path($root.'/'.$job->reference.'.zip')) === true);
        $names = [];
        for ($i = 0; $i < $package->numFiles; $i++) $names[] = $package->getNameIndex($i);
        $package->close();

        $this->assertContains('PI.xlsx', $names);
        $this->assertContains('manifest.json', $names);
        $this->assertContains('蓝标/C549A.btw', $names);
        $this->assertContains('sku标签/C549A.pdf', $names);
        $this->assertContains('蓝标/CA755B.btw', $names);
        $this->assertContains('sku标签/CA755B.pdf', $names);

        $payload = $service->payload($done->fresh());
        $this->assertTrue($payload['has_pi']);
        $this->assertTrue($payload['has_package']);
    }

    public function test_worker_artifact_http_upload_validates_sha_and_worker_ownership(): void
    {
        Storage::fake('local');
        config()->set('services.blue_label_worker.token', 'uat');
        config()->set('services.blue_label_worker.lease_seconds', 180);

        $warehouse = Warehouse::create([
            'slug' => 'package-http-us-sm',
            'code' => 'PKG-HTTP',
            'name' => 'Package HTTP US-SM',
        ]);
        $plan = WarehouseRestockPlan::query()->create([
            'warehouse_id' => $warehouse->id,
            'reference' => 'RP-PACKAGE-HTTP-001',
            'status' => 'confirmed',
            'source_checksum' => hash('sha256', 'package-http-source'),
            'snapshot_checksum' => hash('sha256', 'package-http-snapshot'),
            'rule_snapshot' => ['history_days' => 120],
            'summary_snapshot' => ['snapshot_products' => 1, 'final_units' => 1],
            'confirmed_at' => now(),
        ]);
        $plan->items()->create([
            'product_id' => 880101,
            'product_sku' => 'CA755B',
            'label_sku' => 'CA755B',
            'label_description' => 'CA755B DOMINUS',
            'product_name' => 'CA755B DOMINUS',
            'final_restock_qty' => 1,
            'formula_restock_qty' => 1,
            'manual_restock_qty' => 0,
            'shipped_120d_qty' => 1,
            'quantity_available' => 0,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'weight_source' => '待补充',
            'sort_order' => 0,
        ]);

        $service = app(WarehouseRestockDocumentJobService::class);
        $created = $service->requestBlueLabels($warehouse, $plan->id, null);
        $job = $service->claimNext('company-win-label-01');
        $job = $service->start($job->reference, 'company-win-label-01');

        $bytes = 'BTW-HTTP-'.str_repeat('x', 1200);
        $sha = hash('sha256', $bytes);
        $uri = '/api/internal/blue-label-worker/jobs/'.$job->reference.'/artifacts/btw/CA755B';

        $this->post($uri, [
            'file' => UploadedFile::fake()->createWithContent('CA755B.btw', $bytes),
        ], [
            'Authorization' => 'Bearer uat',
            'X-BYL-Worker-ID' => 'other-worker-01',
            'X-Content-SHA256' => $sha,
            'Accept' => 'application/json',
        ])->assertStatus(422);

        $this->post($uri, [
            'file' => UploadedFile::fake()->createWithContent('CA755B.btw', $bytes),
        ], [
            'Authorization' => 'Bearer uat',
            'X-BYL-Worker-ID' => 'company-win-label-01',
            'X-Content-SHA256' => str_repeat('0', 64),
            'Accept' => 'application/json',
        ])->assertStatus(422);

        $this->post($uri, [
            'file' => UploadedFile::fake()->createWithContent('CA755B.btw', $bytes),
        ], [
            'Authorization' => 'Bearer uat',
            'X-BYL-Worker-ID' => 'company-win-label-01',
            'X-Content-SHA256' => $sha,
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('artifact.sha256', $sha);

        Storage::disk('local')->assertExists(
            'inventory/restock-documents/'.$created['job']->reference.'/blue-labels/CA755B.btw',
        );
    }

    public function test_pi_v1_rejects_more_than_eighteen_skus_before_job_creation(): void
    {
        $warehouse = Warehouse::create([
            'slug' => 'package-limit-us-sm',
            'code' => 'PKG-LIMIT',
            'name' => 'Package Limit US-SM',
        ]);
        $plan = WarehouseRestockPlan::query()->create([
            'warehouse_id' => $warehouse->id,
            'reference' => 'RP-PACKAGE-LIMIT-001',
            'status' => 'confirmed',
            'source_checksum' => hash('sha256', 'package-limit-source'),
            'snapshot_checksum' => hash('sha256', 'package-limit-snapshot'),
            'rule_snapshot' => ['history_days' => 120],
            'summary_snapshot' => ['snapshot_products' => 19, 'final_units' => 19],
            'confirmed_at' => now(),
        ]);

        for ($i = 1; $i <= 19; $i++) {
            $sku = 'SKU'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $plan->items()->create([
                'product_id' => 881000 + $i,
                'product_sku' => $sku,
                'label_sku' => $sku,
                'label_description' => $sku,
                'product_name' => $sku,
                'final_restock_qty' => 1,
                'formula_restock_qty' => 1,
                'manual_restock_qty' => 0,
                'shipped_120d_qty' => 1,
                'quantity_available' => 0,
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'weight_source' => '待补充',
                'sort_order' => $i,
            ]);
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('PI V1 最多支持 18 个 SKU');
        app(WarehouseRestockDocumentJobService::class)->requestBlueLabels($warehouse, $plan->id, null);
    }
}
