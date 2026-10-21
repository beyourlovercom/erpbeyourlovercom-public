<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Kol\Infrastructure\Models\SavedView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OrderSavedViewController
{
    public function __construct(private readonly SavedViewService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(requireName: true));
        $view = $this->service->create('orders', [
            ...$data,
            'visibility' => 'private',
            'filters_json' => $this->filters($data['filters_json'] ?? []),
        ], $request->user());

        return back()->with('success', '订单视图已保存');
    }

    public function update(Request $request, SavedView $savedView): RedirectResponse
    {
        $this->authorizeView($request, $savedView);
        $data = $request->validate($this->rules(requireName: false));
        if (array_key_exists('filters_json', $data)) {
            $data['filters_json'] = $this->filters($data['filters_json'] ?? []);
        }
        $data['visibility'] = 'private';
        $view = $this->service->update($savedView, $data, $request->user());

        return back()->with('success', '订单视图已更新');
    }

    public function destroy(Request $request, SavedView $savedView): RedirectResponse
    {
        $this->authorizeView($request, $savedView);
        $this->service->delete($savedView, $request->user());

        return back()->with('success', '订单视图已删除');
    }

    private function authorizeView(Request $request, SavedView $view): void
    {
        abort_unless($view->module === 'orders', 404);
        abort_unless($view->created_by === (int) $request->user()->getAuthIdentifier(), 403);
    }

    private function rules(bool $requireName): array
    {
        return [
            'name' => [$requireName ? 'required' : 'sometimes', 'string', 'max:100'],
            'filters_json' => ['sometimes', 'array'],
            'filters_json.search' => ['nullable', 'string', 'max:255'],
            'filters_json.source_type' => ['nullable', 'string', 'max:32'],
            'filters_json.order_status' => ['nullable', 'string', 'in:pending_shipment,shipped,completed,completed_delivered,cancelled'],
            'filters_json.view' => ['nullable', 'string', 'in:all,pending,exception,in_transit,shipped,return,loss,overweight,stockout'],
            'is_pinned' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function filters(array $filters): array
    {
        return array_filter([
            'search' => $filters['search'] ?? '',
            'source_type' => $filters['source_type'] ?? '',
            'order_status' => $filters['order_status'] ?? '',
            'view' => $filters['view'] ?? 'all',
        ], fn ($value): bool => $value !== '' && $value !== null);
    }
}
