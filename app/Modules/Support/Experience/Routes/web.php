<?php

use App\Modules\Support\Experience\Controllers\CustomerSupportTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('/customers/library/{customer}/support-tickets')->name('customers.support-tickets.')->group(function (): void {
    Route::get('/create', [CustomerSupportTicketController::class, 'create'])->middleware('can:customers.operate')->name('create');
    Route::post('/', [CustomerSupportTicketController::class, 'store'])->middleware('can:customers.operate')->name('store');
    Route::get('/{ticket}', [CustomerSupportTicketController::class, 'show'])->middleware('can:customers.view')->name('show');
    Route::get('/{ticket}/edit', [CustomerSupportTicketController::class, 'edit'])->middleware('can:customers.operate')->name('edit');
    Route::patch('/{ticket}', [CustomerSupportTicketController::class, 'update'])->middleware('can:customers.operate')->name('update');
});
