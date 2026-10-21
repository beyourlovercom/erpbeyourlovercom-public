<?php

namespace App\Modules\Search\Experience\Controllers;

use App\Modules\Search\Application\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GlobalSearchController
{
    public function __construct(private readonly GlobalSearchService $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:128'],
        ]);

        return response()->json(
            $this->search->search($request->user(), (string) ($data['q'] ?? ''))
        );
    }
}
