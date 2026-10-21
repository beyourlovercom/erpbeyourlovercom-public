<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\WarehouseCost\WarehouseStorageCostFileReader;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageCostImportBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageCostImportRow;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderKind;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class WarehouseStorageCostImportService
{
    public function __construct(private readonly WarehouseStorageCostFileReader $reader, private readonly AuditRecorder $audit) {}

    /** @return array<string,mixed> */
    public function preview(string $path, string $provider): array
    {
        $provider=$this->provider($provider); $checksum=hash_file('sha256',$path);
        if (!is_string($checksum)) throw new InvalidArgumentException('Unable to checksum warehouse storage source.');
        $already=WarehouseStorageCostImportBatch::query()->where('provider',$provider)->where('source_checksum',$checksum)->first();
        if ($already) return $this->existingSummary($already,'dry_run')+['rows'=>[],'already_applied'=>true];

        $source=$this->reader->read($path); $meta=$source['batch'];
        $warehouse=Warehouse::query()->where('slug',$meta['warehouse_slug'])->first();
        if (!$warehouse) throw new InvalidArgumentException('Canonical warehouse not found for source slug.');
        $planned=$this->planRows($source['rows'],$warehouse,$meta,$checksum);
        $manifest=array_map(fn(array $r):array=>$this->manifestRow($r),$planned['rows']);
        $planChecksum=hash('sha256',json_encode([
            'version'=>WarehouseStorageCostFileReader::PARSER_VERSION,'provider'=>$provider,'source_checksum'=>$checksum,
            'warehouse_id'=>(int)$warehouse->id,'billing_period_start'=>$meta['billing_period_start'],'billing_period_end'=>$meta['billing_period_end'],
            'storage_pool_amount'=>$meta['storage_pool_amount'],'currency'=>$meta['currency'],'allocation_rate_per_unit'=>$planned['allocation_rate_per_unit'],'rows'=>$manifest,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return [
            'mode'=>'dry_run','provider'=>$provider,'original_filename'=>basename($path),'source_checksum'=>$checksum,
            'parser_version'=>WarehouseStorageCostFileReader::PARSER_VERSION,'plan_checksum'=>$planChecksum,
            'warehouse_id'=>(int)$warehouse->id,'warehouse_slug'=>(string)$warehouse->slug,
            'billing_period_start'=>$meta['billing_period_start'],'billing_period_end'=>$meta['billing_period_end'],
            'storage_pool_amount'=>$meta['storage_pool_amount'],'currency'=>$meta['currency'],'allocation_rate_per_unit'=>$planned['allocation_rate_per_unit'],
            'total_rows'=>count($planned['rows']),'ready_count'=>$planned['counts']['ready'],'excluded_count'=>$planned['counts']['excluded'],'review_count'=>$planned['counts']['review'],
            'total_outbound_units'=>$planned['units']['total'],'ready_customer_units'=>$planned['units']['ready'],'excluded_units'=>$planned['units']['excluded'],'review_units'=>$planned['units']['review'],
            'ready_customer_source_amount'=>$planned['amounts']['ready'],'excluded_source_amount'=>$planned['amounts']['excluded'],'review_source_amount'=>$planned['amounts']['review'],
            'rows'=>$planned['rows'],'already_applied'=>false,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $path,string $provider): array
    {
        $preview=$this->preview($path,$provider);
        if ($preview['already_applied']) return array_replace($preview,['mode'=>'apply']);
        return DB::transaction(function() use($preview):array {
            $dupe=WarehouseStorageCostImportBatch::query()->where('provider',$preview['provider'])->where('source_checksum',$preview['source_checksum'])->lockForUpdate()->first();
            if($dupe) throw new RuntimeException('warehouse_storage_import_concurrent_duplicate');
            $batch=WarehouseStorageCostImportBatch::query()->create([
                'provider'=>$preview['provider'],'warehouse_id'=>$preview['warehouse_id'],'billing_period_start'=>$preview['billing_period_start'],'billing_period_end'=>$preview['billing_period_end'],
                'original_filename'=>$preview['original_filename'],'source_checksum'=>$preview['source_checksum'],'parser_version'=>$preview['parser_version'],'plan_checksum'=>$preview['plan_checksum'],'status'=>'applied',
                'storage_pool_amount'=>$preview['storage_pool_amount'],'currency'=>$preview['currency'],'allocation_rate_per_unit'=>$preview['allocation_rate_per_unit'],
                'total_rows'=>$preview['total_rows'],'ready_count'=>$preview['ready_count'],'excluded_count'=>$preview['excluded_count'],'review_count'=>$preview['review_count'],
                'total_outbound_units'=>$preview['total_outbound_units'],'ready_customer_units'=>$preview['ready_customer_units'],'excluded_units'=>$preview['excluded_units'],'review_units'=>$preview['review_units'],
                'ready_customer_source_amount'=>$preview['ready_customer_source_amount'],'excluded_source_amount'=>$preview['excluded_source_amount'],'review_source_amount'=>$preview['review_source_amount'],'applied_at'=>now(),
            ]);
            foreach($preview['rows'] as $row) WarehouseStorageCostImportRow::query()->create([
                'batch_id'=>$batch->id,'source_row_number'=>$row['source_row_number'],'reference_number'=>$row['reference_number']?:null,'tracking_number'=>$row['tracking_number']?:null,
                'outbound_units'=>$row['outbound_units'],'shipment_id'=>$row['shipment_id'],'order_id'=>$row['order_id'],'decision'=>$row['decision'],'reason_code'=>$row['reason_code'],
                'allocated_source_amount'=>$row['allocated_source_amount'],'row_hash'=>$row['row_hash'],'raw_data'=>$row['raw_data'],'normalized_data'=>$row['normalized_data'],
            ]);
            $this->audit->record(AuditActor::system(AuditSource::InternalSystem),'inventory.warehouse_storage_cost_import.applied',WarehouseStorageCostImportBatch::class,$batch->id,
                after:['provider'=>$preview['provider'],'source_checksum'=>$preview['source_checksum'],'plan_checksum'=>$preview['plan_checksum'],'ready_count'=>$preview['ready_count'],'excluded_count'=>$preview['excluded_count'],'review_count'=>$preview['review_count'],'total_outbound_units'=>$preview['total_outbound_units']],
                reason:'warehouse_storage_cost_import');
            return array_replace($preview,['mode'=>'apply','batch_id'=>(int)$batch->id]);
        });
    }

    /** @param list<array{source_row_number:int,raw:array<string,string>,values:array<string,string>}> $sourceRows @param array<string,string> $meta @return array<string,mixed> */
    private function planRows(array $sourceRows, Warehouse $warehouse,array $meta,string $checksum):array
    {
        $map=[];
        foreach(Shipment::query()->with(['order.externalReferences','warehouse'])->whereNotNull('tracking_number')->get() as $shipment){
            $tracking=strtoupper(trim((string)$shipment->tracking_number)); if($tracking!=='') $map[$tracking][]=$shipment;
        }
        $totalUnits=array_sum(array_map(fn(array $s):int=>(int)$s['values']['outbound_units'],$sourceRows));
        if($totalUnits<1) throw new InvalidArgumentException('Warehouse storage source has no outbound units.');
        $rate=bcdiv((string)$meta['storage_pool_amount'],(string)$totalUnits,12); $rate8=$this->roundDecimal($rate,8);
        $rows=[]; $counts=['ready'=>0,'excluded'=>0,'review'=>0]; $units=['total'=>$totalUnits,'ready'=>0,'excluded'=>0,'review'=>0]; $amounts=['ready'=>'0.0000','excluded'=>'0.0000','review'=>'0.0000'];
        foreach($sourceRows as $source){
            $v=$source['values']; $tracking=(string)$v['tracking_number']; $reference=(string)$v['reference_number']; $qty=(int)$v['outbound_units'];
            $decision='review'; $reason='shipment_not_found'; $shipmentId=null; $orderId=null;
            $matches=$tracking!==''?($map[$tracking]??[]):[];
            if(count($matches)===1){
                $shipment=$matches[0]; $shipmentId=(int)$shipment->id; $order=$shipment->order; $orderId=$order?(int)$order->id:null;
                if(!$shipment->warehouse || (int)$shipment->warehouse->id!==(int)$warehouse->id){ $reason='warehouse_mismatch'; }
                elseif(!$order){ $reason='order_missing'; }
                else {
                    $kind=$order->order_kind instanceof \BackedEnum?$order->order_kind->value:(string)$order->order_kind;
                    $historical=$order->externalReferences->contains(fn($r)=>$r->provider==='historical_logistics');
                    if($kind===OrderKind::Customer->value && !$historical){ $decision='ready'; $reason=null; }
                    else { $decision='excluded'; $reason='non_customer_order'; }
                }
            } elseif(count($matches)>1){ $reason='shipment_tracking_ambiguous'; }
            elseif(str_starts_with(strtoupper(trim($reference)),'KOL')){ $decision='excluded'; $reason='source_marked_kol'; }
            elseif($tracking===''){ $reason='tracking_missing'; }
            $allocated=$this->roundDecimal(bcmul($rate,(string)$qty,12),4);
            $counts[$decision]++; $units[$decision]+=$qty; $amounts[$decision]=bcadd($amounts[$decision],$allocated,4);
            $normalized=['warehouse_slug'=>$meta['warehouse_slug'],'billing_period_start'=>$meta['billing_period_start'],'billing_period_end'=>$meta['billing_period_end'],'storage_pool_amount'=>$meta['storage_pool_amount'],'currency'=>$meta['currency'],
                'reference_number'=>$reference,'tracking_number'=>$tracking,'outbound_units'=>(string)$qty];
            $hash=hash('sha256',json_encode(['source_checksum'=>$checksum,'source_row_number'=>$source['source_row_number'],'normalized'=>$normalized],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $rows[]=['source_row_number'=>$source['source_row_number'],'reference_number'=>$reference,'tracking_number'=>$tracking,'outbound_units'=>$qty,'shipment_id'=>$shipmentId,'order_id'=>$orderId,
                'decision'=>$decision,'reason_code'=>$reason,'allocated_source_amount'=>$allocated,'row_hash'=>$hash,'raw_data'=>$source['raw'],'normalized_data'=>$normalized];
        }
        return ['rows'=>$rows,'counts'=>$counts,'units'=>$units,'amounts'=>$amounts,'allocation_rate_per_unit'=>$rate8];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function manifestRow(array $row):array { return array_intersect_key($row,array_flip(['source_row_number','reference_number','tracking_number','outbound_units','shipment_id','order_id','decision','reason_code','allocated_source_amount','row_hash'])); }

    private function provider(string $provider):string { $provider=strtolower(trim($provider)); if(preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/D',$provider)!==1) throw new InvalidArgumentException('Warehouse storage provider key is invalid.'); return $provider; }
    private function roundDecimal(string $value,int $scale):string
    {
        $negative=str_starts_with($value,'-'); $value=ltrim($value,'+-'); [$whole,$fraction]=array_pad(explode('.',$value,2),2,''); $fraction=preg_replace('/\D/','',$fraction)?:'';
        $kept=str_pad(substr($fraction,0,$scale),$scale,'0'); if(strlen($fraction)>$scale && (int)$fraction[$scale]>=5){ $inc=$scale===0?'1':'0.'.str_repeat('0',$scale-1).'1'; $rounded=bcadd($whole.'.'.$kept,$inc,$scale); [$whole,$kept]=array_pad(explode('.',$rounded,2),2,''); }
        $result=$whole.($scale>0?'.'.str_pad($kept,$scale,'0'):''); return $negative&&$result!=='0'.($scale>0?'.'.str_repeat('0',$scale):'')?'-'.$result:$result;
    }
    /** @return array<string,mixed> */
    private function existingSummary(WarehouseStorageCostImportBatch $b,string $mode):array
    {
        return ['mode'=>$mode,'provider'=>(string)$b->provider,'original_filename'=>(string)$b->original_filename,'source_checksum'=>(string)$b->source_checksum,'parser_version'=>(string)$b->parser_version,'plan_checksum'=>(string)$b->plan_checksum,
            'warehouse_id'=>(int)$b->warehouse_id,'warehouse_slug'=>(string)($b->warehouse?->slug??''),'billing_period_start'=>$b->billing_period_start->toDateString(),'billing_period_end'=>$b->billing_period_end->toDateString(),
            'storage_pool_amount'=>(string)$b->storage_pool_amount,'currency'=>(string)$b->currency,'allocation_rate_per_unit'=>(string)$b->allocation_rate_per_unit,
            'total_rows'=>(int)$b->total_rows,'ready_count'=>(int)$b->ready_count,'excluded_count'=>(int)$b->excluded_count,'review_count'=>(int)$b->review_count,
            'total_outbound_units'=>(int)$b->total_outbound_units,'ready_customer_units'=>(int)$b->ready_customer_units,'excluded_units'=>(int)$b->excluded_units,'review_units'=>(int)$b->review_units,
            'ready_customer_source_amount'=>(string)$b->ready_customer_source_amount,'excluded_source_amount'=>(string)$b->excluded_source_amount,'review_source_amount'=>(string)$b->review_source_amount,'batch_id'=>(int)$b->id];
    }
}
