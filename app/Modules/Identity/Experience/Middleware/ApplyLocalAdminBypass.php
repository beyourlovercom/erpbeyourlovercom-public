<?php

namespace App\Modules\Identity\Experience\Middleware;

use App\Modules\Identity\Infrastructure\Models\User;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApplyLocalAdminBypass
{
    public const REQUEST_MARKER = 'erp.local_admin_bypass.active';

    private const ALLOWED_HOSTS = ['erp.b', 'localhost', '127.0.0.1', '::1'];

    private const LOOPBACK_ADDRESSES = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('erp_local.admin_bypass.enabled') !== true) {
            return $next($request);
        }

        if (! app()->environment('local')) {
            return $this->unavailable();
        }

        $host = strtolower(trim($request->getHost(), '[]'));
        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            return $this->unavailable();
        }

        $remoteAddress = $request->server('REMOTE_ADDR');
        if (! is_string($remoteAddress) || ! in_array($remoteAddress, self::LOOPBACK_ADDRESSES, true)) {
            return $this->unavailable();
        }

        /** @var StatefulGuard $guard */
        $guard = Auth::guard('web');
        if ($request->session()->has($guard->getName()) || $request->cookies->has($guard->getRecallerName())) {
            return $this->unavailable();
        }

        $email = filter_var(trim((string) config('erp_local.admin_bypass.email')), FILTER_VALIDATE_EMAIL);
        if (! is_string($email)) {
            return $this->unavailable();
        }

        $user = User::query()->where('email', strtolower($email))->first();
        if (! $user?->is_active) {
            return $this->unavailable();
        }

        try {
            $guard->setUser($user);
            $request->attributes->set(self::REQUEST_MARKER, true);

            if ($request->isMethod('POST') && $request->routeIs('logout')) {
                return redirect()->route('dashboard');
            }

            return $next($request);
        } finally {
            $guard->forgetUser();
            $request->attributes->remove(self::REQUEST_MARKER);
        }
    }

    private function unavailable(): Response
    {
        return response('Local administrator bypass is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
