<?php

use App\Modules\Identity\Experience\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/', [AuthController::class, 'root'])->name('root');
    Route::get('/login', [AuthController::class, 'login'])->middleware('guest')->name('login');
});
