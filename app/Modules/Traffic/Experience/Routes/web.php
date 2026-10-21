<?php

use App\Modules\Traffic\Experience\Controllers\BossDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:marketing.view'])
    ->get('/marketing/traffic', BossDashboardController::class)
    ->name('traffic.boss-dashboard');
