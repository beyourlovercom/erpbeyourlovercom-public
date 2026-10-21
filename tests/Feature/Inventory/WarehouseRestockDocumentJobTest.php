<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\WarehouseRestockDocumentJobService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockDocumentJob;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockPlan;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WarehouseRestockDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_blue_label_job_uses_frozen_plan_payload_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::create([
            'slug' => 'restock-doc-a',
            'code' => 'T-BLJ-A',
            'name' => 'Blue Label A',
        ]);
        $product = Product::create([
            'sku' => 'VC549A',
            'label_sku' => 'C549A',
            'label_description' => 'LIVE DESCRIPTION',
            'name' => 'Live Product',
            'is_active' => true,
        ]);
        $plan = $this->makePlan($warehouse, [[
            'product_id' => $product->id,
            'product_sku' => 'VC549A',
            'label_sku' => 'C549A',
            'label_description' => 'FROZEN DESCRIPTION',
            'quantity' => 10,
        ]]);

        $product->update([
            'label_sku' => 'VC549A-NEW',
            'label_description' => 'NEW LIVE DESCRIPTION',
            'name' => 'Changed Live Product',
        ]);

        $service = app(WarehouseRestockDocumentJobService::class);
        $first = $service->requestBlueLabels($warehouse, $plan->id, $user);
        $job = $first['job']->fresh();

        $this->assertTrue($first['created']);
        $this->assertSame('queued', $job->status);
        $this->assertSame('blue_labels', $job->document_kind);
        $this->assertSame('byl.blue-label.job.v1', $job->schema_version);
        $this->assertSame('blue-label-picture-master-v1', $job->template_version);
        $this->assertSame('blue-label-runtime-v1', $job->generator_version);
        $this->assertSame('codex_dc_windows_bartender_v1', $job->dispatch_mode);
        $this->assertSame($user->id, $job->requested_by);
        $this->assertSame($plan->snapshot_checksum, $job->plan_snapshot_checksum);

        $this->assertSame($job->reference, $job->payload['job_id']);
        $this->assertSame($plan->reference, $job->payload['plan_reference']);
        $this->assertSame('VC549A', $job->payload['items'][0]['product_sku']);
        $this->assertSame('C549A', $job->payload['items'][0]['label_sku']);
        $this->assertSame('FROZEN DESCRIPTION', $job->payload['items'][0]['label_description']);
        $this->assertSame(10, $job->payload['items'][0]['restock_qty']);

        $expectedChecksum = hash('sha256', json_encode(
            $job->payload,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ));
        $this->assertSame($expectedChecksum, $job->payload_checksum);

        $same = $service->requestBlueLabels($warehouse, $plan->id, $user);
        $this->assertFalse($same['created']);
        $this->assertSame($job->id, $same['job']->id);
        $this->assertDatabaseCount('warehouse_restock_document_jobs', 1);

        $projection = $service->forPlanId($plan->id);
        $this->assertSame($job->reference, $projection['reference']);
        $this->assertSame('已排队', $projection['status_label']);
        $this->assertSame('等待公司 Windows 执行节点领取', $projection['status_detail']);
        $this->assertFalse($projection['is_terminal']);
        $this->assertSame(0, $projection['artifact_count']);
    }

    public function test_wrong_warehouse_cannot_queue_a_plan(): void
    {
        $warehouseA = Warehouse::create(['slug'=>'restock-doc-b1','code'=>'T-BLJ-B1','name'=>'A']);
        $warehouseB = Warehouse::create(['slug'=>'restock-doc-b2','code'=>'T-BLJ-B2','name'=>'B']);
        $plan = $this->makePlan($warehouseA, [[
            'product_sku'=>'M1','label_sku'=>'M1','label_description'=>'M1','quantity'=>5,
        ]]);

        try {
            app(WarehouseRestockDocumentJobService::class)->requestBlueLabels($warehouseB, $plan->id, null);
            $this->fail('Expected warehouse mismatch to fail closed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('blue_label_job', $e->errors());
        }

        $this->assertDatabaseCount('warehouse_restock_document_jobs', 0);
    }

    public function test_duplicate_label_sku_in_frozen_plan_fails_before_job_creation(): void
    {
        $warehouse = Warehouse::create(['slug'=>'restock-doc-c','code'=>'T-BLJ-C','name'=>'C']);
        $plan = $this->makePlan($warehouse, [
            ['product_id'=>9101,'product_sku'=>'VC549A','label_sku'=>'C549A','label_description'=>'One','quantity'=>10],
            ['product_id'=>9102,'product_sku'=>'OTHER','label_sku'=>'C549A','label_description'=>'Two','quantity'=>2],
        ]);

        try {
            app(WarehouseRestockDocumentJobService::class)->requestBlueLabels($warehouse, $plan->id, null);
            $this->fail('Expected duplicate label SKU to fail closed.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('重复蓝标 SKU', $e->errors()['blue_label_job'][0]);
        }

        $this->assertDatabaseCount('warehouse_restock_document_jobs', 0);
    }

    private function makePlan(Warehouse $warehouse, array $items): WarehouseRestockPlan
    {
        static $sequence = 0;
        $sequence++;
        $snapshotChecksum = hash('sha256', $warehouse->code.'|'.$sequence);

        $plan = WarehouseRestockPlan::query()->create([
            'warehouse_id' => $warehouse->id,
            'reference' => sprintf('RP-TEST-%s-%03d', $warehouse->code, $sequence),
            'status' => 'confirmed',
            'source_checksum' => hash('sha256', 'source|'.$sequence),
            'snapshot_checksum' => $snapshotChecksum,
            'rule_snapshot' => ['history_days'=>120],
            'summary_snapshot' => [
                'snapshot_products'=>count($items),
                'final_units'=>array_sum(array_map(fn(array $item): int => (int)$item['quantity'], $items)),
            ],
            'confirmed_at' => now(),
        ]);

        foreach ($items as $index => $item) {
            $quantity = (int) $item['quantity'];
            $plan->items()->create([
                'product_id' => $item['product_id'] ?? 9000 + $index + $sequence * 10,
                'product_sku' => $item['product_sku'],
                'label_sku' => $item['label_sku'],
                'label_description' => $item['label_description'],
                'product_name' => $item['product_sku'],
                'final_restock_qty' => $quantity,
                'formula_restock_qty' => $quantity,
                'manual_restock_qty' => 0,
                'shipped_120d_qty' => $quantity,
                'quantity_available' => 0,
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'weight_source' => '待补充',
                'sort_order' => $index,
            ]);
        }

        return $plan->fresh('items');
    }
}
