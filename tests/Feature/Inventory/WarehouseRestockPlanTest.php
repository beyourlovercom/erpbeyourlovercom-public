<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\WarehouseRestockPlanService;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WarehouseRestockPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_snapshot_freezes_label_and_packaging_data_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::create(['slug'=>'restock-plan-a','code'=>'T-RP-A','name'=>'US SM']);
        $product = Product::create([
            'sku'=>'VC549A',
            'label_sku'=>'C549A',
            'label_description'=>'C549A Magnetic Wearable APP',
            'name'=>'VC549A 磁吸穿戴裸机玫红色成品 APP款',
            'is_active'=>true,
            'shipping_weight_kg'=>0.2500,
        ]);
        WarehouseInventory::create([
            'warehouse_id'=>$warehouse->id,
            'product_id'=>$product->id,
            'quantity_on_hand'=>2,
            'quantity_reserved'=>0,
            'expected_restock_qty'=>10,
        ]);
        $measurement = PackagingMeasurementRecord::create([
            'product_id'=>$product->id,
            'warehouse_id'=>$warehouse->id,
            'status'=>'approved',
            'packaging_method_key'=>'carton',
            'packaging_length_cm'=>20,
            'packaging_width_cm'=>10,
            'packaging_height_cm'=>5,
            'packaged_weight_kg'=>0.300,
            'measured_at'=>'2026-09-20 08:00:00',
            'created_by'=>$user->id,
            'reviewed_by'=>$user->id,
            'reviewed_at'=>'2026-09-20 08:10:00',
        ]);
        $profile = ProductPackagingProfile::create([
            'product_id'=>$product->id,
            'current_measurement_record_id'=>$measurement->id,
            'preset_key'=>'carton',
            'units_per_carton'=>4,
            'is_active'=>true,
        ]);

        $service = app(WarehouseRestockPlanService::class);
        $first = $service->confirm($warehouse, $user);
        $item = $first->items->firstOrFail();

        $this->assertSame('C549A', $item->label_sku);
        $this->assertSame('C549A Magnetic Wearable APP', $item->label_description);
        $this->assertSame(10, $item->final_restock_qty);
        $this->assertSame(4, $item->units_per_carton);
        $this->assertSame(3, $item->carton_count);
        $this->assertSame('20.00', $item->packaging_length_cm);
        $this->assertSame('0.300', $item->packaged_weight_kg);
        $this->assertSame('0.2500', $item->logistics_weight_kg);
        $this->assertSame($measurement->id, $item->packaging_measurement_record_id);
        $this->assertSame($profile->id, $item->packaging_profile_id);

        $same = $service->confirm($warehouse, $user);
        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('warehouse_restock_plans', 1);
        $this->assertDatabaseCount('warehouse_restock_plan_items', 1);

        $product->update([
            'label_sku'=>'VC549A-NEW',
            'label_description'=>'NEW DESCRIPTION',
            'name'=>'Changed Product Name',
        ]);
        $profile->update(['units_per_carton'=>6]);
        $measurement->update(['packaging_length_cm'=>99, 'packaged_weight_kg'=>0.999]);
        WarehouseInventory::where('warehouse_id',$warehouse->id)
            ->where('product_id',$product->id)
            ->update(['expected_restock_qty'=>15]);

        $frozen = $first->items()->firstOrFail();
        $this->assertSame('C549A', $frozen->label_sku);
        $this->assertSame(4, $frozen->units_per_carton);
        $this->assertSame('20.00', $frozen->packaging_length_cm);
        $this->assertSame('0.300', $frozen->packaged_weight_kg);

        $changed = $service->confirm($warehouse, $user);
        $this->assertNotSame($first->id, $changed->id);
        $this->assertDatabaseCount('warehouse_restock_plans', 2);
        $changedItem = $changed->items->firstOrFail();
        $this->assertSame('VC549A-NEW', $changedItem->label_sku);
        $this->assertSame(15, $changedItem->final_restock_qty);
        $this->assertSame(6, $changedItem->units_per_carton);
        $this->assertSame(3, $changedItem->carton_count);
        $this->assertSame('99.00', $changedItem->packaging_length_cm);
        $this->assertSame('0.999', $changedItem->packaged_weight_kg);
    }

    public function test_snapshot_only_contains_products_with_final_restock_quantity(): void
    {
        $warehouse = Warehouse::create(['slug'=>'restock-plan-b','code'=>'T-RP-B','name'=>'US NY']);
        $needed = Product::create(['sku'=>'M1','name'=>'M1','is_active'=>true]);
        Product::create(['sku'=>'NO-RESTOCK','name'=>'No Restock','is_active'=>true]);

        WarehouseInventory::create([
            'warehouse_id'=>$warehouse->id,
            'product_id'=>$needed->id,
            'quantity_on_hand'=>0,
            'quantity_reserved'=>0,
            'expected_restock_qty'=>5,
        ]);

        $plan = app(WarehouseRestockPlanService::class)->confirm($warehouse, null);

        $this->assertCount(1, $plan->items);
        $this->assertSame('M1', $plan->items->first()->product_sku);
        $this->assertSame('M1', $plan->items->first()->label_sku);
        $this->assertSame(5, $plan->items->first()->final_restock_qty);
    }

    public function test_confirmation_blocks_restock_product_without_a_canonical_sku(): void
    {
        $warehouse = Warehouse::create(['slug'=>'restock-plan-c','code'=>'T-RP-C','name'=>'Canada']);
        $product = Product::create(['sku'=>null,'label_sku'=>'LABEL-ONLY','name'=>'No SKU','is_active'=>true]);
        WarehouseInventory::create([
            'warehouse_id'=>$warehouse->id,
            'product_id'=>$product->id,
            'quantity_on_hand'=>0,
            'quantity_reserved'=>0,
            'expected_restock_qty'=>1,
        ]);

        try {
            app(WarehouseRestockPlanService::class)->confirm($warehouse, null);
            $this->fail('Expected missing canonical SKU to block plan confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('restock_plan', $e->errors());
        }

        $this->assertDatabaseCount('warehouse_restock_plans', 0);
    }

    public function test_product_service_rejects_label_identity_collision_with_other_canonical_sku(): void
    {
        Product::create(['sku'=>'M1','name'=>'M1','is_active'=>true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('蓝标 SKU 已被其他产品的 SKU 使用');

        app(ProductService::class)->createManual([
            'sku'=>'OTHER-1',
            'label_sku'=>'M1',
            'name'=>'Other Product',
            'product_type'=>'simple',
            'is_active'=>true,
        ]);
    }
}
