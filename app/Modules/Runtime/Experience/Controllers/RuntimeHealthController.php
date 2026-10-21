<?php

namespace App\Modules\Runtime\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Runtime\Application\RuntimeHealthService;
use Illuminate\Http\JsonResponse;

class RuntimeHealthController extends Controller
{
    public function __invoke(RuntimeHealthService $health): JsonResponse
    {
        $result = $health->check();

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503);
    }
}
