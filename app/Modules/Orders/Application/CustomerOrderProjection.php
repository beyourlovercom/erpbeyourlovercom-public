<?php

namespace App\Modules\Orders\Application;

use Illuminate\Support\Facades\DB;

final class CustomerOrderProjection
{
    /** @return array{summary: object|null, revenue_by_currency: list<array<string, mixed>>, recent_orders: list<array<string, mixed>>} */
    public function forCustomer(int $customerId): array
    {
        $base = fn () => DB::table('orders')
            ->where('customer_id', $customerId)
            ->where('order_kind', OrderKind::Customer->value);

        $summary = $base()
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('MIN(orders.order_date) as first_order_date')
            ->selectRaw('MAX(orders.order_date) as last_order_date')
            ->first();

        $revenue = $base()
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->selectRaw("COALESCE(orders.currency, 'USD') as currency")
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('COALESCE(SUM(order_items.line_revenue), 0) as revenue')
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn (object $row): array => [
                'currency' => $row->currency,
                'revenue' => (float) $row->revenue,
                'average_order_value' => (int) $row->order_count > 0 ? round((float) $row->revenue / (int) $row->order_count, 2) : 0,
                'order_count' => (int) $row->order_count,
            ])->values()->all();

        $recent = $base()
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->select('orders.id', 'orders.erp_order_id', 'orders.external_order_number', 'orders.order_date', 'orders.order_status', 'orders.currency')
            ->selectRaw('COALESCE(SUM(order_items.line_revenue), 0) as revenue')
            ->groupBy('orders.id', 'orders.erp_order_id', 'orders.external_order_number', 'orders.order_date', 'orders.order_status', 'orders.currency')
            ->latest('orders.order_date')->latest('orders.id')->limit(20)->get()
            ->map(fn (object $order): array => [
                'id' => (int) $order->id,
                'erp_order_id' => $order->erp_order_id,
                'external_order_number' => $order->external_order_number,
                'number' => $order->erp_order_id,
                'order_date' => $order->order_date ? substr((string) $order->order_date, 0, 10) : null,
                'status' => (string) $order->order_status,
                'revenue' => (float) $order->revenue,
                'currency' => $order->currency ?: 'USD',
            ])->values()->all();

        return [
            'summary' => $summary,
            'revenue_by_currency' => $revenue,
            'recent_orders' => $recent,
        ];
    }
}
