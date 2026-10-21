<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Finance\Application\AccountingContinuousDeltaService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use RuntimeException;

final class WarehouseBillReconciliationService
{
    public function __construct(
        private readonly MorePlusCostBridgeService $morePlus,
        private readonly SpeedaUsNyCostBridgeService $speedaUsNy,
        private readonly AccountingContinuousDeltaService $accountingDelta,
    ) {}

    public function supports(WarehouseBillImportBatch $batch): bool
    {
        return in_array(strtolower((string) $batch->provider), ['moreplus','speeda-us-ny'], true);
    }

    /** @return array<string,mixed> */
    public function preview(Warehouse $warehouse, WarehouseBillImportBatch $batch): array
    {
        $this->assertBatchWarehouse($warehouse, $batch);
        if (! $this->supports($batch)) throw new RuntimeException('warehouse_bill_reconciliation_provider_unsupported');
        return match(strtolower((string)$batch->provider)) {
            'moreplus' => $this->morePlus->preview((int)$batch->id),
            'speeda-us-ny' => $this->speedaUsNy->preview((int)$batch->id),
            default => throw new RuntimeException('warehouse_bill_reconciliation_provider_unsupported'),
        };
    }

    /** @return array<string,mixed> */
    public function applyExact(Warehouse $warehouse, WarehouseBillImportBatch $batch, string $expectedSource, string $expectedPlan): array
    {
        $this->assertBatchWarehouse($warehouse, $batch);
        if (! $this->supports($batch)) throw new RuntimeException('warehouse_bill_reconciliation_provider_unsupported');
        if (strtolower((string)$batch->provider) === 'speeda-us-ny') {
            throw new RuntimeException('warehouse_bill_reconciliation_provider_apply_not_enabled');
        }
        if (app()->environment('production') && ! filter_var(getenv('ERP_WAREHOUSE_BILL_RECONCILIATION_WEB_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('warehouse_bill_reconciliation_production_apply_disabled');
        }
        $fresh = $this->morePlus->preview((int) $batch->id);
        $source = strtolower(trim($expectedSource));
        $plan = strtolower(trim($expectedPlan));
        if (preg_match('/^[a-f0-9]{64}$/D', $source) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $plan) !== 1
            || ! hash_equals($source, strtolower((string) $fresh['source_checksum']))
            || ! hash_equals($plan, strtolower((string) $fresh['plan_checksum']))) {
            throw new RuntimeException('warehouse_bill_reconciliation_preflight_mismatch');
        }
        $result = $this->morePlus->apply((int) $batch->id);
        $result['accounting_delta'] = $this->syncAccountingDelta();
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function history(Warehouse $warehouse, int $limit = 6): array
    {
        return WarehouseBillImportBatch::query()->where('warehouse_id', $warehouse->id)->latest('id')->limit($limit)->get()->map(function (WarehouseBillImportBatch $batch): array {
            return [
                'id'=>(int)$batch->id,'provider'=>(string)$batch->provider,'invoice_number'=>$batch->invoice_number,'invoice_date'=>$batch->invoice_date?->toDateString(),
                'currency'=>(string)$batch->currency,'invoice_total'=>(string)$batch->invoice_total,'shipping_total'=>(string)$batch->shipping_total,
                'parcel_outbound_total'=>(string)$batch->parcel_outbound_total,'storage_total'=>(string)$batch->storage_total,'total_rows'=>(int)$batch->total_rows,
                'source_checksum'=>(string)$batch->source_checksum,'applied_at'=>$batch->applied_at?->toIso8601String(),'supported'=>$this->supports($batch),
                'reconciliation'=>null,'reconciliation_mode'=>'on_demand',
            ];
        })->all();
    }


    /** @return array<string,mixed> */
    public function dashboard(Warehouse $warehouse, int $limit = 12): array
    {
        $batches = WarehouseBillImportBatch::query()->where('warehouse_id', $warehouse->id)->latest('id')->limit($limit)->get();
        $months = []; $totals = ['batches'=>0,'rows'=>0,'ready'=>null,'review'=>null,'excluded'=>null,'matched_shipments'=>null,'matched_orders'=>null];
        foreach ($batches as $batch) {
            $month = $batch->invoice_date?->format('Y-m') ?? 'unknown';
            $months[$month] ??= ['month'=>$month,'batches'=>0,'rows'=>0,'currencies'=>[],'ready'=>null,'review'=>null,'excluded'=>null,'status'=>'on_demand'];
            $months[$month]['batches']++; $months[$month]['rows'] += (int)$batch->total_rows; $totals['batches']++; $totals['rows'] += (int)$batch->total_rows;
            $currency=(string)$batch->currency; $months[$month]['currencies'][$currency]=bcadd((string)($months[$month]['currencies'][$currency]??'0'),(string)$batch->invoice_total,4);
        }
        krsort($months);
        return [
            'mode'=>'lightweight',
            'totals'=>$totals,
            'months'=>array_values($months),
            'review_queue'=>[],
            'review_queue_mode'=>'on_demand',
            'accounting_delta'=>$this->accountingDeltaPreview(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function reviewQueue(Warehouse $warehouse, int $batchLimit = 12, int $rowLimit = 100): array
    {
        $out=[]; $batches=WarehouseBillImportBatch::query()->where('warehouse_id',$warehouse->id)->latest('id')->limit($batchLimit)->get();
        foreach($batches as $batch){ if(!$this->supports($batch)||(string)$batch->status!=='applied')continue; $preview=$this->preview($warehouse,$batch);
            foreach(['shipping','parcel_outbound'] as $category) foreach(($preview['groups'][$category]??[]) as $group){ if(!in_array((string)$group['decision'],['review','excluded'],true))continue;
                $out[]=['batch_id'=>(int)$batch->id,'invoice_number'=>$batch->invoice_number,'category'=>$category,'decision'=>$group['decision'],'reason_code'=>$group['reason_code'],'tracking_number'=>$group['tracking_number'],'source_order_references'=>$group['source_order_references'],'shipment_id'=>$group['shipment_id'],'order_id'=>$group['order_id'],'amount'=>$group['amount'],'currency'=>$group['currency']];
                if(count($out)>=$rowLimit)return $out; }
        } return $out;
    }

    /** @return array<string,mixed> */
    private function accountingDeltaPreview(): array
    {
        if (! (bool) config('erp_accounting.multi_shipment.enabled', false)) return ['enabled'=>false,'gate'=>'multi_shipment_disabled'];
        if (! (bool) config('erp_accounting.warehouse_bill_reconciliation.continuous_delta_enabled', false)) return ['enabled'=>false,'mode'=>'disabled','gate'=>'continuous_delta_disabled','candidate_count'=>0,'failures'=>0];
        $p=$this->accountingDelta->preview(1000); return ['enabled'=>true,'mode'=>'dry_run','gate'=>$p['gate']??'pass','candidate_count'=>$p['candidate_count']??0,'failures'=>$p['failures']??0,'stages'=>array_intersect_key($p['stages']??[],array_flip(['warehouse_cost','last_mile_actual','overseas_handling_actual']))];
    }

    /** @return array<string,mixed> */
    private function syncAccountingDelta(): array
    {
        $preview=$this->accountingDeltaPreview();
        if(!($preview['enabled']??false))return $preview;
        if(app()->environment('production')&&!filter_var(getenv('ERP_WAREHOUSE_BILL_ACCOUNTING_DELTA_PRODUCTION_APPLY_ENABLED')?:'false',FILTER_VALIDATE_BOOL))return [...$preview,'mode'=>'blocked','gate'=>'production_apply_disabled'];
        $r=$this->accountingDelta->apply(1000); return ['enabled'=>true,'mode'=>'apply','gate'=>$r['gate']??'pass','candidate_count'=>$r['candidate_count']??0,'processed'=>$r['processed']??0,'failures'=>$r['failures']??0,'run_id'=>$r['run_id']??null,'run_status'=>$r['run_status']??null];
    }

    /** @param array<string,mixed> $preview */
    private function status(array $preview): string
    {
        $ready=(int)$preview['shipping']['ready']+(int)$preview['parcel_outbound']['ready'];
        $review=(int)$preview['shipping']['review']+(int)$preview['parcel_outbound']['review']+(int)($preview['storage']['review_count']??0);
        $done=$ready===0 && (int)$preview['shipping']['skip']>0 && (int)$preview['parcel_outbound']['skip']>0 && (bool)($preview['storage']['already_applied']??false);
        if ($done) return $review>0?'applied_with_review':'applied';
        return $ready>0?'ready':'review';
    }

    private function assertBatchWarehouse(Warehouse $warehouse, WarehouseBillImportBatch $batch): void
    {
        if ((int)$batch->warehouse_id !== (int)$warehouse->id) throw new RuntimeException('warehouse_bill_reconciliation_warehouse_mismatch');
        if ((string)$batch->status !== 'applied') throw new RuntimeException('warehouse_bill_reconciliation_batch_not_applied');
    }
}
