<?php

use App\Modules\Backup\Experience\Controllers\BackupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:backup.view'])->group(function (): void {
    Route::get('/settings/backup', [BackupController::class, 'index'])->name('backup.index');
    Route::get('/setting/backup', fn () => redirect()->route('backup.index'))->name('backup.legacy');
    Route::put('/settings/backup/automatic', [BackupController::class, 'updateAutomaticBackup'])->middleware('can:backup.manage')->name('backup.automatic.update');
});
