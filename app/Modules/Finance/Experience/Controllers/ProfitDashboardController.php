<?php

namespace App\Modules\Finance\Experience\Controllers;

use App\Modules\Finance\Application\ProfitDashboardService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProfitDashboardController
{
    public function __construct(private readonly ProfitDashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        $filters = $this->dashboard->filters($request);
        $orders = Order::query()->with(['address', 'financials', 'costEntries', 'items'])
            ->where('order_kind', 'customer')
            ->whereDoesntHave('externalReferences', fn (Builder $references) => $references->where('provider', 'historical_logistics'))
            ->whereDate('order_date', '>=', $filters['date_from'])
            ->whereDate('order_date', '<=', $filters['date_to'])
            ->when($filters['country'], fn (Builder $query, string $country) => $query->whereHas('address', fn (Builder $address) => $address->where('country', $country)))
            ->when($filters['source_type'], fn (Builder $query, string $source) => $query->where('source_type', $source))
            ->when($filters['product'], function (Builder $query, string $product): void {
                $query->whereHas('items', fn (Builder $items) => $items
                    ->where('sku', 'like', '%'.$product.'%')
                    ->orWhere('product_summary', 'like', '%'.$product.'%'));
            })
            ->get();

        return Inertia::render('Finance/ProfitDashboard', $this->dashboard->build($request, $orders));
    }
}
