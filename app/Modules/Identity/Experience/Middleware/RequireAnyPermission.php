<?php

namespace App\Modules\Identity\Experience\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class RequireAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless($request->user() && collect($permissions)->contains(fn (string $permission): bool => Gate::forUser($request->user())->allows($permission)), 403);

        return $next($request);
    }
}
