<?php

namespace App\Modules\Orders\Application;

use Illuminate\Support\Facades\DB;

/** Read-only Product projection of canonical customer Order and Order Item truth. */
final class ProductOrderProjection
{
    /** @return array{summary:array<string,int>, revenue_by_currency:list<array<string,mixed>>, recent_orders:list<array<string,mixed>>} */
    public function forProduct(int $productId, int $recentLimit = 20): array
    {
        $base = fn () => DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $productId)
            ->where('orders.order_kind', OrderKind::Customer->value);

        $summary = $base()
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as lifetime_units')
            ->selectRaw('COUNT(DISTINCT orders.id) as lifetime_order_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN orders.order_date >= ? THEN order_items.quantity ELSE 0 END), 0) as units_30d", [now()->subDays(30)->toDateString()])
            ->selectRaw("COUNT(DISTINCT CASE WHEN orders.order_date >= ? THEN orders.id END) as order_count_30d", [now()->subDays(30)->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN orders.order_date >= ? THEN order_items.quantity ELSE 0 END), 0) as units_90d", [now()->subDays(90)->toDateString()])
            ->selectRaw("COUNT(DISTINCT CASE WHEN orders.order_date >= ? THEN orders.id END) as order_count_90d", [now()->subDays(90)->toDateString()])
            ->first();

        $revenue = $base()
            ->select('orders.currency')
            ->selectRaw('COALESCE(SUM(order_items.line_revenue), 0) as revenue')
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn (object $row): array => ['currency' => (string) $row->currency, 'revenue' => (float) $row->revenue])->values()->all();

        $recent = $base()
            ->select('orders.id', 'orders.erp_order_id', 'orders.external_order_number', 'orders.order_date', 'orders.order_status', 'orders.currency')
            ->selectRaw('SUM(order_items.quantity) as quantity')
            ->selectRaw('COALESCE(SUM(order_items.line_revenue), 0) as revenue')
            ->groupBy('orders.id', 'orders.erp_order_id', 'orders.external_order_number', 'orders.order_date', 'orders.order_status', 'orders.currency')
            ->orderByDesc('orders.order_date')->orderByDesc('orders.id')->limit($recentLimit)->get()
            ->map(fn (object $order): array => [
                'id' => (int) $order->id,
                'erp_order_id' => $order->erp_order_id,
                'external_order_number' => $order->external_order_number,
                'number' => $order->erp_order_id,
                'order_date' => $order->order_date ? substr((string) $order->order_date, 0, 10) : null,
                'status' => (string) $order->order_status,
                'quantity' => (int) $order->quantity,
                'revenue' => (float) $order->revenue,
                'currency' => (string) $order->currency,
            ])->values()->all();

        return [
            'summary' => [
                'units_30d' => (int) ($summary->units_30d ?? 0),
                'order_count_30d' => (int) ($summary->order_count_30d ?? 0),
                'units_90d' => (int) ($summary->units_90d ?? 0),
                'order_count_90d' => (int) ($summary->order_count_90d ?? 0),
                'lifetime_units' => (int) ($summary->lifetime_units ?? 0),
                'lifetime_order_count' => (int) ($summary->lifetime_order_count ?? 0),
            ],
            'revenue_by_currency' => $revenue,
            'recent_orders' => $recent,
        ];
    }
}
