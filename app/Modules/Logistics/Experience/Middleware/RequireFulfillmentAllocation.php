<?php

namespace App\Modules\Logistics\Experience\Middleware;

use App\Modules\Logistics\Application\FulfillmentAllocationAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireFulfillmentAllocation
{
    public function __construct(private readonly FulfillmentAllocationAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->access->allows($request->user()), 403);

        return $next($request);
    }
}
