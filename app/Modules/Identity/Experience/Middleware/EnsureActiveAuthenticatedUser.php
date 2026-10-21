<?php

namespace App\Modules\Identity\Experience\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Modules\Identity\Infrastructure\Models\User;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveAuthenticatedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->id() ? User::query()->find($guard->id()) : null;

        if ($user && ! $user->is_active) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
