<?php

use App\Modules\Finance\Experience\Controllers\FxSettingsController;
use App\Modules\Finance\Experience\Controllers\OrderAnalysisController;
use App\Modules\Finance\Experience\Controllers\ProfitDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/settings/fx', [FxSettingsController::class, 'index'])->middleware('can:system.permissions.manage')->name('settings.fx');
    Route::put('/settings/fx', [FxSettingsController::class, 'update'])->middleware('can:system.permissions.manage')->name('settings.fx.update');
    Route::get('/accounting', [ProfitDashboardController::class, 'index'])->middleware('can:accounting.view')->name('accounting.index');
    Route::get('/accounting/order-costs', [OrderAnalysisController::class, 'index'])->middleware('can:analysis.view')->name('accounting.order-costs');
    Route::get('/accounting/order-costs/export', [OrderAnalysisController::class, 'export'])->middleware('can:analysis.view')->name('analysis.export');
    Route::get('/accounting/order-costs/{order}', [OrderAnalysisController::class, 'show'])->middleware('can:analysis.view')->name('analysis.show');
    Route::put('/accounting/order-costs/{order}', [OrderAnalysisController::class, 'update'])->middleware('can:analysis.view')->name('analysis.update');
});
