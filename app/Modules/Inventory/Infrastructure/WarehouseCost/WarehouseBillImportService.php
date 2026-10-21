<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportRow;
use App\Modules\Inventory\Infrastructure\Models\WarehouseCostEntry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class WarehouseBillImportService
{
    public function __construct(
        private readonly WarehouseBillProviderRegistry $providers,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $path, Warehouse $warehouse, string $provider = 'moreplus'): array
    {
        $adapter = $this->providers->get($provider);
        $provider = $adapter->key();
        $adapter->assertWarehouse($warehouse);
        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) throw new InvalidArgumentException('Unable to checksum warehouse bill source.');

        $source = $adapter->read($path);
        $plannedRows = [];
        foreach ($source['rows'] as $row) {
            $normalized = [
                'category'=>(string)$row['category'],'source_sheet'=>(string)$row['source_sheet'],'source_row_number'=>(int)$row['source_row_number'],
                'transaction_number'=>$row['transaction_number'] ?? null,'outbound_order_number'=>$row['outbound_order_number'] ?? null,'source_order_reference'=>$row['source_order_reference'] ?? null,'tracking_number'=>$row['tracking_number'] ?? null,
                'charged_on'=>(string)$row['charged_on'],'quantity'=>(int)$row['quantity'],'charge_item_name'=>$row['charge_item_name'] ?? null,'charge_amount'=>(string)$row['charge_amount'],
            ];
            $plannedRows[] = $normalized + [
                'row_hash'=>hash('sha256', json_encode(['source_checksum'=>$checksum,'normalized'=>$normalized], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
                'raw_data'=>$row['raw_data'], 'normalized_data'=>$normalized,
            ];
        }
        $monthly = $this->monthly($plannedRows);
        $invoice = $source['invoice'];
        $manifest = array_map(fn(array $r): array => array_intersect_key($r, array_flip([
            'category','source_sheet','source_row_number','transaction_number','outbound_order_number','source_order_reference','tracking_number','charged_on','quantity','charge_item_name','charge_amount','row_hash',
        ])), $plannedRows);
        $planChecksum = hash('sha256', json_encode([
            'version'=>$adapter->parserVersion(),'provider'=>$provider,'source_checksum'=>$checksum,'warehouse_id'=>(int)$warehouse->id,
            'invoice'=>$invoice,'totals'=>$source['totals'],'periods'=>$source['periods'],'counts'=>$source['counts'],'monthly'=>$monthly,'rows'=>$manifest,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $existing = WarehouseBillImportBatch::query()->where('provider',$provider)->where('source_checksum',$checksum)->first();

        return [
            'mode'=>'dry_run','provider'=>$provider,'warehouse_id'=>(int)$warehouse->id,'warehouse_slug'=>(string)$warehouse->slug,
            'original_filename'=>basename($path),'source_checksum'=>$checksum,'parser_version'=>$adapter->parserVersion(),'plan_checksum'=>$planChecksum,
            'invoice_number'=>$invoice['invoice_number'],'client_name'=>$invoice['client_name'],'invoice_date'=>$invoice['invoice_date'],'currency'=>$invoice['currency'],
            'invoice_total'=>$source['totals']['total'],'invoice_core_total'=>$source['totals']['core_total'],'other_invoice_total'=>$source['totals']['other_invoice'],'other_invoice_categories'=>$invoice['other_categories'] ?? [],'invoice_summary_categories'=>$invoice['summary_categories'] ?? [],
            'shipping_total'=>$source['totals']['shipping'],'parcel_outbound_total'=>$source['totals']['parcel_outbound'],'storage_total'=>$source['totals']['storage'],
            'periods'=>$source['periods'],'counts'=>$source['counts'],'monthly'=>$monthly,'rows'=>$plannedRows,
            'already_applied'=>$existing !== null,'batch_id'=>$existing?->id,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $path, Warehouse $warehouse, ?Authenticatable $actor, string $provider = 'moreplus'): array
    {
        $preview = $this->preview($path, $warehouse, $provider);
        if ($preview['already_applied']) return [...$preview, 'mode'=>'apply'];

        return DB::transaction(function () use ($preview, $warehouse, $actor): array {
            $duplicate = WarehouseBillImportBatch::query()->where('provider',$preview['provider'])->where('source_checksum',$preview['source_checksum'])->lockForUpdate()->first();
            if ($duplicate) throw new RuntimeException('warehouse_bill_import_concurrent_duplicate');
            $batch = WarehouseBillImportBatch::query()->create([
                'provider'=>$preview['provider'],'warehouse_id'=>$warehouse->id,'original_filename'=>$preview['original_filename'],'source_checksum'=>$preview['source_checksum'],
                'parser_version'=>$preview['parser_version'],'plan_checksum'=>$preview['plan_checksum'],'status'=>'applied','invoice_number'=>$preview['invoice_number'],'client_name'=>$preview['client_name'],
                'invoice_date'=>$preview['invoice_date'],'currency'=>$preview['currency'],'invoice_total'=>$preview['invoice_total'],'shipping_total'=>$preview['shipping_total'],
                'parcel_outbound_total'=>$preview['parcel_outbound_total'],'storage_total'=>$preview['storage_total'],'total_rows'=>$preview['counts']['total'],
                'shipping_rows'=>$preview['counts']['shipping'],'parcel_outbound_rows'=>$preview['counts']['parcel_outbound'],'storage_rows'=>$preview['counts']['storage'],
                'applied_by'=>$actor?->getAuthIdentifier(),'applied_at'=>now(),
            ]);
            foreach ($preview['rows'] as $row) {
                WarehouseBillImportRow::query()->create([
                    'batch_id'=>$batch->id,'category'=>$row['category'],'source_sheet'=>$row['source_sheet'],'source_row_number'=>$row['source_row_number'],
                    'transaction_number'=>$row['transaction_number'],'outbound_order_number'=>$row['outbound_order_number'],'tracking_number'=>$row['tracking_number'],
                    'charged_on'=>$row['charged_on'],'quantity'=>$row['quantity'],'charge_item_name'=>$row['charge_item_name'],'charge_amount'=>$row['charge_amount'],
                    'row_hash'=>$row['row_hash'],'raw_data'=>$row['raw_data'],'normalized_data'=>$row['normalized_data'],
                ]);
            }
            foreach ($preview['monthly'] as $month) {
                foreach ($month['categories'] as $category => $amount) {
                    if (bccomp((string)$amount, '0', 4) === 0) continue;
                    WarehouseCostEntry::query()->create([
                        'warehouse_id'=>$warehouse->id,'cost_month'=>$month['month'].'-01','category'=>$category,'amount'=>$this->round2((string)$amount),'currency'=>$preview['currency'],
                        'note'=>$this->providerLabel($preview['provider']).' '.($preview['invoice_number'] ?: $preview['original_filename']).' · '.$this->categoryLabel($category),
                        'source_type'=>'warehouse_bill_import','source_reference'=>'warehouse-bill:'.$batch->id.':'.$month['month'].':'.$category,
                        'created_by_user_id'=>$actor?->getAuthIdentifier(),
                    ]);
                }
            }
            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'inventory.warehouse_bill_import.applied', WarehouseBillImportBatch::class, $batch->id,
                after:['provider'=>$preview['provider'],'warehouse_id'=>(int)$warehouse->id,'invoice_number'=>$preview['invoice_number'],'source_checksum'=>$preview['source_checksum'],'plan_checksum'=>$preview['plan_checksum'],'invoice_total'=>$preview['invoice_total'],'invoice_core_total'=>$preview['invoice_core_total'],'other_invoice_total'=>$preview['other_invoice_total'],'other_invoice_categories'=>$preview['other_invoice_categories'],'counts'=>$preview['counts']],
                reason:'warehouse_bill_import',
            );
            return [...$preview, 'mode'=>'apply','batch_id'=>(int)$batch->id,'already_applied'=>false];
        });
    }

    /** @param list<array<string,mixed>> $rows @return list<array{month:string,total:string,categories:array<string,string>}> */
    private function monthly(array $rows): array
    {
        $months = [];
        foreach ($rows as $row) {
            $month = substr((string)$row['charged_on'],0,7); $category=(string)$row['category']; $amount=(string)$row['charge_amount'];
            $months[$month] ??= ['shipping'=>'0.0000','parcel_outbound'=>'0.0000','storage'=>'0.0000','other'=>'0.0000'];
            $months[$month][$category] = bcadd($months[$month][$category] ?? '0.0000', $amount, 4);
        }
        ksort($months); $out=[];
        foreach ($months as $month => $categories) {
            $total='0.0000'; foreach($categories as $amount)$total=bcadd($total,$amount,4);
            $out[]=['month'=>$month,'total'=>$total,'categories'=>$categories];
        }
        return $out;
    }

    private function providerLabel(string $provider): string { return ['moreplus'=>'MorePlus','speeda-us-ny'=>'速达美东仓'][$provider] ?? $provider; }
    private function categoryLabel(string $category): string { return ['shipping'=>'Shipping','parcel_outbound'=>'Parcel Outbound','storage'=>'Storage','other'=>'Other'][$category] ?? $category; }
    private function round2(string $value): string
    {
        $negative=str_starts_with($value,'-');$value=ltrim($value,'+-');[$whole,$fraction]=array_pad(explode('.',$value,2),2,'');$fraction=preg_replace('/\D/','',$fraction)?:'';$kept=str_pad(substr($fraction,0,2),2,'0');
        if(strlen($fraction)>2&&(int)$fraction[2]>=5){$rounded=bcadd($whole.'.'.$kept,'0.01',2);[$whole,$kept]=array_pad(explode('.',$rounded,2),2,'');}
        $result=$whole.'.'.str_pad($kept,2,'0');return $negative&&$result!=='0.00'?'-'.$result:$result;
    }
}
