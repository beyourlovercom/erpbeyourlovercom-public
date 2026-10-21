<?php

namespace App\Modules\Inventory\Experience\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireBlueLabelWorkerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = trim((string) config('services.blue_label_worker.token', ''));
        if ($configured === '') {
            abort(503, 'Blue label worker authentication is not configured.');
        }

        $presented = trim((string) $request->bearerToken());
        if ($presented === '' || ! hash_equals($configured, $presented)) {
            abort(401, 'Invalid blue label worker token.');
        }

        $workerId = trim((string) $request->header('X-BYL-Worker-ID', ''));
        if ($workerId === '' || preg_match('/^[A-Za-z0-9._-]{3,128}$/D', $workerId) !== 1) {
            abort(422, 'Valid X-BYL-Worker-ID is required.');
        }

        $request->attributes->set('blue_label_worker_id', $workerId);

        return $next($request);
    }
}
