<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\WarehouseRestockProjection;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WarehouseRestockUnitsPerCartonTest extends TestCase
{
    use RefreshDatabase;

    public function test_restock_projection_reads_units_per_carton_from_product_packaging_profile(): void
    {
        $warehouse = Warehouse::create(['slug'=>'test-restock','code'=>'TEST-RESTOCK','name'=>'Test Restock']);
        $product = Product::create(['sku'=>'CARTON-24','name'=>'Carton Product','is_active'=>true]);
        ProductPackagingProfile::create([
            'product_id'=>$product->id,
            'custom_method'=>'整箱海运',
            'units_per_carton'=>24,
            'is_active'=>true,
        ]);

        $projection = app(WarehouseRestockProjection::class)->forWarehouse($warehouse);
        $row = collect($projection['rows'])->firstWhere('product_id', $product->id);

        $this->assertNotNull($row);
        $this->assertSame(24, $row['units_per_carton']);
    }
}
