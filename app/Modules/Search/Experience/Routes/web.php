<?php

use App\Modules\Search\Experience\Controllers\GlobalSearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->get('/search/global', GlobalSearchController::class)
    ->name('search.global');
