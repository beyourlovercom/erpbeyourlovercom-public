<?php

use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;

Route::get('/architecture/foundation', fn () => Inertia::render('Architecture/Foundation', [
    'title' => 'BYL ERP Foundation',
    'status' => 'ARCHITECTURE VALIDATION',
]))->middleware([HandleInertiaRequests::class, 'auth', 'can:architecture.foundation.view'])->name('architecture.foundation');
