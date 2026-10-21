<?php

use App\Modules\Orders\Experience\Controllers\KolLogisticsController;
use App\Modules\Orders\Experience\Controllers\OrderController;
use App\Modules\Orders\Experience\Controllers\OrderSavedViewController;
use App\Modules\Orders\Experience\Controllers\WooCommerceInboundController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/sync/woocommerce', [WooCommerceInboundController::class, 'index'])->middleware('can:sync.view')->name('woocommerce.inbound.index');
    Route::post('/sync/woocommerce/probe', [WooCommerceInboundController::class, 'probe'])->middleware('can:sync.operate')->name('woocommerce.inbound.probe');
    Route::post('/sync/woocommerce/single', [WooCommerceInboundController::class, 'single'])->middleware('can:sync.operate')->name('woocommerce.inbound.single');
    Route::post('/sync/woocommerce/incremental', [WooCommerceInboundController::class, 'incremental'])->middleware('can:sync.operate')->name('woocommerce.inbound.incremental');
    Route::post('/sync/woocommerce/historical', [WooCommerceInboundController::class, 'historical'])->middleware('can:sync.operate')->name('woocommerce.inbound.historical');
    Route::get('/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('orders.index')->defaults('order_kind', 'all');
    Route::get('/orders/export', [OrderController::class, 'export'])->middleware('can:orders.view')->name('orders.export.all')->defaults('order_kind', 'all');
    Route::post('/orders/views', [OrderSavedViewController::class, 'store'])->middleware('can:orders.view')->name('orders.views.store');
    Route::patch('/orders/views/{savedView}', [OrderSavedViewController::class, 'update'])->middleware('can:orders.view')->name('orders.views.update');
    Route::delete('/orders/views/{savedView}', [OrderSavedViewController::class, 'destroy'])->middleware('can:orders.view')->name('orders.views.destroy');
    Route::post('/orders/{order}/operational-markers/{marker}', [OrderController::class, 'setOperationalMarker'])->middleware('can:orders.edit')->where('order', '[0-9]+')->whereIn('marker', ['exception', 'in_transit', 'return', 'stockout'])->name('orders.operational-markers.update');
    Route::get('/orders/procurement', [OrderController::class, 'index'])->middleware('can:orders.view')->name('orders.procurement')->defaults('order_kind', 'all')->defaults('order_scope', 'external_warehouse');
    Route::get('/orders/{order}', [OrderController::class, 'showAny'])->middleware('can:orders.view')->whereNumber('order')->name('orders.show');
    Route::get('/orders/procurement/export', [OrderController::class, 'export'])->middleware('can:orders.view')->name('orders.export.procurement')->defaults('order_kind', 'all')->defaults('order_scope', 'external_warehouse');
    Route::get('/customers/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('customers.orders')->defaults('order_kind', 'customer');
    Route::get('/customers/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('customers.orders.create')->defaults('order_kind', 'customer');
    Route::post('/customers/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store.customers')->defaults('order_kind', 'customer');
    Route::get('/customers/orders/export', [OrderController::class, 'export'])->middleware(['can:customers.export', 'can:system.permissions.manage'])->name('orders.export')->defaults('order_kind', 'customer');
    Route::get('/customers/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|[0-9]+')->name('customers.orders.show')->defaults('order_kind', 'customer');
    Route::patch('/customers/orders/{order}', [OrderController::class, 'update'])->middleware('can:orders.edit')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|[0-9]+')->name('orders.update.customers')->defaults('order_kind', 'customer');

    Route::get('/kol/logistics', [KolLogisticsController::class, 'index'])->middleware('can:kol.view')->name('kol.logistics');
    Route::get('/kol/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('kol.orders')->defaults('order_kind', 'kol_fulfillment');
    Route::get('/kol/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('kol.orders.create')->defaults('order_kind', 'kol_fulfillment');
    Route::post('/kol/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store')->defaults('order_kind', 'kol_fulfillment');
    Route::get('/kol/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|KOL[0-9]{8}-[0-9]{3}|[0-9]+')->name('kol.orders.show')->defaults('order_kind', 'kol_fulfillment');
    Route::patch('/kol/orders/{order}', [OrderController::class, 'update'])->middleware('can:orders.edit')->name('orders.update')->defaults('order_kind', 'kol_fulfillment');
});
