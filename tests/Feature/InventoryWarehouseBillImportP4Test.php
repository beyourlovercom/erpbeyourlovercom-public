<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseCostEntry;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseBillImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

final class InventoryWarehouseBillImportP4Test extends TestCase
{
    use RefreshDatabase;

    public function test_moreplus_bill_applies_immutable_evidence_and_projects_costs_by_real_charge_month(): void
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $file=$this->xlsx();
        $service=app(WarehouseBillImportService::class);
        $preview=$service->preview($file,$warehouse,'moreplus');

        $this->assertSame('20.2777',$preview['invoice_total']);
        $this->assertSame('20.2777',$preview['invoice_core_total']);
        $this->assertSame('0.0000',$preview['other_invoice_total']);
        $this->assertSame([],$preview['other_invoice_categories']);
        $this->assertSame(['shipping'=>1,'parcel_outbound'=>1,'storage'=>2,'total'=>4],$preview['counts']);
        $parcelRow=collect($preview['rows'])->firstWhere('category','parcel_outbound'); $this->assertSame('C2',$parcelRow['source_order_reference']);
        $this->assertSame('2026-07',$preview['monthly'][0]['month']);
        $this->assertSame('17.7777',$preview['monthly'][0]['total']);
        $this->assertSame('2026-08',$preview['monthly'][1]['month']);
        $this->assertSame('2.5000',$preview['monthly'][1]['total']);

        $result=$service->apply($file,$warehouse,null,'moreplus');
        $this->assertFalse($result['already_applied']);
        $this->assertDatabaseCount('warehouse_bill_import_batches',1);
        $this->assertDatabaseCount('warehouse_bill_import_rows',4);
        $this->assertDatabaseCount('warehouse_cost_entries',3);
        $shipping=WarehouseCostEntry::query()->where('category','shipping')->firstOrFail();
        $storage=WarehouseCostEntry::query()->where('category','storage')->firstOrFail();
        $parcel=WarehouseCostEntry::query()->where('category','parcel_outbound')->firstOrFail();
        $this->assertSame('2026-07-01',$shipping->cost_month->toDateString());$this->assertSame('10.00',(string)$shipping->amount);$this->assertSame('USD',$shipping->currency);$this->assertSame('warehouse_bill_import',$shipping->source_type);
        $this->assertSame('2026-07-01',$storage->cost_month->toDateString());$this->assertSame('7.78',(string)$storage->amount);
        $this->assertSame('2026-08-01',$parcel->cost_month->toDateString());$this->assertSame('2.50',(string)$parcel->amount);
        $this->assertDatabaseHas('audit_records',['action'=>'inventory.warehouse_bill_import.applied']);

        $again=$service->apply($file,$warehouse,null,'moreplus');
        $this->assertTrue($again['already_applied']);
        $this->assertDatabaseCount('warehouse_bill_import_batches',1);
        $this->assertDatabaseCount('warehouse_bill_import_rows',4);
        $this->assertDatabaseCount('warehouse_cost_entries',3);
    }

    public function test_moreplus_bill_fails_closed_for_wrong_warehouse_or_inconsistent_invoice(): void
    {
        $service=app(WarehouseBillImportService::class);
        $wrong=Warehouse::query()->where('slug','us-ny')->firstOrFail();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical US-LA');
        $service->preview($this->xlsx(),$wrong,'moreplus');
    }

    public function test_inconsistent_invoice_fails_closed_without_any_write(): void
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        try { app(WarehouseBillImportService::class)->preview($this->xlsx(true),$warehouse,'moreplus'); $this->fail('Expected inconsistent invoice to fail closed.'); }
        catch (InvalidArgumentException $e) { $this->assertStringContainsString('Invoice total is inconsistent',$e->getMessage()); }
        $this->assertDatabaseCount('warehouse_bill_import_batches',0);$this->assertDatabaseCount('warehouse_bill_import_rows',0);$this->assertDatabaseCount('warehouse_cost_entries',0);
    }

    public function test_v3_accepts_non_core_invoice_categories_but_keeps_cost_projection_core_only(): void
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $service=app(WarehouseBillImportService::class);
        $preview=$service->preview($this->xlsx(false,true),$warehouse,'moreplus');

        $this->assertSame('moreplus-bill-v3',$preview['parser_version']);
        $this->assertSame('25.2777',$preview['invoice_total']);
        $this->assertSame('20.2777',$preview['invoice_core_total']);
        $this->assertSame('5.0000',$preview['other_invoice_total']);
        $this->assertSame(['Inbound'=>'5.0000'],$preview['other_invoice_categories']);
        $this->assertSame(['shipping'=>1,'parcel_outbound'=>1,'storage'=>2,'total'=>4],$preview['counts']);

        $service->apply($this->xlsx(false,true),$warehouse,null,'moreplus');
        $this->assertDatabaseHas('warehouse_bill_import_batches',['invoice_total'=>'25.2777','shipping_total'=>'10.0000','parcel_outbound_total'=>'2.5000','storage_total'=>'7.7777']);
        $this->assertSame(3,WarehouseCostEntry::query()->where('source_type','warehouse_bill_import')->count());
        $this->assertFalse(WarehouseCostEntry::query()->where('source_type','warehouse_bill_import')->where('category','other')->exists());
    }

    public function test_v3_accepts_real_invoice_layout_drift_without_fixed_row_or_column_coordinates(): void
    {
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $preview=app(WarehouseBillImportService::class)->preview($this->xlsx(false,false,true),$warehouse,'moreplus');
        $this->assertSame('IN-TEST-001',$preview['invoice_number']);
        $this->assertSame('2026-08-10',$preview['invoice_date']);
        $this->assertSame('20.2777',$preview['invoice_total']);
    }

    public function test_manager_can_preview_and_confirm_moreplus_bill_but_viewer_cannot(): void
    {
        Storage::fake('local');
        $warehouse=Warehouse::query()->where('slug','us-la')->firstOrFail();
        $manager=$this->userWith(['inventory.view','inventory.warehouses.manage']);
        $file=$this->xlsx();
        $upload=new UploadedFile($file,'moreplus.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $key='inventory.warehouse_bill_preview.'.$warehouse->id;

        $this->actingAs($manager)->post('/inventory/us-la/costs/import/preview',['bill'=>$upload])->assertRedirect()->assertSessionHas($key);
        $this->actingAs($manager)->get('/inventory/us-la')->assertOk()->assertInertia(fn($page)=>$page
            ->where('billImportPreview.invoice_number','IN-TEST-001')
            ->where('billImportPreview.invoice_total','20.2777')
            ->where('billImportPreview.counts.total',4));
        $this->actingAs($manager)->post('/inventory/us-la/costs/import/apply')->assertRedirect()->assertSessionMissing($key);
        $this->assertSame(1,WarehouseBillImportBatch::query()->count());
        $this->assertSame(3,WarehouseCostEntry::query()->where('source_type','warehouse_bill_import')->count());

        $viewer=$this->userWith(['inventory.view']);
        $upload2=new UploadedFile($this->xlsx(),'moreplus.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
        $this->actingAs($viewer)->post('/inventory/us-la/costs/import/preview',['bill'=>$upload2])->assertForbidden();
    }

    private function userWith(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);$role=Role::create(['key'=>'warehouse-bill-'.uniqid(),'label'=>'Warehouse Bill Test']);
        foreach($permissions as $key){$permission=Permission::firstOrCreate(['key'=>$key],['label'=>$key]);$role->permissions()->syncWithoutDetaching([$permission->id]);}$user->roles()->attach($role);return $user;
    }

    private function xlsx(bool $badInvoice=false, bool $withOther=false, bool $layoutDrift=false): string
    {
        $invoiceTotal=$withOther?'25.2777':'20.2777';
        if($badInvoice)$invoiceTotal=$withOther?'26.2777':'21.2777';
        if($layoutDrift){
            $invoice=[8=>[1=>'BILL TO',5=>'INVOICE #',6=>'IN-TEST-001'],9=>[1=>'(999)TEST',5=>'INVOICE DATE',6=>'2026-08-10 00:00:00'],
                15=>[1=>'TRANSACTION TYPE',4=>'QTY',5=>'PAID AMOUNT',6=>'TOTAL AMOUNT'],17=>[1=>'Parcel Outbound',4=>'1',5=>'0',6=>'2.5000'],
                21=>[1=>'Storage',4=>'2',5=>'0',6=>'7.7777'],22=>[1=>'Shipping',4=>'1',5=>'0',6=>'10.0000'],25=>[5=>'SUBTOTAL',6=>'20.2777'],28=>[5=>'TOTAL',6=>$invoiceTotal]];
        }else{
            $invoice=[4=>[1=>'BILL TO',5=>'INVOICE #',6=>'IN-TEST-001'],5=>[1=>'(999)TEST',5=>'INVOICE DATE',6=>'2026-08-10 00:00:00'],
                11=>[1=>'TRANSACTION TYPE',4=>'QTY',5=>'PAID AMOUNT',6=>'TOTAL AMOUNT'],13=>[1=>'Parcel Outbound',4=>'1',5=>'0',6=>'2.5000'],
                17=>[1=>'Storage',4=>'2',5=>'0',6=>'7.7777'],18=>[1=>'Shipping',4=>'1',5=>'0',6=>'10.0000'],24=>[5=>'TOTAL',6=>$invoiceTotal]];
            if($withOther)$invoice[12]=[1=>'Inbound',4=>'1',5=>'0',6=>'5.0000'];
        }
        $parcel=[5=>['Outbound Order #','Transaction #','Transaction Amount','Amount Due','SKU','Qty','Charge Item Name','Charge Amount','','Order #','Tracking #','Transaction Date'],
            7=>['OB-2','TR-P-1','$2.5000','$2.5000','SKU-P','1','一件代发操作费 -1','2.5000','','C2','TRACK-P','2026-08-04']];
        $storage=[5=>['Transaction #','Transaction Amount','Amount Due','Storage Date','Charge Item Name','Charge Amount'],
            7=>['TR-S-1','$3.3333','$3.3333','2026-07-01','仓储费','3.3333'],8=>['TR-S-2','$4.4444','$4.4444','2026-07-31','仓储费','4.4444']];
        $shipping=[5=>['Outbound Order #','Transaction #','Transaction Amount','Amount Due','SKU','Qty','Charge Item Name','Charge Amount','Transaction Date','','','Tracking #'],
            7=>['OB-1','TR-SH-1','$10.0000','$10.0000','SKU-S','1','shipping','10.0000','2026-07-10','','','TRACK-S']];
        $path=tempnam(sys_get_temp_dir(),'moreplus-p4-').'.xlsx';$zip=new ZipArchive();$this->assertTrue($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true);
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.implode('',array_map(fn($i)=>'<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',range(1,4))).'</Types>');
        $names=['Invoice','Parcel Outbound','Storage','Shipping'];$sheets='';$rels='';foreach($names as $i=>$name){$id=$i+1;$sheets.='<sheet name="'.htmlspecialchars($name,ENT_XML1).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';$rels.='<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';}
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        foreach([$invoice,$parcel,$storage,$shipping] as $i=>$rows)$zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml',$this->sheet($rows));$zip->close();return $path;
    }

    /** @param array<int,array<int|string,mixed>> $rows */
    private function sheet(array $rows): string
    {
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach($rows as $rn=>$row){$xml.='<row r="'.$rn.'">';foreach($row as $ci=>$value){$index=is_int($ci)?$ci:(int)$ci;$letters=$this->column($index);$xml.='<c r="'.$letters.$rn.'" t="inlineStr"><is><t>'.htmlspecialchars((string)$value,ENT_XML1).'</t></is></c>';}$xml.='</row>';}$xml.='</sheetData></worksheet>';return $xml;
    }
    private function column(int $zeroBased): string { $n=$zeroBased+1;$letters='';while($n>0){$n--;$letters=chr(65+$n%26).$letters;$n=intdiv($n,26);}return $letters; }
}
