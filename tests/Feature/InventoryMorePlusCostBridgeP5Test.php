<?php

namespace Tests\Feature;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\Contracts\AccountingContinuousDeltaCandidateStore;
use Carbon\CarbonImmutable;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportRow;
use App\Modules\Inventory\Infrastructure\WarehouseCost\MorePlusCostBridgeService;
use App\Modules\Logistics\Infrastructure\Models\ShipmentCarrierBilling;
use App\Modules\Logistics\Infrastructure\Models\ShipmentOverseasHandlingBilling;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryMorePlusCostBridgeP5Test extends TestCase
{
    use RefreshDatabase;

    public function test_moreplus_bridge_reuses_existing_cost_pipelines_and_is_idempotent(): void
    {
        [$batch,$order]=$this->fixture(); $service=app(MorePlusCostBridgeService::class);
        $preview=$service->preview($batch->id);
        $this->assertSame(1,$preview['shipping']['ready']);
        $this->assertSame(1,$preview['parcel_outbound']['ready']);
        $this->assertSame(1,$preview['storage']['ready_count']);
        $this->assertSame(2,$preview['storage']['total_outbound_units']);

        $result=$service->apply($batch->id);
        $this->assertSame('apply',$result['mode']);
        $this->assertSame(1,$result['shipping_created']);
        $this->assertSame(1,$result['handling_created']);
        $this->assertNotNull($result['storage_batch_id']);
        $this->assertDatabaseCount('shipment_carrier_billing',1);
        $this->assertDatabaseCount('shipment_overseas_handling_billing',1);
        $this->assertDatabaseCount('warehouse_storage_cost_import_batches',1);
        $this->assertDatabaseHas('audit_records',['action'=>'inventory.moreplus_cost_bridge.applied']);

        $finance=app(CostMaterializationService::class);
        $this->assertSame('10.00',$finance->previewCarrierBillingGate($order)['candidates'][0]['amount']);
        $this->assertSame('2.50',$finance->previewOverseasHandlingGate($order)['candidates'][0]['amount']);
        $this->assertSame('7.78',$finance->previewWarehouseCostGate($order)['candidates'][0]['amount']);
        $finance->applyCarrierBillingGate($order); $finance->applyOverseasHandlingGate($order); $finance->applyWarehouseCostGate($order);
        $this->assertDatabaseHas('order_cost_entries',['order_id'=>$order->id,'cost_type'=>'last_mile_cost','amount'=>'10.00','provenance'=>'provider_import']);
        $this->assertDatabaseHas('order_cost_entries',['order_id'=>$order->id,'cost_type'=>'overseas_handling_cost','amount'=>'2.50','provenance'=>'provider_import']);
        $this->assertDatabaseHas('order_cost_entries',['order_id'=>$order->id,'cost_type'=>'warehouse_cost','amount'=>'7.78','provenance'=>'allocated_actual']);

        $again=$service->preview($batch->id);
        $this->assertSame(1,$again['shipping']['skip']); $this->assertSame(1,$again['parcel_outbound']['skip']); $this->assertTrue($again['storage']['already_applied']);
        $againApply=$service->apply($batch->id); $this->assertSame(0,$againApply['shipping_created']); $this->assertSame(0,$againApply['handling_created']);
        $this->assertDatabaseCount('shipment_carrier_billing',1); $this->assertDatabaseCount('shipment_overseas_handling_billing',1); $this->assertDatabaseCount('warehouse_storage_cost_import_batches',1);
    }

    public function test_bridge_never_overwrites_existing_shipping_or_handling_fact(): void
    {
        [$batch,$order,$shipment]=$this->fixture(withShipment:true);
        $shipment->billingEvidence()->create(['actual_cost'=>'99.0000','currency'=>'USD','billing_document_number'=>'OTHER','data_source'=>'carrier_bill_import:other']);
        $shipment->overseasHandlingEvidence()->create(['actual_cost'=>'8.0000','currency'=>'USD','billing_document_number'=>'OTHER','data_source'=>'manual_import']);
        $preview=app(MorePlusCostBridgeService::class)->preview($batch->id);
        $this->assertSame(1,$preview['shipping']['review']); $this->assertSame(1,$preview['parcel_outbound']['review']);
        $this->assertSame('existing_carrier_billing_conflict',$preview['groups']['shipping'][0]['reason_code']);
        $this->assertSame('existing_overseas_handling_conflict',$preview['groups']['parcel_outbound'][0]['reason_code']);
        app(MorePlusCostBridgeService::class)->apply($batch->id);
        $this->assertSame('99.0000',(string)ShipmentCarrierBilling::query()->firstOrFail()->actual_cost);
        $this->assertSame('8.0000',(string)ShipmentOverseasHandlingBilling::query()->firstOrFail()->actual_cost);
    }

    public function test_source_reference_fallback_is_deterministic_and_fail_closed(): void
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $customer=Order::query()->create(['internal_order_number'=>'SRC-CUSTOMER','erp_order_id'=>'007-0801-c39124','source_type'=>'website','order_kind'=>'customer','order_date'=>'2026-08-01','order_status'=>'shipped','currency'=>'USD']);
        $suffix=Order::query()->create(['internal_order_number'=>'SRC-SUFFIX','erp_order_id'=>'007-0814-c39555','source_type'=>'website','order_kind'=>'customer','order_date'=>'2026-08-14','order_status'=>'shipped','currency'=>'USD']);
        $bareSuffix=Order::query()->create(['internal_order_number'=>'SRC-SUFFIX-BARE','erp_order_id'=>'007-0802-c39140','source_type'=>'website','order_kind'=>'customer','order_date'=>'2026-08-02','order_status'=>'shipped','currency'=>'USD']);
        $conflict=Order::query()->create(['internal_order_number'=>'SRC-CONFLICT','erp_order_id'=>'007-0810-c39398','source_type'=>'website','order_kind'=>'customer','order_date'=>'2026-08-10','order_status'=>'shipped','currency'=>'USD']);
        $conflict->shipment()->create(['warehouse_id'=>$warehouse->id,'shipment_status'=>'shipped','tracking_number'=>'4PX-CONFLICT']);
        $batch=WarehouseBillImportBatch::query()->create(['provider'=>'moreplus','warehouse_id'=>$warehouse->id,'original_filename'=>'source-ref.xlsx','source_checksum'=>str_repeat('c',64),'parser_version'=>'moreplus-bill-v3','plan_checksum'=>str_repeat('d',64),'status'=>'applied','invoice_number'=>'IN-SOURCE-REF','client_name'=>'HC26-3','invoice_date'=>'2026-09-14','currency'=>'USD','invoice_total'=>'8.0000','shipping_total'=>'6.0000','parcel_outbound_total'=>'1.0000','storage_total'=>'1.0000','total_rows'=>8,'shipping_rows'=>6,'parcel_outbound_rows'=>1,'storage_rows'=>1,'applied_at'=>now()]);
        $this->row($batch,'shipping',1,'2026-08-02','1.0000',1,'TRACK-CUSTOMER','TR-CUSTOMER','OB-1','C39124-007');
        $this->row($batch,'shipping',2,'2026-08-02','1.0000',1,'TRACK-KOL','TR-KOL','OB-2','KOL26073104-007');
        $this->row($batch,'shipping',3,'2026-08-02','1.0000',1,'TRACK-LONG','TR-LONG','OB-3','103734044623762104');
        $this->row($batch,'shipping',4,'2026-08-02','1.0000',1,'TRACK-SUFFIX','TR-SUFFIX','OB-4','C39555-007B');
        $this->row($batch,'shipping',5,'2026-08-02','1.0000',1,'TRACK-SUFFIX-BARE','TR-SUFFIX-BARE','OB-5','C39140B');
        $this->row($batch,'shipping',6,'2026-08-02','1.0000',1,'USPS-CONFLICT','TR-CONFLICT','OB-6','C39398');
        $this->row($batch,'parcel_outbound',7,'2026-08-02','1.0000',1,'TRACK-KOL','TR-P-KOL','OB-6','KOL26073104-007');
        $this->row($batch,'storage',8,'2026-08-01','1.0000',0,null,'TR-ST',null);
        $preview=app(MorePlusCostBridgeService::class)->preview($batch->id);
        $this->assertSame(0,$preview['shipping']['ready']); $this->assertSame(5,$preview['shipping']['review']); $this->assertSame(1,$preview['shipping']['excluded']);
        $reasons=collect($preview['groups']['shipping'])->mapWithKeys(fn($g)=>[$g['source_order_references'][0]??''=>$g['reason_code']]);
        $this->assertSame('shipment_missing',$reasons['C39124-007']); $this->assertSame('source_marked_kol',$reasons['KOL26073104-007']);
        $this->assertSame('source_order_unmapped',$reasons['103734044623762104']); $this->assertSame('fulfillment_suffix_unresolved',$reasons['C39555-007B']); $this->assertSame('fulfillment_suffix_unresolved',$reasons['C39140B']);
        $this->assertSame('existing_shipment_tracking_conflict',$reasons['C39398']);
        $this->assertSame(1,$preview['parcel_outbound']['excluded']); $this->assertSame(1,$preview['storage']['excluded_count']); $this->assertSame(0,$preview['storage']['ready_count']);
        $this->assertDatabaseCount('shipment_carrier_billing',0); $this->assertDatabaseCount('shipment_overseas_handling_billing',0);
    }

    public function test_overseas_handling_evidence_reenters_continuous_delta_scope(): void
    {
        [$batch,$order,$shipment]=$this->fixture(withShipment:true);
        $shipment->overseasHandlingEvidence()->create([
            'actual_cost'=>'2.5000','currency'=>'USD','billing_document_number'=>'IN-P5-DELTA',
            'data_source'=>'warehouse_bill_bridge:moreplus','billed_at'=>now(),
            'raw_evidence'=>['warehouse_bill_batch_id'=>$batch->id],
        ]);
        $store=app(AccountingContinuousDeltaCandidateStore::class);
        $orders=$store->candidates(CarbonImmutable::now('UTC')->subMinute(),CarbonImmutable::now('UTC')->addMinute(),100);
        $this->assertTrue($orders->contains(fn($candidate)=>(int)$candidate->id===(int)$order->id));
        $candidate=app(CostMaterializationService::class)->previewOverseasHandlingGate($order)['candidates'][0];
        $this->assertSame('apply',$candidate['materialization_status']);
        $this->assertSame('overseas_handling_cost',$candidate['cost_type']);
        $this->assertSame('2.50',$candidate['amount']);
    }

    public function test_command_is_dry_run_first_and_production_apply_is_exact_guarded(): void
    {
        [$batch]=$this->fixture(); $preview=app(MorePlusCostBridgeService::class)->preview($batch->id);
        $this->artisan('inventory:bridge-moreplus-costs',['batch'=>$batch->id])->assertSuccessful();
        $this->assertDatabaseCount('shipment_carrier_billing',0);
        $old=$this->app['env']; $this->app['env']='production'; putenv('ERP_MOREPLUS_COST_BRIDGE_PRODUCTION_APPLY_ENABLED');
        $args=['batch'=>$batch->id,'--apply'=>true,'--expect-source-sha'=>$preview['source_checksum'],'--expect-plan-sha'=>$preview['plan_checksum'],
            '--expect-shipping-ready'=>'1','--expect-shipping-review'=>'0','--expect-shipping-excluded'=>'0','--expect-shipping-skip'=>'0',
            '--expect-parcel-ready'=>'1','--expect-parcel-review'=>'0','--expect-parcel-excluded'=>'0','--expect-parcel-skip'=>'0',
            '--expect-storage-ready'=>'1','--expect-storage-review'=>'0','--expect-storage-excluded'=>'0'];
        try{
            $this->artisan('inventory:bridge-moreplus-costs',$args)->assertFailed();
            putenv('ERP_MOREPLUS_COST_BRIDGE_PRODUCTION_APPLY_ENABLED=true');
            $wrong=$args; $wrong['--expect-parcel-ready']='2'; $this->artisan('inventory:bridge-moreplus-costs',$wrong)->assertFailed();
            $this->assertDatabaseCount('shipment_carrier_billing',0);
            $this->artisan('inventory:bridge-moreplus-costs',$args)->assertSuccessful();
            $this->assertDatabaseCount('shipment_carrier_billing',1); $this->assertDatabaseCount('shipment_overseas_handling_billing',1);
        } finally { putenv('ERP_MOREPLUS_COST_BRIDGE_PRODUCTION_APPLY_ENABLED'); $this->app['env']=$old; }
    }

    /** @return array{0:WarehouseBillImportBatch,1:Order,2?:\App\Modules\Logistics\Infrastructure\Models\Shipment} */
    private function fixture(bool $withShipment=false): array
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $order=Order::query()->create(['internal_order_number'=>'P5-'.strtoupper(uniqid()),'source_type'=>'manual','order_kind'=>'customer','order_date'=>'2026-07-15','order_status'=>'shipped','currency'=>'USD']);
        $shipment=$order->shipment()->create(['warehouse_id'=>$warehouse->id,'shipment_status'=>'shipped','tracking_number'=>'TRACK-P5-1']);
        $batch=WarehouseBillImportBatch::query()->create(['provider'=>'moreplus','warehouse_id'=>$warehouse->id,'original_filename'=>'moreplus-p5.xlsx','source_checksum'=>str_repeat('a',64),'parser_version'=>'moreplus-xlsx-v1','plan_checksum'=>str_repeat('b',64),'status'=>'applied','invoice_number'=>'IN-P5-001','client_name'=>'HC26-3','invoice_date'=>'2026-08-10','currency'=>'USD','invoice_total'=>'20.2777','shipping_total'=>'10.0000','parcel_outbound_total'=>'2.5000','storage_total'=>'7.7777','total_rows'=>4,'shipping_rows'=>1,'parcel_outbound_rows'=>1,'storage_rows'=>2,'applied_at'=>now()]);
        $this->row($batch,'shipping',1,'2026-07-15','10.0000',2,'TRACK-P5-1','TR-SHIP','OB-1');
        $this->row($batch,'parcel_outbound',2,'2026-08-04','2.5000',2,'TRACK-P5-1','TR-PARCEL','OB-1');
        $this->row($batch,'storage',3,'2026-07-01','3.0000',0,null,'TR-ST-1',null);
        $this->row($batch,'storage',4,'2026-07-31','4.7777',0,null,'TR-ST-2',null);
        return $withShipment?[$batch,$order,$shipment]:[$batch,$order];
    }

    private function row(WarehouseBillImportBatch $batch,string $category,int $sourceRow,string $date,string $amount,int $qty,?string $tracking,?string $transaction,?string $outbound,?string $sourceRef=null): void
    {
        WarehouseBillImportRow::query()->create(['batch_id'=>$batch->id,'category'=>$category,'source_sheet'=>$category,'source_row_number'=>$sourceRow,'transaction_number'=>$transaction,'outbound_order_number'=>$outbound,'tracking_number'=>$tracking,'charged_on'=>$date,'quantity'=>$qty,'charge_item_name'=>$category,'charge_amount'=>$amount,'row_hash'=>hash('sha256',$category.$sourceRow),'raw_data'=>[],'normalized_data'=>['source_order_reference'=>$sourceRef]]);
    }
}
