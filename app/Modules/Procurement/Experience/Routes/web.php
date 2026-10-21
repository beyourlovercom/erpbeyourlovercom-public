<?php

use App\Modules\Procurement\Experience\Controllers\ProcurementOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/purchase', [ProcurementOrderController::class, 'index'])->middleware('can:procurement.view')->name('procurement.index');
    Route::get('/purchase/orders', [ProcurementOrderController::class, 'index'])->middleware('can:procurement.view')->name('procurement.orders.index');
    Route::get('/purchase/orders/from-order/{order}/create', [ProcurementOrderController::class, 'create'])->middleware(['can:orders.view', 'can:procurement.create'])->where('order', '[0-9]+')->name('procurement.orders.create');
    Route::post('/purchase/orders/from-order/{order}', [ProcurementOrderController::class, 'store'])->middleware(['can:orders.view', 'can:procurement.create'])->where('order', '[0-9]+')->name('procurement.orders.store');
    Route::get('/purchase/orders/{procurementOrder}', [ProcurementOrderController::class, 'show'])->middleware('can:procurement.view')->whereNumber('procurementOrder')->name('procurement.orders.show');
    Route::patch('/purchase/orders/{procurementOrder}', [ProcurementOrderController::class, 'update'])->middleware('can:procurement.operate')->whereNumber('procurementOrder')->name('procurement.orders.update');

    Route::redirect('/procurement', '/purchase')->middleware('can:procurement.view')->name('procurement.legacy.index');
    Route::redirect('/procurement-orders', '/purchase/orders')->middleware('can:procurement.view')->name('procurement.legacy.orders.index');
    Route::get('/procurement-orders/{procurementOrder}', [ProcurementOrderController::class, 'legacyShow'])->middleware('can:procurement.view')->whereNumber('procurementOrder')->name('procurement.legacy.orders.show');
    Route::get('/orders/{order}/procurement/create', [ProcurementOrderController::class, 'legacyCreate'])->middleware(['can:orders.view', 'can:procurement.create'])->where('order', '[0-9]+')->name('procurement.legacy.orders.create');
    Route::post('/orders/{order}/procurement', [ProcurementOrderController::class, 'store'])->middleware(['can:orders.view', 'can:procurement.create'])->where('order', '[0-9]+')->name('procurement.legacy.orders.store');
});
