<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Application\InventoryStockStatus;
use App\Modules\Inventory\Application\WarehouseClearanceService;
use App\Modules\Inventory\Application\WarehouseInboundProjection;
use App\Modules\Inventory\Application\WarehouseInboundLifecycleService;
use App\Modules\Inventory\Application\WarehouseRestockProjection;
use App\Modules\Inventory\Application\WarehouseRestockPlanService;
use App\Modules\Inventory\Application\WarehouseRestockDocumentJobService;
use App\Modules\Inventory\Application\WarehouseRestockPackageService;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockDocumentJob;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseBillImportService;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseBillReconciliationService;
use App\Modules\Inventory\Infrastructure\Models\WarehouseBillImportBatch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Logistics\Application\WarehouseShipmentProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WarehouseController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly WarehouseClearanceService $clearance,
        private readonly WarehouseInboundProjection $inbounds,
        private readonly WarehouseInboundLifecycleService $inboundLifecycle,
        private readonly WarehouseRestockProjection $restock,
        private readonly WarehouseRestockPlanService $restockPlans,
        private readonly WarehouseRestockDocumentJobService $restockDocumentJobs,
        private readonly WarehouseRestockPackageService $restockPackages,
        private readonly WarehouseBillImportService $billImport,
        private readonly WarehouseBillReconciliationService $reconciliation,
        private readonly WarehouseShipmentProjection $shipments,
    ) {}
    public function create(): Response { return Inertia::render('Inventory/WarehouseCreate'); }
    public function store(Request $request): RedirectResponse
    {
        $data=$request->validate($this->warehouseRules());
        $w=$this->inventory->createWarehouse($data,$request->user());
        return redirect()->route('inventory.warehouse.show',$this->inventory->warehousePublicSlug($w))->with('success','仓库已创建');
    }
    public function show(Request $request,string $warehouse): Response|RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $publicSlug=$this->inventory->warehousePublicSlug($w);
        if($warehouse!==$publicSlug) return redirect()->route('inventory.warehouse.show',$publicSlug);
        $latestRestockPlan=$this->restockPlans->latestForWarehouse($w);
        return Inertia::render('Inventory/WarehouseShow',[
            'warehouse'=>$this->payload($w),
            'clearanceProducts'=>$this->clearance->listForWarehouse($w),
            'clearanceCandidates'=>$this->clearance->candidatesForWarehouse($w),
            'costCenter'=>$this->inventory->warehouseCostCenter($w),
            'billImportPreview'=>data_get(session($this->billPreviewKey((int)$w->id)),'summary'),
            'billReconciliationHistory'=>$this->reconciliation->history($w),
            'billReconciliationDashboard'=>$this->reconciliation->dashboard($w),
            'billReconciliationPreview'=>session($this->billReconciliationPreviewKey((int)$w->id)),
            'inboundProjection'=>$this->inbounds->forWarehouse($w),
            'restockProjection'=>$this->restock->forWarehouse($w),
            'latestRestockPlan'=>$latestRestockPlan,
            'latestRestockLabelJob'=>$this->restockDocumentJobs->forPlanId($latestRestockPlan['id']??null),
            'shipmentProjection'=>$this->shipments->forWarehouse(
                $w,
                max(1,(int)$request->query('shipment_page',1)),
                (int)$request->query('shipment_per_page',50),
                (string)$request->query('shipment_q',''),
                (string)$request->query('shipment_status','all'),
            ),
            'canOperate'=>auth()->user()?->can(AuthorizationKeys::InventoryOperate->value) ?? false,
            'canManage'=>auth()->user()?->can(AuthorizationKeys::InventoryWarehousesManage->value) ?? false,
        ]);
    }
    public function downloadRestockDocument(
        string $warehouse,
        int $plan,
        int $job,
        string $artifact,
    ) {
        $w = $this->inventory->warehouse($warehouse);
        $documentJob = WarehouseRestockDocumentJob::query()
            ->whereKey($job)
            ->where('warehouse_id', $w->id)
            ->where('restock_plan_id', $plan)
            ->where('status', WarehouseRestockDocumentJobService::STATUS_SUCCEEDED)
            ->firstOrFail();

        $path = match ($artifact) {
            'pi' => $this->restockPackages->piPath($documentJob),
            'package' => $this->restockPackages->packagePath($documentJob),
            default => abort(404),
        };
        abort_unless(Storage::disk('local')->exists($path), 404);

        $filename = $artifact === 'pi'
            ? $documentJob->reference.'-PI.xlsx'
            : $documentJob->reference.'.zip';

        return Storage::disk('local')->download(
            $path,
            $filename,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    public function update(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $rules=$this->warehouseRules($w->id); unset($rules['slug']);
        $this->inventory->updateWarehouse($w,$request->validate($rules),$request->user());
        return back()->with('success','仓库信息已保存');
    }
    public function billing(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate(['billing_markdown'=>['nullable','string','max:200000']]);
        $this->inventory->updateBilling($w,$data['billing_markdown'] ?? null,$request->user());
        return back()->with('success','仓库计费表已保存');
    }
    public function regions(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate(['country_codes'=>['array'],'country_codes.*'=>['string','size:2']]);
        $this->inventory->updateRegions($w,$data['country_codes'] ?? [],$request->user());
        return back()->with('success','服务地区已保存');
    }
    public function addCost(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate([
            'cost_month'=>['required','date_format:Y-m'],
            'category'=>['required','in:shipping,parcel_outbound,storage,other'],
            'amount'=>['required','numeric','between:-999999999.99,999999999.99'],
            'currency'=>['required','string','size:3','alpha'],
            'note'=>['nullable','string','max:255'],
        ]);
        $this->inventory->addWarehouseCost($w,$data,$request->user());
        return back()->with('success','仓库费用已记录');
    }
    public function removeCost(Request $request,string $warehouse,int $cost): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $this->inventory->removeWarehouseCost($w,$cost,$request->user());
        return back()->with('success','手工仓库费用已删除');
    }
    public function previewBillImport(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $capability=$this->billImportCapability($w); abort_if($capability===null,404);
        $request->validate(['bill'=>['required','file','max:'.(int)$capability['max_kb']]]);
        $key=$this->billPreviewKey((int)$w->id);
        $old=session($key); if(is_array($old)&&isset($old['stored_path'])) Storage::disk('local')->delete((string)$old['stored_path']);
        $stored=$request->file('bill')->storeAs('warehouse-bill-previews',Str::uuid().'.'.$capability['extension'],'local');
        try {
            $preview=$this->billImport->preview(Storage::disk('local')->path($stored),$w,$capability['provider']);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($stored);
            return back()->withErrors(['bill'=>$e->getMessage()]);
        }
        session([$key=>['stored_path'=>$stored,'source_checksum'=>$preview['source_checksum'],'plan_checksum'=>$preview['plan_checksum'],'summary'=>$this->billPreviewPayload($preview)]]);
        return back()->with('success',$capability['label'].' 账单预览已生成');
    }
    public function applyBillImport(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);$capability=$this->billImportCapability($w); abort_if($capability===null,404);
        if (app()->environment('production') && $capability['provider']==='speeda-us-ny'
            && !filter_var(getenv('ERP_US_NY_WAREHOUSE_BILL_IMPORT_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
            return back()->withErrors(['bill'=>'US-NY Production bill import apply is disabled.']);
        }
        $key=$this->billPreviewKey((int)$w->id);$stored=session($key);
        if(!is_array($stored)||empty($stored['stored_path'])||!Storage::disk('local')->exists((string)$stored['stored_path'])) return back()->withErrors(['bill'=>'账单预览已失效，请重新上传。']);
        $path=Storage::disk('local')->path((string)$stored['stored_path']);
        try {
            $preview=$this->billImport->preview($path,$w,$capability['provider']);
            if(!hash_equals((string)$stored['source_checksum'],(string)$preview['source_checksum'])||!hash_equals((string)$stored['plan_checksum'],(string)$preview['plan_checksum'])) return back()->withErrors(['bill'=>'账单预览已变化，请重新上传。']);
            $result=$this->billImport->apply($path,$w,$request->user(),$capability['provider']);
        } catch (Throwable $e) { return back()->withErrors(['bill'=>$e->getMessage()]); }
        Storage::disk('local')->delete((string)$stored['stored_path']);session()->forget($key);
        return back()->with('success',($result['already_applied']??false)?'该 '.$capability['label'].' 账单已导入，无需重复处理。':$capability['label'].' 账单已导入仓库费用中心');
    }
    public function previewBillReconciliation(Request $request,string $warehouse,int $batch): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $b=WarehouseBillImportBatch::query()->findOrFail($batch);
        try { $preview=$this->reconciliation->preview($w,$b); }
        catch (Throwable $e) { return back()->withErrors(['reconciliation'=>$e->getMessage()]); }
        session([$this->billReconciliationPreviewKey((int)$w->id)=>[
            'batch_id'=>(int)$b->id,'source_checksum'=>(string)$preview['source_checksum'],'plan_checksum'=>(string)$preview['plan_checksum'],
            'shipping'=>array_intersect_key($preview['shipping'],array_flip(['ready','review','excluded','skip'])),
            'parcel_outbound'=>array_intersect_key($preview['parcel_outbound'],array_flip(['ready','review','excluded','skip'])),
            'storage'=>array_intersect_key($preview['storage'],array_flip(['ready_count','review_count','excluded_count','already_applied','batch_id'])),
        ]]);
        return back()->with('success','真实物流成本核销预览已刷新');
    }
    public function applyBillReconciliation(Request $request,string $warehouse,int $batch): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $b=WarehouseBillImportBatch::query()->findOrFail($batch);
        $data=$request->validate([
            'expected_source_checksum'=>['required','string','regex:/^[a-f0-9]{64}$/i'],
            'expected_plan_checksum'=>['required','string','regex:/^[a-f0-9]{64}$/i'],
        ]);
        try { $result=$this->reconciliation->applyExact($w,$b,(string)$data['expected_source_checksum'],(string)$data['expected_plan_checksum']); }
        catch (Throwable $e) { return back()->withErrors(['reconciliation'=>$e->getMessage()]); }
        session()->forget($this->billReconciliationPreviewKey((int)$w->id));
        return back()->with('success',sprintf('真实物流成本核销完成：Shipping %d / Handling %d', (int)($result['shipping_created']??0),(int)($result['handling_created']??0)));
    }

    public function saveInventory(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate($this->inventoryRules()); $productId=(int)$data['product_id']; unset($data['product_id']);
        $this->inventory->saveInventory($w,$productId,$data,$request->user());
        return back()->with('success','库存已保存');
    }
    public function updateInventory(Request $request,string $warehouse,int $inventory): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate($this->inventoryRules(false));
        $this->inventory->updateInventory($w,$inventory,$data,$request->user());
        return back()->with('success','库存已更新');
    }

    public function advanceInbound(Request $request,string $warehouse,int $batch): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate(['status'=>['required','string','in:preparing,factory_packing,in_transit,port_arrived,received,ready']]);
        $updated=$this->inboundLifecycle->advance($w,$batch,(string)$data['status'],$request->user());
        $labels=['preparing'=>'准备中','factory_packing'=>'工厂打包','in_transit'=>'运输中','port_arrived'=>'港口已到货','received'=>'仓库已入库','ready'=>'正常发货'];
        return back()->with('success','入库批次已更新：'.$updated->reference.' → '.($labels[$updated->status]??$updated->status));
    }


    public function confirmRestockPlan(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $plan=$this->restockPlans->confirm($w,$request->user());
        return back()->with('success','备货计划已确认：'.$plan->reference);
    }

    public function requestRestockBlueLabels(Request $request,string $warehouse,int $plan): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $result=$this->restockDocumentJobs->requestBlueLabels($w,$plan,$request->user());
        $message=$result['created']?'蓝标任务已排队：':'蓝标任务已存在：';
        return back()->with('success',$message.$result['job']->reference);
    }

    public function saveRestockSupplement(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate([
            'product_id'=>['required','integer','exists:products,id'],
            'manual_restock_qty'=>['required','integer','min:0','max:1000000'],
        ]);
        $this->inventory->saveRestockSupplement($w,(int)$data['product_id'],(int)$data['manual_restock_qty'],$request->user());
        return back()->with('success','补充备货数量已保存');
    }
    public function addClearance(Request $request,string $warehouse): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate(['product_ids'=>['required','array','min:1'],'product_ids.*'=>['integer','distinct','exists:products,id']]);
        $this->clearance->add($w,$data['product_ids'],$request->user());
        return back()->with('success','已加入清仓 / Gift 库');
    }
    public function updateClearance(Request $request,string $warehouse,int $clearance): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $data=$request->validate(['priority'=>['required','integer','min:1','max:9999']]);
        $this->clearance->updatePriority($w,$clearance,(int)$data['priority'],$request->user());
        return back()->with('success','Gift 优先级已更新');
    }
    public function removeClearance(Request $request,string $warehouse,int $clearance): RedirectResponse
    {
        $w=$this->inventory->warehouse($warehouse);
        $this->clearance->remove($w,$clearance,$request->user());
        return back()->with('success','已移出清仓 / Gift 库');
    }
    /** @return array{provider:string,label:string,extension:string,format_label:string,accept:string,max_kb:int}|null */
    private function billImportCapability($w): ?array
    {
        $code=strtoupper((string)$w->code);$config=config("warehouse_master.warehouses.{$code}.bill_import");
        if(!is_array($config)||($config['enabled']??false)!==true) return null;
        $provider=strtolower(trim((string)($config['provider']??'')));$extension=strtolower(trim((string)($config['extension']??'')));
        if($provider===''||preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/D',$provider)!==1||preg_match('/^[a-z0-9]{1,12}$/D',$extension)!==1) return null;
        return [
            'provider'=>$provider,'label'=>(string)($config['label']??strtoupper($provider)),'extension'=>$extension,
            'format_label'=>(string)($config['format_label']??strtoupper($extension)),'accept'=>(string)($config['accept']??('.'.$extension)),
            'max_kb'=>max(1,min(65536,(int)($config['max_kb']??16384))),
        ];
    }
    /** @return array{status:string,label:string,provider:?string,format_label:string,note:string}|null */
    private function billImportOnboarding($w): ?array
    {
        $code=strtoupper((string)$w->code); $config=config("warehouse_master.warehouses.{$code}.bill_import");
        if(!is_array($config)) return null;
        $enabled=($config['enabled']??false)===true;
        $provider=trim((string)($config['provider']??''));
        $status=$enabled?'active':(string)($config['status']??'awaiting_sample');
        return [
            'status'=>$status,
            'label'=>(string)($config['label']??($w->name??$code)),
            'provider'=>$provider!==''?$provider:null,
            'format_label'=>(string)($config['format_label']??'待确认'),
            'note'=>match($status) {
                'active' => 'Provider Adapter 已启用，可上传真实仓库账单并进入核销链。',
                'validating' => '已收到真实原始账单样本，Provider Adapter 正在做金额守恒、Shipment 匹配与 UAT；验证完成前继续禁止财务写入。',
                default => '等待该仓真实原始账单样本；未获得样本前禁止猜测字段或启用财务写入。',
            },
        ];
    }

    private function billPreviewKey(int $warehouseId): string { return 'inventory.warehouse_bill_preview.'.$warehouseId; }
    private function billReconciliationPreviewKey(int $warehouseId): string { return 'inventory.warehouse_bill_reconciliation_preview.'.$warehouseId; }
    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function billPreviewPayload(array $preview): array
    {
        return array_intersect_key($preview,array_flip(['provider','original_filename','source_checksum','plan_checksum','invoice_number','client_name','invoice_date','currency','invoice_total','invoice_core_total','other_invoice_total','other_invoice_categories','invoice_summary_categories','shipping_total','parcel_outbound_total','storage_total','periods','counts','monthly','already_applied','batch_id']));
    }
    private function warehouseRules(?int $ignoreId=null): array
    {
        return [
            'slug'=>['required','string','max:64','alpha_dash','unique:warehouses,slug'.($ignoreId?",{$ignoreId}":'')],
            'code'=>['required','string','max:64','unique:warehouses,code'.($ignoreId?",{$ignoreId}":'')],
            'name'=>['required','string','max:191'],'country_code'=>['nullable','string','size:2'],
            'address_line1'=>['nullable','string','max:255'],'address_line2'=>['nullable','string','max:255'],'city'=>['nullable','string','max:128'],
            'state_region'=>['nullable','string','max:128'],'postal_code'=>['nullable','string','max:32'],'timezone'=>['nullable','string','max:64'],
            'type'=>['required','string','max:32'],'ownership_type'=>['sometimes','in:owned,external'],'is_domestic'=>['boolean'],'is_active'=>['boolean'],'priority'=>['integer','min:0','max:9999'],
            'fulfillment_available_on'=>['nullable','date'],
            'replenishment_lead_time_days'=>['nullable','integer','min:0','max:365'],'replenishment_safety_stock_days'=>['nullable','integer','min:0','max:365'],'replenishment_target_cover_days'=>['nullable','integer','min:0','max:365'],
        ];
    }
    private function fulfillmentState($w): string
    {
        if ($w->fulfillment_available_on !== null && $w->fulfillment_available_on->isFuture()) return 'preparing';
        $hasFullSnapshot = InventorySyncBatch::query()
            ->where('warehouse_id', $w->id)->where('status', 'confirmed')->where('sync_mode', 'full')->exists();
        return $hasFullSnapshot ? 'ready' : 'awaiting_inventory';
    }

    private function fulfillmentStateLabel($w): string
    {
        return match ($this->fulfillmentState($w)) {
            'preparing' => '备货中',
            'awaiting_inventory' => '待库存确认',
            default => '可履约',
        };
    }

    private function inventoryRules(bool $withProduct=true): array
    {
        $rules=[
            'quantity_on_hand'=>['required','integer','min:0'],'quantity_reserved'=>['required','integer','min:0'],
            'inbound_at'=>['nullable','date'],'location'=>['nullable','string','max:128'],'standard_qoh'=>['nullable','integer','min:0'],
            'expected_restock_qty'=>['nullable','integer','min:0'],
        ];
        if($withProduct)$rules=['product_id'=>['required','integer','exists:products,id'],...$rules];
        return $rules;
    }
    private function payload($w): array
    {
        $code = strtoupper((string) $w->code);
        $canonical = (array) config("warehouse_master.warehouses.{$code}", []);
        $fulfillmentCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $inventoryOnlyCodes = array_map('strtoupper', (array) config('warehouse_master.inventory_only_codes', []));

        $lastConfirmedSync = InventorySyncBatch::query()
            ->where('warehouse_id', $w->id)
            ->where('status', 'confirmed')
            ->orderByDesc('confirmed_at')
            ->first();

        return [
            'id'=>$w->id,'slug'=>$w->slug,'public_slug'=>$this->inventory->warehousePublicSlug($w),
            'code'=>$w->code,'name'=>$w->name,'name_en'=>$canonical['name_en'] ?? null,'country_code'=>$w->country_code,
            'role_label'=>$canonical['role_label'] ?? '仓库','bill_import'=>$this->billImportCapability($w),'bill_import_onboarding'=>$this->billImportOnboarding($w),
            'is_fulfillment_warehouse'=>in_array($code, $fulfillmentCodes, true),
            'is_inventory_only_warehouse'=>in_array($code, $inventoryOnlyCodes, true),
            'address_line1'=>$w->address_line1,'address_line2'=>$w->address_line2,'city'=>$w->city,'state_region'=>$w->state_region,'postal_code'=>$w->postal_code,
            'timezone'=>$w->timezone,'type'=>$w->type,'ownership_type'=>$w->ownership_type,'is_domestic'=>$w->is_domestic,'is_active'=>$w->is_active,'priority'=>$w->priority,
            'fulfillment_available_on'=>$w->fulfillment_available_on?->format('Y-m-d'),
            'fulfillment_state'=>$this->fulfillmentState($w),
            'fulfillment_state_label'=>$this->fulfillmentStateLabel($w),
            'replenishment_lead_time_days'=>$w->replenishment_lead_time_days,'replenishment_safety_stock_days'=>$w->replenishment_safety_stock_days,'replenishment_target_cover_days'=>$w->replenishment_target_cover_days,'billing_markdown'=>$w->billing_markdown,
            'service_regions'=>$w->serviceRegions->pluck('country_code')->all(),
            'last_inventory_sync_at'=>($lastConfirmedSync?->stock_count_at ?? $lastConfirmedSync?->confirmed_at)?->toIso8601String(),
            'inventories'=>$w->inventories->map(fn($i)=>[
                'id'=>$i->id,'product'=>['id'=>$i->product->id,'sku'=>$i->product->sku,'name'=>$i->product->name],
                'quantity_on_hand'=>$i->quantity_on_hand,'quantity_reserved'=>$i->quantity_reserved,'quantity_available'=>$i->quantity_available,
                'stock_status'=>InventoryStockStatus::classify((int)$i->quantity_available),'stock_status_label'=>InventoryStockStatus::label(InventoryStockStatus::classify((int)$i->quantity_available)),
                'inbound_at'=>$i->inbound_at?->format('Y-m-d'),'stay_days'=>$i->stay_days,'location'=>$i->location,'standard_qoh'=>$i->standard_qoh,'expected_restock_qty'=>$i->expected_restock_qty,
                'replenishment'=>$this->inventory->replenishmentMetrics($i),
            ])->values()->all(),
        ];
    }
}
