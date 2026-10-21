<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class InventoryMultiWarehouseTemplateP6Test extends TestCase
{
    use RefreshDatabase;

    public function test_all_canonical_warehouses_share_one_detail_contract_and_only_us_la_has_bill_import_capability(): void
    {
        $user=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        foreach(['us-ny','us-la','us-3sm','de','ca','factory','007'] as $slug){
            $this->actingAs($user)->get('/inventory/'.$slug)->assertOk()->assertInertia(function(Assert $page) use($slug): void {
                $page->component('Inventory/WarehouseShow')
                    ->where('warehouse.public_slug',$slug)
                    ->has('costCenter.months',3)
                    ->has('clearanceProducts')
                    ->has('clearanceCandidates');
                if($slug==='us-la'){
                    $page->where('warehouse.bill_import.provider','moreplus')
                        ->where('warehouse.bill_import.label','MorePlus')
                        ->where('warehouse.bill_import.extension','xlsx')
                        ->where('warehouse.bill_import.format_label','XLSX');
                } else {
                    $page->where('warehouse.bill_import',null);
                }
            });
        }
    }

    public function test_non_capability_warehouse_bill_import_routes_fail_closed(): void
    {
        $user=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        $this->actingAs($user)->post('/inventory/us-ny/costs/import/preview')->assertNotFound();
        $this->actingAs($user)->post('/inventory/us-ny/costs/import/apply')->assertNotFound();
        $this->assertDatabaseCount('warehouse_bill_import_batches',0);
    }

    public function test_moreplus_legacy_slug_redirects_to_canonical_inventory_url(): void
    {
        $user=$this->userWith(['inventory.view']);
        $this->actingAs($user)->get('/inventory/us-moreplus')->assertRedirect('/inventory/us-la');
    }

    private function userWith(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'inventory-p6-'.uniqid(),'label'=>'Inventory P6']);
        foreach($permissions as $key){$permission=Permission::firstOrCreate(['key'=>$key],['label'=>$key]);$role->permissions()->syncWithoutDetaching([$permission->id]);}
        $user->roles()->attach($role);
        return $user;
    }
}
