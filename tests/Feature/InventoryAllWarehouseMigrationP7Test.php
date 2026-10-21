<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\WarehouseMasterRegistry;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class InventoryAllWarehouseMigrationP7Test extends TestCase
{
    use RefreshDatabase;

    public function test_all_legacy_inventory_aliases_redirect_to_canonical_public_slug(): void
    {
        $user=$this->userWith(['inventory.view']);
        foreach([
            'us-moreplus'=>'us-la',
            'us-sm'=>'us-3sm',
            'us-sj'=>'us-3sm',
            'usa-sj'=>'us-3sm',
            'de-tianyun'=>'de',
        ] as $legacy=>$canonical){
            $this->actingAs($user)->get('/inventory/'.$legacy)->assertRedirect('/inventory/'.$canonical);
        }
    }

    public function test_product_inventory_accepts_legacy_filter_but_outputs_only_canonical_slug(): void
    {
        $user=$this->userWith(['inventory.view']);
        $warehouse=Warehouse::query()->where('code','US-LA')->firstOrFail();
        $product=Product::create(['sku'=>'P7-CANONICAL','name'=>'P7 Canonical Product','source_type'=>'manual','product_type'=>'simple']);
        WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity_on_hand'=>9,'quantity_reserved'=>1]);

        $this->actingAs($user)->get('/inventory/products?warehouse=us-moreplus')->assertRedirect('/inventory/products?warehouse=us-la');
        $this->actingAs($user)->get('/inventory/products?warehouse=us-la')->assertOk()->assertInertia(fn(Assert $page)=>$page
            ->component('Inventory/Products')
            ->where('filters.warehouse','us-la')
            ->has('rows.data',1)
            ->where('rows.data.0.warehouse.slug','us-la')
            ->where('rows.data.0.warehouse.public_slug','us-la')
            ->where('rows.data.0.product.sku','P7-CANONICAL'));
    }

    public function test_warehouse_master_public_slugs_and_aliases_are_collision_free(): void
    {
        $registry=app(WarehouseMasterRegistry::class);
        $expected=['US-NY'=>'us-ny','US-LA'=>'us-la','US-SM'=>'us-3sm','DE'=>'de','CA'=>'ca','FACTORY'=>'factory','007'=>'007'];
        $seen=[];
        foreach($expected as $code=>$publicSlug){
            $config=$registry->forCode($code);
            $this->assertSame($publicSlug,$registry->publicSlugForCode($code,'fallback'));
            foreach(array_unique([$publicSlug,...(array)($config['legacy_slugs']??[])]) as $alias){
                $key=strtolower((string)$alias);
                $this->assertArrayNotHasKey($key,$seen,"Warehouse alias collision: {$alias}");
                $seen[$key]=$code;
                $this->assertSame($code,$registry->codeForSlug((string)$alias));
            }
        }
    }

    private function userWith(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'inventory-p7-'.uniqid(),'label'=>'Inventory P7']);
        foreach($permissions as $key){$permission=Permission::firstOrCreate(['key'=>$key],['label'=>$key]);$role->permissions()->syncWithoutDetaching([$permission->id]);}
        $user->roles()->attach($role);
        return $user;
    }
}
