<?php

namespace Tests\Feature;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseBillImportService;
use App\Modules\Inventory\Infrastructure\WarehouseCost\SpeedaUsNyCostBridgeService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

final class InventorySpeedaUsNyBillProviderV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_real_shape_multiline_outbound_is_grouped_and_storage_uses_transaction_business_date(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'us-ny')->firstOrFail();
        $preview = app(WarehouseBillImportService::class)->preview($this->xlsx(), $warehouse, 'speeda-us-ny');

        $this->assertSame('speeda-us-ny-bill-v1', $preview['parser_version']);
        $this->assertSame('16.2000', $preview['invoice_total']);
        $this->assertSame('10.0000', $preview['shipping_total']);
        $this->assertSame('3.7000', $preview['parcel_outbound_total']);
        $this->assertSame('2.5000', $preview['storage_total']);
        $this->assertSame(['shipping'=>1,'parcel_outbound'=>1,'storage'=>1,'total'=>3], $preview['counts']);

        $shipping = collect($preview['rows'])->firstWhere('category', 'shipping');
        $handling = collect($preview['rows'])->firstWhere('category', 'parcel_outbound');
        $storage = collect($preview['rows'])->firstWhere('category', 'storage');

        $this->assertSame('9235990407314800998017', $shipping['tracking_number']);
        $this->assertSame('C33822', $shipping['source_order_reference']);
        $this->assertSame('OBS0122602180RV', $shipping['outbound_order_number']);
        $this->assertSame(2, $handling['quantity']);
        $this->assertSame('2026-03-01', $storage['charged_on']);
    }

    public function test_outbound_date_falls_back_to_canonical_transaction_number_when_source_time_is_blank(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'us-ny')->firstOrFail();
        $preview = app(WarehouseBillImportService::class)->preview($this->xlsx('C33822', '9235990407314800998017', ''), $warehouse, 'speeda-us-ny');

        $shipping = collect($preview['rows'])->firstWhere('category', 'shipping');
        $handling = collect($preview['rows'])->firstWhere('category', 'parcel_outbound');

        $this->assertSame('2026-02-18', $shipping['charged_on']);
        $this->assertSame('2026-02-18', $handling['charged_on']);
        $this->assertSame('9235990407314800998017', $shipping['tracking_number']);
    }

    public function test_cost_bridge_exact_tracking_is_ready_and_missing_or_kol_fail_closed(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'us-ny')->firstOrFail();
        $order = Order::query()->create([
            'internal_order_number'=>'USNY-BRIDGE-1',
            'erp_order_id'=>'007-0213-c33822',
            'source_type'=>'manual',
            'order_kind'=>'customer',
            'order_date'=>'2026-02-13',
            'order_status'=>'shipped',
            'currency'=>'USD',
        ]);
        $shipment = new Shipment([
            'shipment_status'=>'shipped',
            'warehouse_id'=>$warehouse->id,
            'tracking_number'=>'9235990407314800998017',
            'shipped_at'=>'2026-02-18',
        ]);
        $order->shipment()->save($shipment);

        $applied = app(WarehouseBillImportService::class)->apply($this->xlsx(), $warehouse, null, 'speeda-us-ny');
        $preview = app(SpeedaUsNyCostBridgeService::class)->preview((int)$applied['batch_id']);

        $this->assertSame(1, $preview['shipping']['ready']);
        $this->assertSame(1, $preview['parcel_outbound']['ready']);
        $this->assertSame('tracking_exact', $preview['groups']['shipping'][0]['reason_code']);
        $this->assertSame((int)$shipment->id, $preview['groups']['shipping'][0]['shipment_id']);

        $missing = app(WarehouseBillImportService::class)->apply($this->xlsx('C39999', '9235990999999999999999'), $warehouse, null, 'speeda-us-ny');
        $missingPreview = app(SpeedaUsNyCostBridgeService::class)->preview((int)$missing['batch_id']);
        $this->assertSame(1, $missingPreview['shipping']['review']);

        $kol = app(WarehouseBillImportService::class)->apply($this->xlsx('KOL26021801-007', '9235990888888888888888'), $warehouse, null, 'speeda-us-ny');
        $kolPreview = app(SpeedaUsNyCostBridgeService::class)->preview((int)$kol['batch_id']);
        $this->assertSame(1, $kolPreview['shipping']['excluded']);
        $this->assertSame('source_marked_kol', $kolPreview['groups']['shipping'][0]['reason_code']);
    }

    public function test_speeda_provider_rejects_non_us_ny_warehouse(): void
    {
        $wrong = Warehouse::query()->where('slug', 'us-la')->firstOrFail();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical US-NY');
        app(WarehouseBillImportService::class)->preview($this->xlsx(), $wrong, 'speeda-us-ny');
    }

    private function xlsx(string $reference='C33822', string $tracking='9235990407314800998017', string $outboundDate='2026-02-18 07:42:43'): string
    {
        $summary = [
            1 => ['账单编号(Bill No)：','BL#TEST','仓库(Warehouse):','美东-NJ-01 (US01)'],
            2 => ['客户ID(Client ID):','LRKJ (2881012)','费用账期(Billing Period):','2026-03-01至2026-03-31'],
            3 => ['账单创建时间(Statement Date):','2026-04-14 14:57:49'],
            5 => ['入库费用(Inbound Charges)','0'],
            6 => ['出库费用(Outbound Charges)','13.7000'],
            7 => ['仓租费用(Storage Charges)','2.5000'],
            8 => ['工单费用(Work Order Charges)','0'],
            9 => ['其他费用(Other Charges)','0'],
            10 => ['费用小计(SubTotal)','16.2000'],
        ];
        $outbound = [
            1 => ['费用单据号','操作费','物流费','费用小计','币种','操作费计费项','计费项费用','OMS单据号','出库时间','物流跟踪号','平台单号','参考单号','SKU','数量'],
            2 => ['TR#20260218001078','3.7','10','13.7','USD','NJ合并订单包材费','0.5','OBS0122602180RV',$outboundDate,$tracking,'44',$reference,'BY489A','1'],
            3 => ['TR#20260218001078','44','44','','USD','NJ出库费','3.2','OBS0122602180RV',$outboundDate,$tracking,'44',$reference,'MF414A','1'],
        ];
        $storage = [
            1 => ['费用单据号','计费时间','费用小计','币种'],
            2 => ['TR#20260301001007','2026-04-14 11:09:31','2.5000','USD'],
        ];

        $path = tempnam(sys_get_temp_dir(), 'speeda-usny-').'.xlsx';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE) === true);
        $names = ['账户汇总账单(Summary)','出库费用明细(outbound fee)','仓租费用(Bill Modify)'];
        $sheets=''; $rels='';
        foreach ($names as $i=>$name) {
            $id=$i+1;
            $sheets.='<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $rels.='<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
        }
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        foreach ([$summary,$outbound,$storage] as $i=>$rows) $zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml', $this->sheet($rows));
        $zip->close();
        return $path;
    }

    private function sheet(array $rows): string
    {
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rn=>$row) {
            $xml.='<row r="'.$rn.'">';
            foreach (array_values($row) as $ci=>$value) {
                $ref=$this->column($ci).$rn;
                $xml.='<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string)$value,ENT_XML1).'</t></is></c>';
            }
            $xml.='</row>';
        }
        return $xml.'</sheetData></worksheet>';
    }

    private function column(int $zeroBased): string
    {
        $n=$zeroBased+1; $letters='';
        while ($n>0) { $n--; $letters=chr(65+$n%26).$letters; $n=intdiv($n,26); }
        return $letters;
    }
}
