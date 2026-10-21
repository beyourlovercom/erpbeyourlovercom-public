<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportRow;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseBillProviderRegistry;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseBillReconciliationService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class InventoryWarehouseBillReconciliationV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_moreplus_and_rejects_unknown_provider(): void
    {
        $registry=app(WarehouseBillProviderRegistry::class);
        $this->assertSame(['moreplus','speeda-us-ny'],$registry->keys());
        $this->assertSame('moreplus',$registry->get('MOREPLUS')->key());
        $this->expectException(\InvalidArgumentException::class);
        $registry->get('unknown-provider');
    }

    public function test_exact_gate_rejects_drift_and_is_idempotent(): void
    {
        [$warehouse,$batch]=$this->fixture();
        $service=app(WarehouseBillReconciliationService::class);
        $preview=$service->preview($warehouse,$batch);
        $this->assertSame(1,$preview['shipping']['ready']);
        $this->assertSame(1,$preview['parcel_outbound']['ready']);
        try {
            $service->applyExact($warehouse,$batch,$preview['source_checksum'],str_repeat('f',64));
            $this->fail('Expected preflight mismatch.');
        } catch (RuntimeException $e) {
            $this->assertSame('warehouse_bill_reconciliation_preflight_mismatch',$e->getMessage());
        }
        $result=$service->applyExact($warehouse,$batch,$preview['source_checksum'],$preview['plan_checksum']);
        $this->assertSame(1,$result['shipping_created']);
        $this->assertSame(1,$result['handling_created']);
        $after=$service->preview($warehouse,$batch);
        $this->assertSame(1,$after['shipping']['skip']);
        $this->assertSame(1,$after['parcel_outbound']['skip']);
        $again=$service->applyExact($warehouse,$batch,$after['source_checksum'],$after['plan_checksum']);
        $this->assertSame(0,$again['shipping_created']);
        $this->assertSame(0,$again['handling_created']);
    }

    public function test_production_web_apply_is_fail_closed_without_process_flag(): void
    {
        [$warehouse,$batch]=$this->fixture();
        $service=app(WarehouseBillReconciliationService::class); $preview=$service->preview($warehouse,$batch);
        $old=$this->app['env']; $this->app['env']='production'; putenv('ERP_WAREHOUSE_BILL_RECONCILIATION_WEB_APPLY_ENABLED');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('warehouse_bill_reconciliation_production_apply_disabled');
            $service->applyExact($warehouse,$batch,$preview['source_checksum'],$preview['plan_checksum']);
        } finally {
            putenv('ERP_WAREHOUSE_BILL_RECONCILIATION_WEB_APPLY_ENABLED'); $this->app['env']=$old;
        }
    }

    public function test_manager_routes_are_authorized_and_history_is_exposed(): void
    {
        [$warehouse,$batch]=$this->fixture();
        $manager=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        $viewer=$this->userWith(['inventory.view']);
        $this->actingAs($manager)->post('/inventory/us-la/costs/reconciliation/'.$batch->id.'/preview')->assertRedirect();
        $this->actingAs($manager)->get('/inventory/us-la')->assertOk()->assertInertia(fn($page)=>$page
            ->where('billReconciliationHistory.0.id',$batch->id)
            ->where('billReconciliationHistory.0.supported',true)
            ->where('billReconciliationHistory.0.reconciliation.status','ready')
            ->where('billReconciliationDashboard.totals.batches',1)
            ->where('billReconciliationDashboard.totals.rows',3)
            ->where('billReconciliationDashboard.totals.matched_shipments',1)
            ->where('billReconciliationDashboard.totals.matched_orders',1)
            ->where('billReconciliationPreview.batch_id',$batch->id));
        $this->actingAs($viewer)->post('/inventory/us-la/costs/reconciliation/'.$batch->id.'/preview')->assertForbidden();
    }

    public function test_multi_warehouse_rollout_exposes_active_and_awaiting_sample_states(): void
    {
        $manager=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        $this->actingAs($manager)->get('/inventory/us-la')->assertOk()->assertInertia(fn($page)=>$page
            ->where('warehouse.bill_import_onboarding.status','active')
            ->where('warehouse.bill_import_onboarding.provider','moreplus'));
        $this->actingAs($manager)->get('/inventory/us-ny')->assertOk()->assertInertia(fn($page)=>$page
            ->where('warehouse.bill_import.provider','speeda-us-ny')
            ->where('warehouse.bill_import_onboarding.status','active')
            ->where('warehouse.bill_import_onboarding.provider','speeda-us-ny'));
        foreach (['us-3sm','de','ca'] as $slug) {
            $this->actingAs($manager)->get('/inventory/'.$slug)->assertOk()->assertInertia(fn($page)=>$page
                ->where('warehouse.bill_import',null)
                ->where('warehouse.bill_import_onboarding.status','awaiting_sample')
                ->where('warehouse.bill_import_onboarding.provider',null));
        }
    }

    /** @return array{0:Warehouse,1:WarehouseBillImportBatch} */
    private function fixture(): array
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $order=Order::query()->create(['internal_order_number'=>'WLCR-'.strtoupper(uniqid()),'source_type'=>'manual','order_kind'=>'customer','order_date'=>'2026-08-01','order_status'=>'shipped','currency'=>'USD']);
        $order->shipment()->create(['warehouse_id'=>$warehouse->id,'shipment_status'=>'shipped','tracking_number'=>'TRACK-WLCR-1']);
        $batch=WarehouseBillImportBatch::query()->create(['provider'=>'moreplus','warehouse_id'=>$warehouse->id,'original_filename'=>'wlcr.xlsx','source_checksum'=>str_repeat('a',64),'parser_version'=>'moreplus-bill-v3','plan_checksum'=>str_repeat('b',64),'status'=>'applied','invoice_number'=>'IN-WLCR','client_name'=>'HC26-3','invoice_date'=>'2026-09-14','currency'=>'USD','invoice_total'=>'13.5000','shipping_total'=>'10.0000','parcel_outbound_total'=>'2.5000','storage_total'=>'1.0000','total_rows'=>3,'shipping_rows'=>1,'parcel_outbound_rows'=>1,'storage_rows'=>1,'applied_at'=>now()]);
        $this->row($batch,'shipping',1,'10.0000',1,'TRACK-WLCR-1');
        $this->row($batch,'parcel_outbound',2,'2.5000',2,'TRACK-WLCR-1');
        $this->row($batch,'storage',3,'1.0000',0,null);
        return [$warehouse,$batch];
    }

    private function row(WarehouseBillImportBatch $batch,string $category,int $sourceRow,string $amount,int $qty,?string $tracking): void
    {
        WarehouseBillImportRow::query()->create(['batch_id'=>$batch->id,'category'=>$category,'source_sheet'=>$category,'source_row_number'=>$sourceRow,'transaction_number'=>'TR-'.$sourceRow,'outbound_order_number'=>'OB-'.$sourceRow,'tracking_number'=>$tracking,'charged_on'=>'2026-08-15','quantity'=>$qty,'charge_item_name'=>$category,'charge_amount'=>$amount,'row_hash'=>hash('sha256',$category.$sourceRow),'raw_data'=>[],'normalized_data'=>[]]);
    }

    private function userWith(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'wlcr-'.uniqid(),'label'=>'WLCR Test']);
        foreach($permissions as $key){$permission=Permission::firstOrCreate(['key'=>$key],['label'=>$key]);$role->permissions()->syncWithoutDetaching([$permission->id]);}
        $user->roles()->attach($role);
        return $user;
    }
}
