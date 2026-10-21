<?php

use App\Modules\Guides\Experience\Controllers\GuideController;
use App\Modules\Guides\Experience\Controllers\ReleaseNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:system.permissions.manage'])->get('/settings', [ReleaseNoteController::class, 'settings'])->name('shell.settings');
Route::middleware(['web', 'auth', 'can:system.permissions.manage'])->put('/settings/order-identity', [ReleaseNoteController::class, 'updateOrderIdentity'])->name('settings.order-identity.update');

Route::middleware(['web', 'auth', 'can:guides.manage'])->group(function (): void {
    Route::get('/guides/create', [GuideController::class, 'create'])->name('guides.create');
});

Route::middleware(['web', 'auth', 'can:guides.view'])->group(function (): void {
    Route::get('/guides', [GuideController::class, 'index'])->name('shell.guides');
    Route::get('/guides/videos', [GuideController::class, 'videos'])->name('guides.videos');
    Route::get('/guides/manuals', [GuideController::class, 'manuals'])->name('guides.manuals');
    Route::redirect('/guides/changelog', '/settings/changelog')->name('guides.releases.index');
    Route::get('/settings/changelog', [ReleaseNoteController::class, 'index'])->name('settings.changelog');
    Route::get('/guides/{guide}', [GuideController::class, 'show'])->name('guides.show');
});

Route::middleware(['web', 'auth', 'can:guides.manage'])->group(function (): void {
    Route::post('/guides', [GuideController::class, 'store'])->name('guides.store');
    Route::get('/guides/{guide}/edit', [GuideController::class, 'edit'])->name('guides.edit');
    Route::patch('/guides/{guide}', [GuideController::class, 'update'])->name('guides.update');
    Route::post('/guides/{guide}/publish', [GuideController::class, 'publish'])->name('guides.publish');
    Route::post('/guides/{guide}/unpublish', [GuideController::class, 'unpublish'])->name('guides.unpublish');
});
