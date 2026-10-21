<?php

use App\Modules\Inventory\Experience\Controllers\InventoryController;
use App\Modules\Inventory\Experience\Controllers\InventorySyncController;
use App\Modules\Inventory\Experience\Controllers\WarehouseController;
use App\Modules\Inventory\Experience\Controllers\PackagingMeasurementController;
use App\Modules\Inventory\Experience\Controllers\BlueLabelWorkerController;
use App\Modules\Inventory\Experience\Middleware\RequireBlueLabelWorkerToken;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth'])->group(function (): void {
    Route::get('/inventory/packaging/media/{media}', [PackagingMeasurementController::class, 'media'])->whereNumber('media')->name('inventory.packaging.media');
    Route::get('/inventory/packaging', [PackagingMeasurementController::class, 'index'])->name('inventory.packaging.index');
    Route::get('/inventory/packaging/create', [PackagingMeasurementController::class, 'create'])->name('inventory.packaging.create');
    Route::get('/inventory/packaging/{record}/edit', [PackagingMeasurementController::class, 'edit'])->whereNumber('record')->name('inventory.packaging.edit');
    Route::get('/inventory/packaging/{record}', [PackagingMeasurementController::class, 'show'])->whereNumber('record')->name('inventory.packaging.show');
    Route::get('/inventory/product-search',[InventoryController::class,'productSearch'])->name('inventory.product-search');
    Route::get('/inventory/packaging/product/{product}/guide', [PackagingMeasurementController::class, 'productGuide'])->whereNumber('product')->name('inventory.packaging.product-guide');
});

Route::middleware(['web','auth','can:inventory.packaging.submit'])->group(function (): void {
    Route::post('/inventory/packaging', [PackagingMeasurementController::class, 'store'])->name('inventory.packaging.store');
    Route::patch('/inventory/packaging/{record}', [PackagingMeasurementController::class, 'update'])->whereNumber('record')->name('inventory.packaging.update');
    Route::post('/inventory/packaging/{record}/submit', [PackagingMeasurementController::class, 'submit'])->whereNumber('record')->name('inventory.packaging.submit');
    Route::post('/inventory/packaging/{record}/media', [PackagingMeasurementController::class, 'upload'])->whereNumber('record')->name('inventory.packaging.media.store');
    Route::delete('/inventory/packaging/media/{media}', [PackagingMeasurementController::class, 'removeMedia'])->whereNumber('media')->name('inventory.packaging.media.destroy');
});

Route::middleware(['web','auth','can:inventory.packaging.review'])->group(function (): void {
    Route::post('/inventory/packaging/{record}/review', [PackagingMeasurementController::class, 'review'])->whereNumber('record')->name('inventory.packaging.review');
    Route::post('/inventory/packaging/{record}/adopt-standard', [PackagingMeasurementController::class, 'adoptStandard'])->whereNumber('record')->name('inventory.packaging.adopt-standard');
});

Route::middleware(['web','auth','can:inventory.view'])->group(function (): void {
    Route::get('/inventory',[InventoryController::class,'dashboard'])->name('shell.inventory');
    Route::get('/inventory/list',[InventoryController::class,'warehouses'])->name('inventory.list');
    Route::get('/inventory/products',[InventoryController::class,'products'])->name('inventory.products');
    Route::get('/inventory/warehouses',fn()=>redirect()->route('inventory.list'))->name('inventory.warehouses');
    Route::get('/inventory/{warehouse}',[WarehouseController::class,'show'])->where('warehouse','^(?!add$|list$|products$|product-search$|warehouses$|sync$)[^/]+$')->name('inventory.warehouse.show');
});

Route::middleware(['web','auth','can:inventory.warehouses.manage'])->group(function (): void {
    Route::get('/inventory/add',[WarehouseController::class,'create'])->name('inventory.add');
    Route::post('/inventory',[WarehouseController::class,'store'])->name('inventory.store');
    Route::patch('/inventory/{warehouse}',[WarehouseController::class,'update'])->name('inventory.warehouse.update');
    Route::patch('/inventory/{warehouse}/billing',[WarehouseController::class,'billing'])->name('inventory.warehouse.billing');
    Route::patch('/inventory/{warehouse}/regions',[WarehouseController::class,'regions'])->name('inventory.warehouse.regions');
    Route::post('/inventory/{warehouse}/costs',[WarehouseController::class,'addCost'])->name('inventory.warehouse.costs.store');
    Route::delete('/inventory/{warehouse}/costs/{cost}',[WarehouseController::class,'removeCost'])->whereNumber('cost')->name('inventory.warehouse.costs.destroy');
    Route::post('/inventory/{warehouse}/costs/import/preview',[WarehouseController::class,'previewBillImport'])->name('inventory.warehouse.costs.import.preview');
    Route::post('/inventory/{warehouse}/costs/import/apply',[WarehouseController::class,'applyBillImport'])->name('inventory.warehouse.costs.import.apply');
    Route::post('/inventory/{warehouse}/costs/reconciliation/{batch}/preview',[WarehouseController::class,'previewBillReconciliation'])->whereNumber('batch')->name('inventory.warehouse.costs.reconciliation.preview');
    Route::post('/inventory/{warehouse}/costs/reconciliation/{batch}/apply',[WarehouseController::class,'applyBillReconciliation'])->whereNumber('batch')->name('inventory.warehouse.costs.reconciliation.apply');
});

Route::middleware(['web','auth','can:inventory.operate'])->group(function (): void {
    Route::post('/inventory/{warehouse}/stock',[WarehouseController::class,'saveInventory'])->name('inventory.stock.store');
    Route::patch('/inventory/{warehouse}/stock/{inventory}',[WarehouseController::class,'updateInventory'])->name('inventory.stock.update');
    Route::patch('/inventory/{warehouse}/inbound/{batch}/advance',[WarehouseController::class,'advanceInbound'])->whereNumber('batch')->name('inventory.inbound.advance');
    Route::patch('/inventory/{warehouse}/restock-supplement',[WarehouseController::class,'saveRestockSupplement'])->name('inventory.restock.supplement');
    Route::post('/inventory/{warehouse}/restock-plans/confirm',[WarehouseController::class,'confirmRestockPlan'])->name('inventory.restock.plan.confirm');
    Route::post('/inventory/{warehouse}/restock-plans/{plan}/blue-label-jobs',[WarehouseController::class,'requestRestockBlueLabels'])->whereNumber('plan')->name('inventory.restock.blue-label-job.store');
    Route::get('/inventory/{warehouse}/restock-plans/{plan}/blue-label-jobs/{job}/download/{artifact}',[WarehouseController::class,'downloadRestockDocument'])
        ->whereNumber('plan')->whereNumber('job')->whereIn('artifact',['pi','package'])
        ->name('inventory.restock.blue-label-job.download');
    Route::post('/inventory/{warehouse}/clearance',[WarehouseController::class,'addClearance'])->name('inventory.clearance.store');
    Route::patch('/inventory/{warehouse}/clearance/{clearance}',[WarehouseController::class,'updateClearance'])->whereNumber('clearance')->name('inventory.clearance.update');
    Route::delete('/inventory/{warehouse}/clearance/{clearance}',[WarehouseController::class,'removeClearance'])->whereNumber('clearance')->name('inventory.clearance.destroy');
    Route::get('/inventory/sync',[InventorySyncController::class,'index'])->name('inventory.sync');
    Route::post('/inventory/sync/preview',[InventorySyncController::class,'preview'])->name('inventory.sync.preview');
    Route::post('/inventory/sync/{batch}/confirm',[InventorySyncController::class,'confirm'])->whereNumber('batch')->name('inventory.sync.confirm');
    Route::get('/inventory/sync/history',[InventorySyncController::class,'history'])->name('inventory.sync.history');
    Route::get('/inventory/sync/{batch}',[InventorySyncController::class,'show'])->whereNumber('batch')->name('inventory.sync.show');
});

Route::prefix('/api/internal/blue-label-worker')
    ->middleware(RequireBlueLabelWorkerToken::class)
    ->group(function (): void {
        Route::post('/claim', [BlueLabelWorkerController::class, 'claim'])
            ->name('inventory.blue-label-worker.claim');
        Route::post('/jobs/{job}/start', [BlueLabelWorkerController::class, 'start'])
            ->name('inventory.blue-label-worker.start');
        Route::post('/jobs/{job}/heartbeat', [BlueLabelWorkerController::class, 'heartbeat'])
            ->name('inventory.blue-label-worker.heartbeat');
        Route::post('/jobs/{job}/artifacts/{kind}/{labelSku}', [BlueLabelWorkerController::class, 'artifact'])
            ->whereIn('kind', ['btw', 'pdf'])
            ->where('labelSku', '[A-Za-z0-9._-]+')
            ->name('inventory.blue-label-worker.artifact');
        Route::post('/jobs/{job}/complete', [BlueLabelWorkerController::class, 'complete'])
            ->name('inventory.blue-label-worker.complete');
        Route::post('/jobs/{job}/fail', [BlueLabelWorkerController::class, 'fail'])
            ->name('inventory.blue-label-worker.fail');
    });
