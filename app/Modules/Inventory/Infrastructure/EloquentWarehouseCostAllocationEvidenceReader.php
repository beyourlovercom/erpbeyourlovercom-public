<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\WarehouseCostAllocationEvidenceReader;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageCostImportRow;

final class EloquentWarehouseCostAllocationEvidenceReader implements WarehouseCostAllocationEvidenceReader
{
    public function read(int $orderId): ?array
    {
        $rows=WarehouseStorageCostImportRow::query()->with('batch')->where('order_id',$orderId)->where('decision','ready')->orderBy('id')->get();
        if($rows->isEmpty()) return null;
        $batchIds=$rows->pluck('batch_id')->unique()->values();
        if($batchIds->count()!==1) return ['blocked'=>'warehouse_storage_allocation_ambiguous','batch_ids'=>$batchIds->all()];
        $batch=$rows->first()->batch;
        if(!$batch || $batch->status!=='applied') return ['blocked'=>'warehouse_storage_batch_not_applied'];
        $amount='0.0000'; $units=0; $rowIds=[];
        foreach($rows as $row){ $amount=bcadd($amount,(string)$row->allocated_source_amount,4); $units+=(int)$row->outbound_units; $rowIds[]=(int)$row->id; }
        return ['batch_id'=>(int)$batch->id,'warehouse_id'=>(int)$batch->warehouse_id,'billing_period_start'=>$batch->billing_period_start->toDateString(),'billing_period_end'=>$batch->billing_period_end->toDateString(),
            'source_amount'=>$amount,'currency'=>(string)$batch->currency,'outbound_units'=>$units,'row_ids'=>$rowIds,'source_checksum'=>(string)$batch->source_checksum,'plan_checksum'=>(string)$batch->plan_checksum,
            'source_at'=>$batch->applied_at?->toISOString()??$batch->updated_at?->toISOString()];
    }
}
