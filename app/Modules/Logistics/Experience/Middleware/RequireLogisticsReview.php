<?php

namespace App\Modules\Logistics\Experience\Middleware;

use App\Modules\Logistics\Application\LogisticsReviewAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireLogisticsReview
{
    public function __construct(private readonly LogisticsReviewAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->access->allows($request->user()), 403);

        return $next($request);
    }
}
