<?php

namespace App\Modules\Identity\Experience\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function login(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function root(Request $request): Response|RedirectResponse
    {
        return $request->user() ? redirect()->route('dashboard') : Inertia::render('Auth/Login');
    }
}
