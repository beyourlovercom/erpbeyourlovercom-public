<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Kol\Infrastructure\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SavedViewController
{
    public function __construct(private readonly SavedViewService $service) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'visibility' => 'required|string|in:private,shared',
            'filters_json' => 'nullable|array',
            'sort_json' => 'nullable|array',
            'is_pinned' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $view = $this->service->create('kol', $data, $request->user());

        return response()->json(['id' => $view->id, 'name' => $view->name], 201);
    }

    public function update(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorizeView($request, $savedView);

        $data = $request->validate([
            'name' => 'nullable|string|max:100',
            'visibility' => 'nullable|string|in:private,shared',
            'filters_json' => 'nullable|array',
            'sort_json' => 'nullable|array',
            'is_pinned' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $view = $this->service->update($savedView, $data, $request->user());

        return response()->json(['id' => $view->id, 'name' => $view->name]);
    }

    public function destroy(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorizeView($request, $savedView);
        $this->service->delete($savedView, $request->user());

        return response()->json(null, 204);
    }

    private function authorizeView(Request $request, SavedView $view): void
    {
        abort_unless($view->module === 'kol', 404);
        $userId = (int) $request->user()->getAuthIdentifier();
        if ($view->created_by !== $userId) {
            abort(403);
        }
    }
}
