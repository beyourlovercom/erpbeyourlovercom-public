<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportRow;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentCarrierBilling;
use App\Modules\Logistics\Infrastructure\Models\ShipmentOverseasHandlingBilling;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class MorePlusCostBridgeService
{
    public const BRIDGE_VERSION='moreplus-cost-bridge-v2';
    public function __construct(private readonly WarehouseStorageCostImportService $storage, private readonly AuditRecorder $audit) {}

    /** @return array<string,mixed> */
    public function preview(int $batchId): array
    {
        $batch=WarehouseBillImportBatch::query()->with(['warehouse','rows'])->findOrFail($batchId);
        $this->assertBatch($batch);
        $shipments=$this->shipmentMap(); $orders=$this->orderIdentityMap();
        $shipping=$this->planDestination($batch,'shipping',$shipments,$orders);
        $parcel=$this->planDestination($batch,'parcel_outbound',$shipments,$orders);
        [$storagePreview,$storagePath]=$this->storagePreview($batch);
        if($storagePath!==null) @unlink($storagePath);
        $manifest=['version'=>self::BRIDGE_VERSION,'warehouse_bill_batch_id'=>(int)$batch->id,'source_checksum'=>(string)$batch->source_checksum,
            'shipping'=>$shipping['groups'],'parcel_outbound'=>$parcel['groups'],'storage_plan_checksum'=>$storagePreview['plan_checksum']??null,
            'storage_counts'=>array_intersect_key($storagePreview,array_flip(['ready_count','excluded_count','review_count','total_outbound_units']))];
        $planChecksum=hash('sha256',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return ['mode'=>'dry_run','bridge_version'=>self::BRIDGE_VERSION,'warehouse_bill_batch_id'=>(int)$batch->id,'warehouse_id'=>(int)$batch->warehouse_id,
            'source_checksum'=>(string)$batch->source_checksum,'warehouse_bill_plan_checksum'=>(string)$batch->plan_checksum,'plan_checksum'=>$planChecksum,
            'invoice_number'=>$batch->invoice_number,'currency'=>(string)$batch->currency,
            'shipping'=>$this->summary($shipping),'parcel_outbound'=>$this->summary($parcel),'storage'=>$this->storageSummary($storagePreview),
            'groups'=>['shipping'=>$shipping['groups'],'parcel_outbound'=>$parcel['groups']]];
    }

    /** @return array<string,mixed> */
    public function apply(int $batchId): array
    {
        $preview=$this->preview($batchId);
        return DB::transaction(function() use($batchId,$preview):array {
            $batch=WarehouseBillImportBatch::query()->lockForUpdate()->findOrFail($batchId); $this->assertBatch($batch);
            if(!hash_equals((string)$preview['source_checksum'],(string)$batch->source_checksum)) throw new RuntimeException('moreplus_bridge_source_changed');
            $shippingCreated=0; $handlingCreated=0;
            foreach($preview['groups']['shipping'] as $group){ if($group['decision']!=='ready') continue; $this->createShipping($batch,$group); $shippingCreated++; }
            foreach($preview['groups']['parcel_outbound'] as $group){ if($group['decision']!=='ready') continue; $this->createHandling($batch,$group); $handlingCreated++; }
            [$storagePreview,$path]=$this->storagePreview($batch);
            try { $storageResult=$path===null?$storagePreview:$this->storage->apply($path,'moreplus_p4_storage'); }
            finally { if($path!==null) @unlink($path); }
            $this->audit->record(AuditActor::system(AuditSource::InternalSystem),'inventory.moreplus_cost_bridge.applied',WarehouseBillImportBatch::class,$batch->id,
                after:['source_checksum'=>$preview['source_checksum'],'plan_checksum'=>$preview['plan_checksum'],'shipping_created'=>$shippingCreated,'handling_created'=>$handlingCreated,
                    'storage_batch_id'=>$storageResult['batch_id']??null,'shipping'=>$preview['shipping'],'parcel_outbound'=>$preview['parcel_outbound'],'storage'=>$preview['storage']],reason:'moreplus_cost_bridge');
            return array_replace($preview,['mode'=>'apply','shipping_created'=>$shippingCreated,'handling_created'=>$handlingCreated,'storage_batch_id'=>$storageResult['batch_id']??null]);
        });
    }

    /** @return array<string,list<Shipment>> */
    private function shipmentMap(): array
    {
        $map=[];
        foreach(Shipment::query()->with(['order.externalReferences','warehouse','billingEvidence','overseasHandlingEvidence'])->whereNotNull('tracking_number')->get() as $shipment){
            $tracking=$this->tracking((string)$shipment->tracking_number); if($tracking!=='') $map[$tracking][]=$shipment;
        }
        return $map;
    }

    /** @return array<string,list<Order>> */
    private function orderIdentityMap(): array
    {
        $map=[];
        foreach(Order::query()->with(['shipment','externalReferences','fulfillmentChildren.shipment'])->whereNull('fulfillment_parent_id')->whereNotNull('erp_order_id')->get() as $order){
            if(preg_match('/-c(\d+)$/i',(string)$order->erp_order_id,$m)!==1) continue;
            $map[$m[1]][]=$order;
        }
        return $map;
    }

    /** @param array<string,list<Shipment>> $shipmentMap @param array<string,list<Order>> $orderIdentityMap @return array<string,mixed> */
    private function planDestination(WarehouseBillImportBatch $batch,string $category,array $shipmentMap,array $orderIdentityMap):array
    {
        $rows=$batch->rows->where('category',$category)->sortBy('id')->values(); $grouped=[];
        foreach($rows as $row){ $tracking=$this->tracking((string)($row->tracking_number??'')); $key=$tracking!==''?$tracking:'__row_'.$row->id; $grouped[$key][]=$row; }
        $groups=[]; $counts=['ready'=>0,'review'=>0,'excluded'=>0,'skip'=>0];
        foreach($grouped as $key=>$sourceRows){
            $tracking=str_starts_with($key,'__row_')?'':$key; $amount='0.0000'; $rowIds=[]; $rowHashes=[]; $dates=[]; $refs=[]; $sourceOrderRefs=[];
            foreach($sourceRows as $row){ $amount=bcadd($amount,(string)$row->charge_amount,4); $rowIds[]=(int)$row->id; $rowHashes[]=(string)$row->row_hash; $dates[]=$row->charged_on?->toDateString(); if($row->transaction_number)$refs[]=(string)$row->transaction_number; $sourceRef=$this->sourceOrderReference($row); if($sourceRef!=='')$sourceOrderRefs[]=$sourceRef; }
            sort($rowIds); sort($rowHashes); $sourceOrderRefs=array_values(array_unique($sourceOrderRefs)); $decision='review'; $reason='shipment_not_found'; $shipmentId=null; $orderId=null; $destinationId=null;
            $matches=$tracking!==''?($shipmentMap[$tracking]??[]):[];
            if($tracking===''){ $reason='tracking_missing'; }
            elseif(count($matches)>1){ $reason='shipment_tracking_ambiguous'; }
            elseif(count($matches)===1){
                $shipment=$matches[0]; $shipmentId=(int)$shipment->id; $order=$shipment->order; $orderId=$order?(int)$order->id:null;
                if(!$shipment->warehouse||(int)$shipment->warehouse->id!==(int)$batch->warehouse_id){ $reason='warehouse_mismatch'; }
                elseif(!$order){ $reason='order_missing'; }
                else {
                    $kind=$order->order_kind instanceof \BackedEnum?$order->order_kind->value:(string)$order->order_kind;
                    $historical=$order->externalReferences->contains(fn($r)=>$r->provider==='historical_logistics');
                    if($kind!==OrderKind::Customer->value||$historical){ $decision='excluded'; $reason='non_customer_order'; }
                    else {
                        $existing=$category==='shipping'?$shipment->billingEvidence:$shipment->overseasHandlingEvidence;
                        if(!$existing){ $decision='ready'; $reason=null; }
                        elseif($this->sameDestination($existing,$batch,$rowIds,$amount,$category)){ $decision='skip'; $reason='already_applied'; $destinationId=(int)$existing->id; }
                        else { $decision='review'; $reason=$category==='shipping'?'existing_carrier_billing_conflict':'existing_overseas_handling_conflict'; $destinationId=(int)$existing->id; }
                    }
                }
            } else {
                $fallback=$this->sourceFallback($sourceOrderRefs,$orderIdentityMap,$tracking);
                $decision=$fallback['decision']; $reason=$fallback['reason_code']; $shipmentId=$fallback['shipment_id']; $orderId=$fallback['order_id'];
            }
            $counts[$decision]++;
            $groups[]=['tracking_number'=>$tracking?:null,'source_order_references'=>$sourceOrderRefs,'source_row_ids'=>$rowIds,'row_hashes'=>$rowHashes,'shipment_id'=>$shipmentId,'order_id'=>$orderId,'destination_id'=>$destinationId,
                'amount'=>$amount,'currency'=>(string)$batch->currency,'billed_at'=>max(array_filter($dates))?:null,'provider_references'=>array_values(array_unique($refs)),'decision'=>$decision,'reason_code'=>$reason];
        }
        usort($groups,fn($a,$b)=>strcmp((string)($a['tracking_number']??''),(string)($b['tracking_number']??'')));
        return ['source_rows'=>$rows->count(),'groups'=>$groups,'counts'=>$counts];
    }

    /** @param list<string> $sourceOrderRefs @param array<string,list<Order>> $orderIdentityMap @return array{decision:string,reason_code:?string,shipment_id:?int,order_id:?int} */
    private function sourceFallback(array $sourceOrderRefs,array $orderIdentityMap,string $tracking):array
    {
        if(count($sourceOrderRefs)!==1) return ['decision'=>'review','reason_code'=>count($sourceOrderRefs)>1?'source_order_reference_ambiguous':'source_order_reference_missing','shipment_id'=>null,'order_id'=>null];
        $ref=strtoupper(trim($sourceOrderRefs[0]));
        if(preg_match('/^KOL\d+-007$/D',$ref)===1) return ['decision'=>'excluded','reason_code'=>'source_marked_kol','shipment_id'=>null,'order_id'=>null];
        if(preg_match('/^\d{12,20}$/D',$ref)===1) return ['decision'=>'review','reason_code'=>'source_order_unmapped','shipment_id'=>null,'order_id'=>null];
        if(preg_match('/^C(\d+)(?:-007([A-Z])?|([A-Z]))?$/D',$ref,$m)!==1) return ['decision'=>'review','reason_code'=>'source_order_unmapped','shipment_id'=>null,'order_id'=>null];
        $orders=$orderIdentityMap[$m[1]]??[];
        if(count($orders)===0) return ['decision'=>'review','reason_code'=>'source_order_not_found','shipment_id'=>null,'order_id'=>null];
        if(count($orders)>1) return ['decision'=>'review','reason_code'=>'source_order_ambiguous','shipment_id'=>null,'order_id'=>null];
        $order=$orders[0]; $kind=$order->order_kind instanceof \BackedEnum?$order->order_kind->value:(string)$order->order_kind; $historical=$order->externalReferences->contains(fn($r)=>$r->provider==='historical_logistics');
        if($kind!==OrderKind::Customer->value||$historical) return ['decision'=>'excluded','reason_code'=>'non_customer_order','shipment_id'=>$order->shipment?(int)$order->shipment->id:null,'order_id'=>(int)$order->id];
        $suffix=strtolower((string)(((string)($m[2]??''))!==''?$m[2]:($m[3]??''))); $target=$order;
        if($suffix!==''){ $children=$order->fulfillmentChildren->filter(fn($child)=>strtolower((string)$child->fulfillment_suffix)===$suffix)->values(); if($children->count()!==1)return ['decision'=>'review','reason_code'=>'fulfillment_suffix_unresolved','shipment_id'=>null,'order_id'=>(int)$order->id]; $target=$children->first(); }
        if($target->shipment){ $existing=$this->tracking((string)$target->shipment->tracking_number); return ['decision'=>'review','reason_code'=>$existing!==$tracking?'existing_shipment_tracking_conflict':'shipment_identity_inconsistent','shipment_id'=>(int)$target->shipment->id,'order_id'=>(int)$target->id]; }
        return ['decision'=>'review','reason_code'=>'shipment_missing','shipment_id'=>null,'order_id'=>(int)$target->id];
    }

    private function sourceOrderReference($row):string
    {
        $normalized=(array)($row->normalized_data??[]); return strtoupper(trim((string)($normalized['source_order_reference']??'')));
    }

    private function sameDestination($existing,WarehouseBillImportBatch $batch,array $rowIds,string $amount,string $category):bool
    {
        $raw=(array)($existing->raw_evidence??[]); $existingRows=array_map('intval',(array)($raw['warehouse_bill_row_ids']??[])); sort($existingRows); $expected=$rowIds; sort($expected);
        return (string)$existing->actual_cost===$amount && strtoupper((string)$existing->currency)===strtoupper((string)$batch->currency)
            && (string)$existing->data_source==='warehouse_bill_bridge:moreplus' && (int)($raw['warehouse_bill_batch_id']??0)===(int)$batch->id
            && $existingRows===$expected && (string)($raw['category']??'')===$category;
    }

    private function createShipping(WarehouseBillImportBatch $batch,array $group):void
    {
        $shipment=Shipment::query()->lockForUpdate()->findOrFail($group['shipment_id']); $existing=$shipment->billingEvidence()->lockForUpdate()->first();
        if($existing) throw new RuntimeException('moreplus_bridge_carrier_billing_conflict_after_preflight');
        ShipmentCarrierBilling::query()->create(['shipment_id'=>$shipment->id,'actual_cost'=>$group['amount'],'currency'=>$batch->currency,'billing_document_number'=>$batch->invoice_number,
            'provider_reference'=>$group['provider_references'][0]??('moreplus-batch-'.$batch->id),'data_source'=>'warehouse_bill_bridge:moreplus','billed_at'=>$group['billed_at'],
            'raw_evidence'=>$this->rawEvidence($batch,$group,'shipping')]);
    }

    private function createHandling(WarehouseBillImportBatch $batch,array $group):void
    {
        $shipment=Shipment::query()->lockForUpdate()->findOrFail($group['shipment_id']); $existing=$shipment->overseasHandlingEvidence()->lockForUpdate()->first();
        if($existing) throw new RuntimeException('moreplus_bridge_handling_conflict_after_preflight');
        ShipmentOverseasHandlingBilling::query()->create(['shipment_id'=>$shipment->id,'actual_cost'=>$group['amount'],'currency'=>$batch->currency,'billing_document_number'=>$batch->invoice_number,
            'provider_reference'=>$group['provider_references'][0]??('moreplus-batch-'.$batch->id),'data_source'=>'warehouse_bill_bridge:moreplus','billed_at'=>$group['billed_at'],
            'raw_evidence'=>$this->rawEvidence($batch,$group,'parcel_outbound')]);
    }

    private function rawEvidence(WarehouseBillImportBatch $batch,array $group,string $category):array
    {
        return ['bridge_version'=>self::BRIDGE_VERSION,'warehouse_bill_batch_id'=>(int)$batch->id,'warehouse_bill_row_ids'=>$group['source_row_ids'],'row_hashes'=>$group['row_hashes'],
            'source_checksum'=>(string)$batch->source_checksum,'warehouse_bill_plan_checksum'=>(string)$batch->plan_checksum,'category'=>$category];
    }

    /** @return array{0:array<string,mixed>,1:?string} */
    private function storagePreview(WarehouseBillImportBatch $batch):array
    {
        $storageRows=$batch->rows->where('category','storage')->sortBy('charged_on');
        if($storageRows->isEmpty()||bccomp((string)$batch->storage_total,'0',4)===0) return [['mode'=>'dry_run','ready_count'=>0,'excluded_count'=>0,'review_count'=>0,'total_outbound_units'=>0,'already_applied'=>false,'plan_checksum'=>null],null];
        $parcelRows=$batch->rows->where('category','parcel_outbound')->sortBy('id')->values();
        if($parcelRows->isEmpty()) throw new InvalidArgumentException('MorePlus storage bridge requires Parcel Outbound rows as outbound-unit evidence.');
        $start=$storageRows->first()->charged_on->toDateString(); $end=$storageRows->last()->charged_on->toDateString();
        $base=tempnam(sys_get_temp_dir(),'moreplus-storage-'); if($base===false) throw new RuntimeException('moreplus_storage_temp_failed'); $path=$base.'.csv'; if(!rename($base,$path)) throw new RuntimeException('moreplus_storage_temp_rename_failed');
        $h=fopen($path,'wb'); fputcsv($h,['warehouse_slug','billing_period_start','billing_period_end','storage_pool_amount','currency','reference_number','tracking_number','outbound_units']);
        foreach($parcelRows as $row){ $normalized=(array)($row->normalized_data??[]); $reference=(string)($normalized['source_order_reference']??$row->outbound_order_number??''); fputcsv($h,[$batch->warehouse->slug,$start,$end,(string)$batch->storage_total,(string)$batch->currency,$reference,(string)($row->tracking_number??''),(int)$row->quantity]); }
        fclose($h); return [$this->storage->preview($path,'moreplus_p4_storage'),$path];
    }

    private function assertBatch(WarehouseBillImportBatch $batch):void
    {
        if($batch->status!=='applied') throw new InvalidArgumentException('Warehouse bill batch must be applied before bridging.');
        if(strtolower((string)$batch->provider)!=='moreplus') throw new InvalidArgumentException('Only MorePlus warehouse bill batches are supported.');
        if(!$batch->warehouse||strtolower((string)$batch->warehouse->slug)!=='us-la') throw new InvalidArgumentException('MorePlus cost bridge requires canonical US-LA warehouse.');
    }
    private function tracking(string $value):string { return strtoupper(preg_replace('/\s+/','',trim($value))??''); }
    /** @param array<string,mixed> $p */ private function summary(array $p):array { return ['source_rows'=>$p['source_rows'],'group_count'=>count($p['groups'])]+$p['counts']; }
    /** @param array<string,mixed> $p */ private function storageSummary(array $p):array { return array_intersect_key($p,array_flip(['plan_checksum','ready_count','excluded_count','review_count','total_outbound_units','ready_customer_units','excluded_units','review_units','storage_pool_amount','currency','already_applied','batch_id'])); }
}
