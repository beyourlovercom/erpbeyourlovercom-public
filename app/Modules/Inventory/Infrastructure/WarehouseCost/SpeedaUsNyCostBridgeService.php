<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use InvalidArgumentException;

final class SpeedaUsNyCostBridgeService
{
    public const BRIDGE_VERSION = 'speeda-us-ny-cost-bridge-v1';

    /** @return array<string,mixed> */
    public function preview(int $batchId): array
    {
        $batch = WarehouseBillImportBatch::query()->with(['warehouse','rows'])->findOrFail($batchId);
        $this->assertBatch($batch);

        $shipmentMap = $this->shipmentMap();
        $orderMap = $this->orderIdentityMap();
        $shipping = $this->plan($batch, 'shipping', $shipmentMap, $orderMap);
        $handling = $this->plan($batch, 'parcel_outbound', $shipmentMap, $orderMap);

        $manifest = [
            'version'=>self::BRIDGE_VERSION,
            'warehouse_bill_batch_id'=>(int)$batch->id,
            'source_checksum'=>(string)$batch->source_checksum,
            'shipping'=>$shipping['groups'],
            'parcel_outbound'=>$handling['groups'],
        ];
        $planChecksum = hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));

        return [
            'mode'=>'dry_run',
            'bridge_version'=>self::BRIDGE_VERSION,
            'warehouse_bill_batch_id'=>(int)$batch->id,
            'warehouse_id'=>(int)$batch->warehouse_id,
            'source_checksum'=>(string)$batch->source_checksum,
            'plan_checksum'=>$planChecksum,
            'invoice_number'=>$batch->invoice_number,
            'currency'=>(string)$batch->currency,
            'shipping'=>$this->summary($shipping),
            'parcel_outbound'=>$this->summary($handling),
            'storage'=>['ready_count'=>0,'review_count'=>0,'excluded_count'=>0,'already_applied'=>false,'plan_checksum'=>null],
            'groups'=>['shipping'=>$shipping['groups'],'parcel_outbound'=>$handling['groups']],
        ];
    }

    /** @return array<string,list<Shipment>> */
    private function shipmentMap(): array
    {
        $map=[];
        foreach (Shipment::query()->with(['order.externalReferences','warehouse','billingEvidence','overseasHandlingEvidence'])->whereNotNull('tracking_number')->get() as $shipment) {
            $tracking=$this->tracking((string)$shipment->tracking_number);
            if ($tracking!=='') $map[$tracking][]=$shipment;
        }
        return $map;
    }

    /** @return array<string,list<Order>> */
    private function orderIdentityMap(): array
    {
        $map=[];
        foreach (Order::query()->with(['shipment','externalReferences','fulfillmentChildren.shipment'])->whereNull('fulfillment_parent_id')->whereNotNull('erp_order_id')->get() as $order) {
            if (preg_match('/-c(\d+)$/i',(string)$order->erp_order_id,$m)===1) $map[$m[1]][]=$order;
        }
        return $map;
    }

    /** @param array<string,list<Shipment>> $shipmentMap @param array<string,list<Order>> $orderMap @return array<string,mixed> */
    private function plan(WarehouseBillImportBatch $batch, string $category, array $shipmentMap, array $orderMap): array
    {
        $rows=$batch->rows->where('category',$category)->sortBy('id')->values();
        $groups=[]; $counts=['ready'=>0,'review'=>0,'excluded'=>0,'skip'=>0];

        foreach ($rows as $row) {
            $tracking=$this->tracking((string)($row->tracking_number??''));
            $ref=$this->sourceOrderReference($row);
            $decision='review'; $reason='shipment_not_found'; $shipmentId=null; $orderId=null;

            if (preg_match('/^KOL/i',$ref)===1) {
                $decision='excluded'; $reason='source_marked_kol';
            } else {
                $matches=$tracking!==''?($shipmentMap[$tracking]??[]):[];
                if ($tracking==='') $reason='tracking_missing';
                elseif (count($matches)>1) $reason='shipment_tracking_ambiguous';
                elseif (count($matches)===1) {
                    $shipment=$matches[0]; $shipmentId=(int)$shipment->id; $order=$shipment->order; $orderId=$order?(int)$order->id:null;
                    if (!$shipment->warehouse || (int)$shipment->warehouse->id!==(int)$batch->warehouse_id) $reason='warehouse_mismatch';
                    elseif (!$order) $reason='order_missing';
                    else {
                        $kind=$order->order_kind instanceof \BackedEnum?$order->order_kind->value:(string)$order->order_kind;
                        $historical=$order->externalReferences->contains(fn($r)=>$r->provider==='historical_logistics');
                        if ($kind!==OrderKind::Customer->value || $historical) { $decision='excluded'; $reason='non_customer_order'; }
                        elseif ($this->referenceConflicts($ref,(string)$order->erp_order_id)) $reason='reference_order_conflict';
                        else { $decision='ready'; $reason='tracking_exact'; }
                    }
                } else {
                    $fallback=$this->sourceFallback($ref,$orderMap,$tracking);
                    $decision=$fallback['decision']; $reason=$fallback['reason_code']; $shipmentId=$fallback['shipment_id']; $orderId=$fallback['order_id'];
                }
            }

            $counts[$decision]++;
            $groups[]=[
                'tracking_number'=>$tracking?:null,
                'source_order_reference'=>$ref?:null,
                'source_row_ids'=>[(int)$row->id],
                'row_hashes'=>[(string)$row->row_hash],
                'shipment_id'=>$shipmentId,
                'order_id'=>$orderId,
                'amount'=>(string)$row->charge_amount,
                'currency'=>(string)$batch->currency,
                'billed_at'=>$row->charged_on?->toDateString(),
                'provider_references'=>$row->transaction_number?[(string)$row->transaction_number]:[],
                'decision'=>$decision,
                'reason_code'=>$reason,
            ];
        }

        usort($groups,fn($a,$b)=>strcmp((string)($a['tracking_number']??''),(string)($b['tracking_number']??'')));
        return ['source_rows'=>$rows->count(),'groups'=>$groups,'counts'=>$counts];
    }

    /** @param array<string,list<Order>> $orderMap @return array{decision:string,reason_code:string,shipment_id:?int,order_id:?int} */
    private function sourceFallback(string $ref,array $orderMap,string $tracking): array
    {
        if ($ref==='') return ['decision'=>'review','reason_code'=>'source_order_reference_missing','shipment_id'=>null,'order_id'=>null];
        if (preg_match('/^KOL/i',$ref)===1) return ['decision'=>'excluded','reason_code'=>'source_marked_kol','shipment_id'=>null,'order_id'=>null];
        if (preg_match('/^C(\d+)(?:-007([A-Z])?|([A-Z]))?$/D',$ref,$m)!==1) return ['decision'=>'review','reason_code'=>'source_order_unmapped','shipment_id'=>null,'order_id'=>null];

        $orders=$orderMap[$m[1]]??[];
        if (count($orders)===0) return ['decision'=>'review','reason_code'=>'source_order_not_found','shipment_id'=>null,'order_id'=>null];
        if (count($orders)>1) return ['decision'=>'review','reason_code'=>'source_order_ambiguous','shipment_id'=>null,'order_id'=>null];

        $order=$orders[0]; $orderId=(int)$order->id;
        $suffix=strtolower((string)(((string)($m[2]??''))!==''?$m[2]:($m[3]??'')));
        $target=$order;
        if ($suffix!=='') {
            $children=$order->fulfillmentChildren->filter(fn($child)=>strtolower((string)$child->fulfillment_suffix)===$suffix)->values();
            if ($children->count()!==1) return ['decision'=>'review','reason_code'=>'fulfillment_suffix_unresolved','shipment_id'=>null,'order_id'=>$orderId];
            $target=$children->first();
        }
        if ($target->shipment) {
            $existing=$this->tracking((string)$target->shipment->tracking_number);
            return ['decision'=>'review','reason_code'=>$existing!==$tracking?'existing_shipment_tracking_conflict':'shipment_identity_inconsistent','shipment_id'=>(int)$target->shipment->id,'order_id'=>(int)$target->id];
        }
        return ['decision'=>'review','reason_code'=>'shipment_missing','shipment_id'=>null,'order_id'=>(int)$target->id];
    }

    private function referenceConflicts(string $ref,string $erpOrderId): bool
    {
        if (preg_match('/^C(\d+)(?:-007[A-Z]?|[A-Z])?$/D',$ref,$r)!==1) return false;
        return preg_match('/-c(\d+)$/i',$erpOrderId,$e)===1 && $r[1]!==$e[1];
    }

    private function sourceOrderReference($row): string
    {
        $normalized=(array)($row->normalized_data??[]);
        return strtoupper(trim((string)($normalized['source_order_reference']??'')));
    }

    private function assertBatch(WarehouseBillImportBatch $batch): void
    {
        if ((string)$batch->status!=='applied') throw new InvalidArgumentException('Warehouse bill batch must be applied before bridging.');
        if (strtolower((string)$batch->provider)!=='speeda-us-ny') throw new InvalidArgumentException('Speeda US-NY cost bridge requires speeda-us-ny provider.');
        if (!$batch->warehouse || strtoupper((string)$batch->warehouse->code)!=='US-NY') throw new InvalidArgumentException('Speeda US-NY cost bridge requires canonical US-NY warehouse.');
    }

    private function tracking(string $value): string { return strtoupper(preg_replace('/\s+/','',trim($value))??''); }
    /** @param array<string,mixed> $plan */ private function summary(array $plan): array { return ['source_rows'=>$plan['source_rows'],'group_count'=>count($plan['groups'])]+$plan['counts']; }
}
