<?php

use App\Modules\Marketing\Experience\Controllers\MarketingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('/sync/wpapi/subscribers', [MarketingController::class, 'syncWordPress'])->middleware(['can:sync.operate', 'can:marketing.operate'])->name('sync.wpapi.subscribers');
});

Route::middleware(['web', 'auth', 'can:marketing.view'])->group(function (): void {
    Route::get('/marketing', [MarketingController::class, 'overview'])->name('shell.marketing');
    Route::get('/marketing/subscribers', [MarketingController::class, 'subscribers'])->name('marketing.subscribers');
    Route::get('/marketing/newsletters', fn () => app(MarketingController::class)->planned('newsletters'))->name('marketing.newsletters');
    Route::get('/marketing/campaigns', fn () => app(MarketingController::class)->planned('campaigns'))->name('marketing.campaigns');
    Route::get('/marketing/automations', fn () => app(MarketingController::class)->planned('automations'))->name('marketing.automations');
    Route::get('/marketing/promotions', fn () => app(MarketingController::class)->planned('promotions'))->name('marketing.promotions');
});
