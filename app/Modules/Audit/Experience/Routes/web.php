<?php

use App\Modules\Audit\Experience\Controllers\AuditRecordController;
use Illuminate\Support\Facades\Route;

Route::get('/system/audit-records', AuditRecordController::class)
    ->middleware(['auth', 'can:audit.view'])
    ->name('system.audit-records.index');
