<?php

use App\Modules\Runtime\Experience\Controllers\RuntimeHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/system/runtime/health', RuntimeHealthController::class)
    ->middleware(['auth', 'can:system.permissions.manage'])
    ->name('system.runtime.health');
