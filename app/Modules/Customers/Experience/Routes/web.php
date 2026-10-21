<?php

use App\Modules\Customers\Experience\Controllers\CompanyController;
use App\Modules\Customers\Experience\Controllers\CustomerController;
use App\Modules\Customers\Experience\Controllers\CustomerDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/customers', CustomerDashboardController::class)->middleware('can:customers.view')->name('shell.customers');

    Route::get('/customers/library', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.library');
    Route::get('/customers/groups', [CustomerController::class, 'groups'])->middleware('can:customers.view')->name('customers.groups');
    Route::post('/customers/groups', [CustomerController::class, 'storeGroup'])->middleware('can:customers.operate')->name('customers.groups.store');
    Route::patch('/customers/groups/{customerGroup}', [CustomerController::class, 'updateGroup'])->middleware('can:customers.operate')->name('customers.groups.update');
    Route::delete('/customers/groups/{customerGroup}', [CustomerController::class, 'destroyGroup'])->middleware('can:customers.operate')->name('customers.groups.destroy');
    Route::get('/customers/groups/{customerGroup}/customers/search', [CustomerController::class, 'searchGroupCustomers'])->middleware('can:customers.view')->name('customers.groups.customers.search');
    Route::post('/customers/groups/{customerGroup}/members', [CustomerController::class, 'addGroupMembers'])->middleware('can:customers.operate')->name('customers.groups.members.store');
    Route::delete('/customers/groups/{customerGroup}/members/{customer}', [CustomerController::class, 'removeGroupMember'])->middleware('can:customers.operate')->name('customers.groups.members.destroy');
    Route::get('/customers/library/create', [CustomerController::class, 'create'])->middleware('can:customers.operate')->name('customers.library.create');
    Route::post('/customers/library', [CustomerController::class, 'store'])->middleware('can:customers.operate')->name('customers.library.store');
    Route::get('/customers/library/export', [CustomerController::class, 'reservedExport'])->middleware(['can:customers.export', 'can:system.permissions.manage'])->name('customers.library.export');
    Route::post('/customers/library/import', [CustomerController::class, 'reservedImport'])->middleware(['can:customers.import', 'can:system.permissions.manage'])->name('customers.library.import');
    Route::get('/customers/import', [CustomerController::class, 'importWorkspace'])->middleware(['can:customers.import', 'can:system.permissions.manage'])->name('customers.import');
    Route::get('/sync/customers/import', [CustomerController::class, 'importWorkspace'])->middleware(['can:customers.import', 'can:system.permissions.manage'])->name('sync.customers.import');
    Route::get('/customers/import/template', [CustomerController::class, 'importTemplate'])->middleware(['can:customers.import', 'can:system.permissions.manage'])->name('customers.import.template');
    Route::post('/customers/import/preview', [CustomerController::class, 'previewImport'])->middleware(['can:customers.import', 'can:system.permissions.manage'])->name('customers.import.preview');
    Route::post('/customers/import/{batch}/confirm', [CustomerController::class, 'confirmImport'])->middleware(['can:customers.import', 'can:system.permissions.manage'])->name('customers.import.confirm');
    Route::get('/customers/library/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view')->name('customers.library.show');
    Route::get('/customers/library/{customer}/edit', [CustomerController::class, 'edit'])->middleware('can:customers.operate')->name('customers.library.edit');
    Route::match(['put', 'patch'], '/customers/library/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.operate')->name('customers.library.update');
    Route::post('/customers/library/{customer}/archive', [CustomerController::class, 'archive'])->middleware('can:customers.operate')->name('customers.library.archive');
    Route::post('/customers/library/{customer}/restore', [CustomerController::class, 'restore'])->middleware('can:customers.operate')->name('customers.library.restore');

    Route::get('/customers/companies', [CompanyController::class, 'index'])->middleware('can:customers.view')->name('customers.companies');
    Route::get('/customers/companies/create', [CompanyController::class, 'create'])->middleware('can:customers.operate')->name('customers.companies.create');
    Route::post('/customers/companies', [CompanyController::class, 'store'])->middleware('can:customers.operate')->name('customers.companies.store');
    Route::get('/customers/companies/{company}', [CompanyController::class, 'show'])->middleware('can:customers.view')->name('customers.companies.show');
    Route::get('/customers/companies/{company}/edit', [CompanyController::class, 'edit'])->middleware('can:customers.operate')->name('customers.companies.edit');
    Route::match(['put', 'patch'], '/customers/companies/{company}', [CompanyController::class, 'update'])->middleware('can:customers.operate')->name('customers.companies.update');
    Route::post('/customers/companies/{company}/archive', [CompanyController::class, 'archive'])->middleware('can:customers.operate')->name('customers.companies.archive');
    Route::post('/customers/companies/{company}/restore', [CompanyController::class, 'restore'])->middleware('can:customers.operate')->name('customers.companies.restore');
});
