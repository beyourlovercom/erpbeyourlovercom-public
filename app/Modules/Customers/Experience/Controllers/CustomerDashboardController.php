<?php

namespace App\Modules\Customers\Experience\Controllers;

use App\Modules\Customers\Application\CustomerSubscriberProjection;
use App\Modules\Support\Application\CustomerSupportProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerDashboardController
{
    public function __construct(private readonly CustomerSubscriberProjection $subscriberProjection, private readonly CustomerSupportProjection $supportProjection) {}

    public function __invoke(Request $request): Response
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();

        $totalCustomers = DB::table('customers')->whereNull('archived_at')->count();

        $newThisMonth = DB::table('customers')
            ->whereNull('archived_at')
            ->whereDate('created_at', '>=', $monthStart)
            ->whereDate('created_at', '<=', $monthEnd)
            ->count();

        $ordersThisMonth = DB::table('orders')
            ->where('order_kind', 'customer')
            ->whereNotNull('customer_id')
            ->whereDate('order_date', '>=', $monthStart)
            ->whereDate('order_date', '<=', $monthEnd)
            ->count();

        $revenueThisMonth = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.order_kind', 'customer')
            ->whereNotNull('orders.customer_id')
            ->whereDate('orders.order_date', '>=', $monthStart)
            ->whereDate('orders.order_date', '<=', $monthEnd)
            ->selectRaw('COALESCE(orders.currency, \'USD\') as currency')
            ->selectRaw('COALESCE(SUM(order_items.line_revenue), 0) as revenue')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => $row->currency,
                'revenue' => (float) $row->revenue,
            ])->values()->all();

        return Inertia::render('Customers/Dashboard', [
            'metrics' => [
                'total_customers' => $totalCustomers,
                'new_this_month' => $newThisMonth,
                'orders_this_month' => $ordersThisMonth,
                'revenue_this_month' => $revenueThisMonth,
                'subscriber_identity_links' => $this->subscriberProjection->identityLinkCount(),
                'pending_support_tickets' => $this->supportProjection->pendingCount(),
            ],
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
            'canViewOrders' => (bool) $request->user()?->hasPermission('orders.view'),
            'canImport' => (bool) ($request->user()?->hasPermission('customers.import') && $request->user()?->hasPermission('system.permissions.manage')),
            'canExport' => (bool) ($request->user()?->hasPermission('customers.export') && $request->user()?->hasPermission('system.permissions.manage')),
        ]);
    }
}
