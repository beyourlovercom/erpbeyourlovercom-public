<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryFoundationM1Test extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_requires_view_permission(): void
    {
        $user=$this->userWith([]);
        $this->actingAs($user)->get('/inventory')->assertForbidden();
    }

    public function test_seven_canonical_warehouses_are_seeded_without_fake_addresses(): void
    {
        $this->assertSame([
            '007'=>'007 本地仓库','ca'=>'加拿大仓','de'=>'德国天运仓','factory'=>'中国工厂','us-3sm'=>'美国3世迈仓','us-la'=>'美西 MorePlus 仓','us-ny'=>'美东纽约仓',
        ], Warehouse::query()->orderBy('slug')->pluck('name','slug')->all());
        $this->assertSame(0, Warehouse::query()->whereNotNull('address_line1')->count());
        $this->assertSame(6, Warehouse::query()->where('ownership_type', 'owned')->count());
        $this->assertSame(1, Warehouse::query()->where('ownership_type', 'external')->count());
        $this->assertSame(7, Warehouse::query()->where('is_active', true)->count());
    }

    public function test_inventory_dashboard_list_products_and_detail_routes_are_real(): void
    {
        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->get('/inventory')->assertOk()->assertInertia(fn($p)=>$p->component('Inventory/Dashboard'));
        $this->actingAs($user)->get('/inventory/list')->assertOk()->assertInertia(fn($p)=>$p->component('Inventory/WarehouseList'));
        $this->actingAs($user)->get('/inventory/products')->assertOk()->assertInertia(fn($p)=>$p->component('Inventory/Products'));
        $this->actingAs($user)->get('/inventory/us-la')->assertOk()->assertInertia(fn($p)=>$p->component('Inventory/WarehouseShow')->where('warehouse.slug','us-la'));
        $this->actingAs($user)->get('/inventory/us-3sm')->assertOk()->assertInertia(fn($p)=>$p
            ->component('Inventory/WarehouseShow')
            ->where('warehouse.code','US-SM')
            ->where('warehouse.name','美国3世迈仓')
            ->where('warehouse.public_slug','us-3sm'));
        $this->actingAs($user)->get('/inventory/warehouses')->assertRedirect('/inventory/list');
    }


    public function test_dashboard_exposes_seven_master_warehouses_and_six_fulfillment_warehouses(): void
    {
        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->get('/inventory')->assertOk()->assertInertia(fn($p)=>$p
            ->where('summary.warehouse_total_count',7)
            ->where('summary.fulfillment_warehouse_count',6)
            ->where('summary.inventory_only_warehouse_count',1)
            ->has('summary.warehouses',7));
    }

    public function test_view_only_user_cannot_mutate_inventory_or_warehouse(): void
    {
        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->get('/inventory/add')->assertForbidden();
        $this->actingAs($user)->post('/inventory/us-la/stock',[])->assertForbidden();
        $this->actingAs($user)->patch('/inventory/us-la/billing',['billing_markdown'=>'x'])->assertForbidden();
    }

    public function test_warehouse_manager_can_create_real_warehouse_with_nullable_address_and_audit(): void
    {
        $user=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        $this->actingAs($user)->post('/inventory',[
            'slug'=>'test-wh','code'=>'TEST-WH','name'=>'测试仓库','country_code'=>'US','type'=>'warehouse','ownership_type'=>'external','is_active'=>true,'priority'=>90,
            'address_line1'=>'100 Test Ave','city'=>'Test City','state_region'=>'CA','postal_code'=>'90001','timezone'=>'America/Los_Angeles',
        ])->assertRedirect();
        $this->assertDatabaseHas('warehouses',['slug'=>'test-wh','code'=>'test-wh','name'=>'测试仓库','ownership_type'=>'external','address_line1'=>'100 Test Ave']);
        $this->assertDatabaseHas('audit_records',['action'=>'warehouse.created']);
    }

    public function test_warehouse_fulfillment_date_is_editable_and_future_date_blocks_auto_allocation(): void
    {
        $user=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        InventorySyncBatch::query()->create([
            'warehouse_id'=>$warehouse->id,'source_type'=>'csv','sync_mode'=>'full','status'=>'confirmed','confirmed_at'=>now(),
        ]);

        $this->actingAs($user)->patch('/inventory/us-la',[
            'name'=>$warehouse->name,'code'=>$warehouse->code,'country_code'=>'US',
            'address_line1'=>$warehouse->address_line1,'address_line2'=>$warehouse->address_line2,
            'city'=>$warehouse->city,'state_region'=>$warehouse->state_region,'postal_code'=>$warehouse->postal_code,
            'timezone'=>'America/Los_Angeles','type'=>$warehouse->type,'ownership_type'=>$warehouse->ownership_type,
            'is_domestic'=>false,'is_active'=>true,'priority'=>$warehouse->priority,
            'fulfillment_available_on'=>now()->addWeek()->toDateString(),
        ])->assertRedirect();

        $row=collect(app(InventoryAvailabilityReader::class)->activeWarehouses())->firstWhere('id',$warehouse->id);
        $this->assertFalse($row['can_auto_allocate']);
        $this->assertSame(now()->addWeek()->toDateString(),$row['fulfillment_available_on']);
        $this->actingAs($user)->get('/inventory/us-la')->assertOk()->assertInertia(fn($p)=>$p
            ->where('warehouse.fulfillment_state','preparing')
            ->where('warehouse.fulfillment_state_label','备货中'));
    }

    public function test_fulfillment_date_does_not_open_without_full_snapshot_and_opens_after_snapshot(): void
    {
        $warehouse=Warehouse::query()->where('slug','us-ny')->firstOrFail();
        $warehouse->update(['fulfillment_available_on'=>now()->subDay()->toDateString()]);

        $row=collect(app(InventoryAvailabilityReader::class)->activeWarehouses())->firstWhere('id',$warehouse->id);
        $this->assertFalse($row['can_auto_allocate']);
        $list=collect(app(\App\Modules\Inventory\Application\InventoryService::class)->warehouses())->firstWhere('id',$warehouse->id);
        $this->assertSame('awaiting_inventory',$list['fulfillment_state']);

        InventorySyncBatch::query()->create([
            'warehouse_id'=>$warehouse->id,'source_type'=>'csv','sync_mode'=>'full','status'=>'confirmed','confirmed_at'=>now(),
        ]);
        $row=collect(app(InventoryAvailabilityReader::class)->activeWarehouses())->firstWhere('id',$warehouse->id);
        $this->assertTrue($row['can_auto_allocate']);
        $list=collect(app(\App\Modules\Inventory\Application\InventoryService::class)->warehouses())->firstWhere('id',$warehouse->id);
        $this->assertSame('ready',$list['fulfillment_state']);
    }

    public function test_warehouse_manager_can_explicitly_configure_domestic_fulfillment_authority(): void
    {
        $user=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        $warehouse=Warehouse::query()->where('slug','factory')->firstOrFail();
        $this->assertFalse((bool)$warehouse->is_domestic);

        $this->actingAs($user)->patch('/inventory/factory',[
            'name'=>$warehouse->name,
            'code'=>$warehouse->code,
            'country_code'=>'CN',
            'address_line1'=>$warehouse->address_line1,
            'address_line2'=>$warehouse->address_line2,
            'city'=>$warehouse->city,
            'state_region'=>$warehouse->state_region,
            'postal_code'=>$warehouse->postal_code,
            'timezone'=>$warehouse->timezone,
            'type'=>$warehouse->type,
            'ownership_type'=>$warehouse->ownership_type,
            'is_domestic'=>true,
            'is_active'=>true,
            'priority'=>$warehouse->priority,
        ])->assertRedirect();

        $this->assertTrue((bool)$warehouse->fresh()->is_domestic);
        $this->assertDatabaseHas('audit_records',['action'=>'warehouse.updated']);
    }

    public function test_stock_links_to_canonical_product_id_and_available_is_computed(): void
    {
        $product=Product::create(['sku'=>'INV-001','name'=>'Inventory Product','source_type'=>'manual','product_type'=>'simple']);
        $user=$this->userWith(['inventory.view','inventory.operate']);
        $this->actingAs($user)->post('/inventory/us-la/stock',[
            'product_id'=>$product->id,'quantity_on_hand'=>12,'quantity_reserved'=>5,'inbound_at'=>'2026-08-20','location'=>'A-01','standard_qoh'=>10,'expected_restock_qty'=>4,
        ])->assertRedirect();
        $item=WarehouseInventory::query()->where('product_id',$product->id)->firstOrFail();
        $this->assertSame(7,$item->quantity_available);
        $this->assertDatabaseHas('warehouse_inventory',['product_id'=>$product->id,'quantity_on_hand'=>12,'quantity_reserved'=>5,'location'=>'A-01']);
        $this->assertDatabaseHas('inventory_adjustments',['product_id'=>$product->id,'quantity_before'=>0,'quantity_after'=>12]);
        $this->assertDatabaseHas('audit_records',['action'=>'inventory.stock_saved']);
    }

    public function test_product_inventory_page_uses_product_master_sku_and_calculates_stay_time(): void
    {
        $product=Product::create(['sku'=>'INV-STAY','name'=>'Stay Product','source_type'=>'manual','product_type'=>'simple']);
        $warehouse=Warehouse::query()->where('slug','de')->firstOrFail();
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity_on_hand'=>20,'quantity_reserved'=>2,'inbound_at'=>now()->subDays(5),'expected_restock_qty'=>3]);
        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->get('/inventory/products?search=INV-STAY')->assertOk()->assertInertia(fn($p)=>$p
            ->where('rows.data.0.product.sku','INV-STAY')->where('rows.data.0.quantity_available',18)->where('rows.data.0.stay_days',5));
    }

    public function test_stock_status_uses_available_quantity_and_never_imported_warehouses_are_not_false_out_of_stock(): void
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $out=$this->product('STATUS-OUT');
        $low=$this->product('STATUS-LOW');
        $in=$this->product('STATUS-IN');
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$out->id,'quantity_on_hand'=>1,'quantity_reserved'=>0]);
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$low->id,'quantity_on_hand'=>11,'quantity_reserved'=>2]);
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$in->id,'quantity_on_hand'=>10,'quantity_reserved'=>0]);

        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->get('/inventory')->assertOk()->assertInertia(fn($p)=>$p
            ->where('summary.out_of_stock_rows',1)
            ->where('summary.low_stock_rows',1)
            ->where('summary.in_stock_rows',1)
            ->where('summary.stock_alert_rows',2));

        $this->actingAs($user)->get('/inventory/products?search=STATUS-LOW')->assertOk()->assertInertia(fn($p)=>$p
            ->where('rows.data.0.quantity_available',9)
            ->where('rows.data.0.stock_status','low_stock')
            ->where('rows.data.0.stock_status_label','库存紧张'));
    }

    public function test_replenishment_uses_recent_90_day_shipped_quantity_and_configured_warehouse_policy(): void
    {
        $warehouse=Warehouse::query()->where('slug','de')->firstOrFail();
        $warehouse->update([
            'replenishment_lead_time_days'=>5,
            'replenishment_safety_stock_days'=>3,
            'replenishment_target_cover_days'=>14,
        ]);
        $product=$this->product('REPLENISH-90D');
        WarehouseInventory::create([
            'warehouse_id'=>$warehouse->id,'product_id'=>$product->id,
            'quantity_on_hand'=>4,'quantity_reserved'=>1,'expected_restock_qty'=>1,
        ]);

        $recent=Order::create([
            'internal_order_number'=>'REPL-RECENT','source_type'=>'manual','order_kind'=>'customer',
            'order_date'=>now()->subDays(10)->toDateString(),'order_status'=>'shipped',
        ]);
        $recent->items()->create([
            'product_id'=>$product->id,'sku'=>$product->sku,'product_summary'=>$product->name,
            'quantity'=>90,'unit_price'=>'1.00','line_revenue'=>'90.00',
        ]);
        $recent->shipment()->create([
            'warehouse_id'=>$warehouse->id,'shipment_status'=>'completed','shipped_at'=>now()->subDays(10),
        ]);

        $old=Order::create([
            'internal_order_number'=>'REPL-OLD','source_type'=>'manual','order_kind'=>'customer',
            'order_date'=>now()->subDays(100)->toDateString(),'order_status'=>'shipped',
        ]);
        $old->items()->create([
            'product_id'=>$product->id,'sku'=>$product->sku,'product_summary'=>$product->name,
            'quantity'=>900,'unit_price'=>'1.00','line_revenue'=>'900.00',
        ]);
        $old->shipment()->create([
            'warehouse_id'=>$warehouse->id,'shipment_status'=>'completed','shipped_at'=>now()->subDays(100),
        ]);

        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->get('/inventory/products?search=REPLENISH-90D')->assertOk()->assertInertia(fn($p)=>$p
            ->where('rows.data.0.quantity_available',3)
            ->where('rows.data.0.replenishment.shipped_90d_qty',90)
            ->where('rows.data.0.replenishment.monthly_average',30)
            ->where('rows.data.0.replenishment.daily_average',1)
            ->where('rows.data.0.replenishment.days_of_cover',3)
            ->where('rows.data.0.replenishment.safety_stock_qty',3)
            ->where('rows.data.0.replenishment.reorder_point_qty',8)
            ->where('rows.data.0.replenishment.target_stock_qty',14)
            ->where('rows.data.0.replenishment.recommended_restock_qty',10)
            ->where('rows.data.0.replenishment.reorder_needed',true)
            ->where('rows.data.0.replenishment.policy_label','建议补货'));
    }

    public function test_replenishment_counts_historical_reship_attempts_as_real_dispatch_demand(): void
    {
        $warehouse=Warehouse::query()->where('slug','de')->firstOrFail();
        $warehouse->update([
            'replenishment_lead_time_days'=>5,
            'replenishment_safety_stock_days'=>3,
            'replenishment_target_cover_days'=>14,
        ]);
        $product=$this->product('REPLENISH-RESHIP');
        WarehouseInventory::create([
            'warehouse_id'=>$warehouse->id,'product_id'=>$product->id,
            'quantity_on_hand'=>100,'quantity_reserved'=>0,'expected_restock_qty'=>0,
        ]);
        $order=Order::create([
            'internal_order_number'=>'REPL-RESHIP','source_type'=>'manual','order_kind'=>'customer',
            'order_date'=>now()->subDays(20)->toDateString(),'order_status'=>'shipped',
        ]);
        $order->items()->create([
            'product_id'=>$product->id,'sku'=>$product->sku,'product_summary'=>$product->name,
            'quantity'=>30,'unit_price'=>'1.00','line_revenue'=>'30.00',
        ]);
        $shipment=$order->shipment()->create([
            'warehouse_id'=>$warehouse->id,'shipment_status'=>'shipped','shipped_at'=>now()->subDays(5),
        ]);
        ShipmentAttempt::create([
            'order_id'=>$order->id,'shipment_id'=>$shipment->id,'attempt_number'=>1,
            'shipment_status'=>'exception','warehouse_id'=>$warehouse->id,
            'shipped_at'=>now()->subDays(20),'snapshotted_at'=>now()->subDays(10),
        ]);

        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->get('/inventory/products?search=REPLENISH-RESHIP')->assertOk()->assertInertia(fn($p)=>$p
            ->where('rows.data.0.replenishment.shipped_90d_qty',60)
            ->where('rows.data.0.replenishment.monthly_average',20)
            ->where('rows.data.0.replenishment.daily_average',0.67));
    }

    public function test_replenishment_does_not_invent_policy_when_warehouse_policy_is_unconfigured(): void
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $product=$this->product('REPLENISH-NO-POLICY');
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity_on_hand'=>5,'quantity_reserved'=>0]);
        $user=$this->userWith(['inventory.view']);

        $this->actingAs($user)->get('/inventory/products?search=REPLENISH-NO-POLICY')->assertOk()->assertInertia(fn($p)=>$p
            ->where('rows.data.0.replenishment.policy_status','unconfigured')
            ->where('rows.data.0.replenishment.policy_label','补货策略未配置')
            ->where('rows.data.0.replenishment.recommended_restock_qty',null)
            ->where('rows.data.0.replenishment.reorder_needed',null));
    }

    public function test_billing_markdown_and_service_regions_are_editable_and_audited(): void
    {
        $user=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        $this->actingAs($user)->patch('/inventory/de/billing',['billing_markdown'=>"| KG | EUR |\n|---|---:|\n| 1 | 5 |"])->assertRedirect();
        $this->actingAs($user)->patch('/inventory/de/regions',['country_codes'=>['de','FR','DE']])->assertRedirect();
        $warehouse=Warehouse::query()->where('slug','de')->firstOrFail();
        $this->assertStringContainsString('| KG | EUR |',$warehouse->billing_markdown);
        $this->assertSame(['DE','FR'],$warehouse->serviceRegions()->orderBy('country_code')->pluck('country_code')->all());
        $this->assertDatabaseHas('audit_records',['action'=>'warehouse.billing_updated']);
        $this->assertDatabaseHas('audit_records',['action'=>'warehouse.service_regions_updated']);
    }

    public function test_inventory_product_search_does_not_require_products_view_permission(): void
    {
        Product::create(['sku'=>'SEARCH-INV','name'=>'Search Inventory','source_type'=>'manual','product_type'=>'simple']);
        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->getJson('/inventory/product-search?q=SEARCH')->assertOk()->assertJsonFragment(['sku'=>'SEARCH-INV']);
    }

    private function product(string $sku): Product
    {
        return Product::create(['sku'=>$sku,'name'=>$sku,'source_type'=>'manual','product_type'=>'simple']);
    }

    private function userWith(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'inventory-test-'.uniqid(),'label'=>'Inventory Test']);
        foreach($permissions as $key){$permission=Permission::firstOrCreate(['key'=>$key],['label'=>$key]);$role->permissions()->syncWithoutDetaching([$permission->id]);}
        $user->roles()->attach($role);
        return $user;
    }
}
