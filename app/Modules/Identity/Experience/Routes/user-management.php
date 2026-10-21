<?php

use App\Modules\Identity\Experience\Controllers\AccountController;
use App\Modules\Identity\Experience\Controllers\RolePermissionsController;
use App\Modules\Identity\Experience\Controllers\UserController;
use Illuminate\Support\Facades\Route;


Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::match(['put', 'patch'], '/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::get('/account/password', [AccountController::class, 'password'])->name('account.password');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
});

Route::middleware(['web', 'auth', 'can:system.permissions.manage'])->group(function (): void {
    Route::get('/settings/users', [UserController::class, 'index'])->name('user.index');
    Route::get('/settings/users/create', [UserController::class, 'create'])->name('user.create');
    Route::post('/settings/users', [UserController::class, 'store'])->name('user.store');
    Route::get('/settings/users/{user}/edit', [UserController::class, 'edit'])->name('user.edit');
    Route::match(['put', 'patch'], '/settings/users/{user}', [UserController::class, 'update'])->name('user.update');
    Route::post('/settings/users/{user}/password', [UserController::class, 'setPassword'])->name('user.password');
    Route::post('/settings/users/{user}/role', [UserController::class, 'setRole'])->name('user.role');
    Route::post('/settings/users/{user}/permissions', [UserController::class, 'updatePermissions'])->name('user.permissions');
    Route::post('/settings/users/{user}/enable', [UserController::class, 'enable'])->name('user.enable');
    Route::post('/settings/users/{user}/disable', [UserController::class, 'disable'])->name('user.disable');
    Route::delete('/settings/users/{user}', [UserController::class, 'destroy'])->name('user.destroy');

    Route::get('/settings/roles-permissions', [RolePermissionsController::class, 'index'])->name('settings.roles-permissions');
    Route::put('/settings/roles-permissions/{preset}', [RolePermissionsController::class, 'update'])->name('settings.roles-permissions.update');

    // Temporary GET-only compatibility redirects. Legacy mutation routes do not exist.
    Route::get('/user', fn () => redirect('/settings/users'));
    Route::get('/user/create', fn () => redirect('/settings/users/create'));
    Route::get('/user/{user}/edit', fn (string $user) => redirect("/settings/users/{$user}/edit"));
});
