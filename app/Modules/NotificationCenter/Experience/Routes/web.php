<?php

use App\Modules\NotificationCenter\Experience\Controllers\NotificationCenterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth'])->group(function (): void {
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{notification}', [NotificationCenterController::class, 'show'])->name('notifications.show');
    Route::post('/notifications/{notification}/open', [NotificationCenterController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/{notification}/read', [NotificationCenterController::class, 'read'])->name('notifications.read');
    Route::delete('/notifications/{notification}', [NotificationCenterController::class, 'destroy'])->name('notifications.destroy');
    Route::post('/notifications/read-all', [NotificationCenterController::class, 'readAll'])->name('notifications.read-all');
});
