<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseCostEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryWarehouseCostCenterP3Test extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_record_view_and_remove_manual_warehouse_cost(): void
    {
        $this->travelTo('2026-09-16 09:00:00');
        $user = $this->userWith(['inventory.view','inventory.warehouses.manage']);

        $this->actingAs($user)->post('/inventory/us-la/costs',[
            'cost_month'=>'2026-09','category'=>'shipping','amount'=>'123.45','currency'=>'usd','note'=>'September shipping',
        ])->assertRedirect();

        $cost = WarehouseCostEntry::query()->firstOrFail();
        $this->assertSame('USD',$cost->currency);
        $this->assertSame('2026-09-01',$cost->cost_month->toDateString());
        $this->assertDatabaseHas('audit_records',['action'=>'warehouse.cost_recorded']);

        $this->actingAs($user)->get('/inventory/us-la')->assertOk()->assertInertia(fn($page)=>$page
            ->has('costCenter.months',3)
            ->where('costCenter.months.0.month','2026-09')
            ->where('costCenter.months.0.currencies.0.currency','USD')
            ->where('costCenter.months.0.currencies.0.total',123.45)
            ->where('costCenter.months.0.currencies.0.shipping',123.45)
            ->where('costCenter.months.0.entries.0.category','shipping'));

        $this->actingAs($user)->delete('/inventory/us-la/costs/'.$cost->id)->assertRedirect();
        $this->assertDatabaseMissing('warehouse_cost_entries',['id'=>$cost->id]);
        $this->assertDatabaseHas('audit_records',['action'=>'warehouse.cost_deleted']);
    }

    public function test_inventory_viewer_can_read_cost_center_but_cannot_write(): void
    {
        $user = $this->userWith(['inventory.view']);
        $warehouse = Warehouse::query()->where('slug','us-la')->firstOrFail();
        WarehouseCostEntry::query()->create(['warehouse_id'=>$warehouse->id,'cost_month'=>now()->startOfMonth(),'category'=>'storage','amount'=>50,'currency'=>'USD','source_type'=>'manual']);

        $this->actingAs($user)->get('/inventory/us-la')->assertOk()->assertInertia(fn($page)=>$page->where('costCenter.months.0.currencies.0.storage',50));
        $this->actingAs($user)->post('/inventory/us-la/costs',['cost_month'=>now()->format('Y-m'),'category'=>'shipping','amount'=>1,'currency'=>'USD'])->assertForbidden();
    }

    private function userWith(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'warehouse-cost-test-'.uniqid(),'label'=>'Warehouse Cost Test']);
        foreach($permissions as $key){$permission=Permission::firstOrCreate(['key'=>$key],['label'=>$key]);$role->permissions()->syncWithoutDetaching([$permission->id]);}
        $user->roles()->attach($role);
        return $user;
    }
}
