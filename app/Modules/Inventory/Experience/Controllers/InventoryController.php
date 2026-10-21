<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Application\InventoryStockStatus;
use App\Modules\Identity\Application\AuthorizationKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class InventoryController
{
    public function __construct(private readonly InventoryService $inventory) {}
    public function dashboard(): Response { return Inertia::render('Inventory/Dashboard', ['summary'=>$this->inventory->dashboard(),'canManage'=>auth()->user()?->can(AuthorizationKeys::InventoryWarehousesManage->value) ?? false]); }
    public function warehouses(): Response { return Inertia::render('Inventory/WarehouseList', ['warehouses'=>$this->inventory->warehouses(),'canManage'=>auth()->user()?->can(AuthorizationKeys::InventoryWarehousesManage->value) ?? false]); }
    public function products(Request $request): Response|RedirectResponse
    {
        $page=$this->inventory->productInventory($request->only(['search','warehouse','stock_status']));
        $warehouseFilter=trim((string)$request->input('warehouse',''));
        $canonicalWarehouse=$warehouseFilter===''?'':($this->inventory->canonicalWarehouseSlug($warehouseFilter) ?? $warehouseFilter);
        if($warehouseFilter!=='' && $canonicalWarehouse!==$warehouseFilter){
            $query=array_filter(['search'=>$request->input('search'),'warehouse'=>$canonicalWarehouse],fn($value):bool=>$value!==null&&$value!=='');
            return redirect()->route('inventory.products',$query);
        }
        return Inertia::render('Inventory/Products', [
            'rows'=>$page->through(fn ($i) => [
                'id'=>$i->id,'warehouse'=>['slug'=>$i->warehouse->slug,'public_slug'=>$this->inventory->warehousePublicSlug($i->warehouse),'name'=>$i->warehouse->name],
                'product'=>['id'=>$i->product->id,'sku'=>$i->product->sku,'name'=>$i->product->name],
                'inbound_at'=>$i->inbound_at?->format('Y-m-d'),'stay_days'=>$i->stay_days,'location'=>$i->location,
                'quantity_on_hand'=>$i->quantity_on_hand,'quantity_reserved'=>$i->quantity_reserved,'quantity_available'=>$i->quantity_available,
                'stock_status'=>InventoryStockStatus::classify((int)$i->quantity_available),'stock_status_label'=>InventoryStockStatus::label(InventoryStockStatus::classify((int)$i->quantity_available)),
                'standard_qoh'=>$i->standard_qoh,'expected_restock_qty'=>$i->expected_restock_qty,
                'replenishment'=>$this->inventory->replenishmentMetrics($i),
            ])->toArray(),
            'warehouses'=>$this->inventory->warehouses(),'filters'=>['search'=>$request->input('search'),'warehouse'=>$canonicalWarehouse,'stock_status'=>$request->input('stock_status')],
        ]);
    }
    public function productSearch(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('inventory.view') || $request->user()?->can('inventory.packaging.submit'), 403);
        $q=trim((string)$request->input('q',''));
        return response()->json($q==='' ? [] : $this->inventory->searchProducts($q));
    }
}
