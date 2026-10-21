<?php

use App\Modules\Dashboard\Experience\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:dashboard.view'])->get('/dashboard', DashboardController::class)->name('dashboard');
