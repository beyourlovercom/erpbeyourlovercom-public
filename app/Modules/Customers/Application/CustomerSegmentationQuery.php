<?php

namespace App\Modules\Customers\Application;

use Carbon\Carbon;

final class CustomerSegmentationQuery
{
    private const ADVANCED_SEGMENTS = [
        'active_customer',
        'churn_risk',
        'dormant_customer',
        'high_frequency',
        'high_value',
        'vip',
    ];

    private const VALUE_THRESHOLDS = [
        'USD' => 500,
        'EUR' => 450,
        'GBP' => 400,
        'CAD' => 700,
        'AUD' => 750,
        'CNY' => 3500,
    ];

    public function supports(string $segment): bool
    {
        return in_array($segment, self::ADVANCED_SEGMENTS, true);
    }

    public function apply($query, string $segment): void
    {
        if ($segment === 'high_frequency') {
            $this->applyHighFrequency($query);
            return;
        }

        if ($segment === 'high_value') {
            $this->applyHighValue($query);
            return;
        }

        if ($segment === 'vip') {
            $query->where(function ($nested): void {
                $this->applyHighFrequency($nested);
                $this->applyHighValue($nested);
            });
            return;
        }

        $now = now();
        if ($segment === 'active_customer') {
            $this->applyLatestOrderWindow($query, $now->copy()->subDays(90), $now);
            return;
        }

        if ($segment === 'churn_risk') {
            $this->applyMinimumValidPurchases($query, 2);
            $this->applyLatestOrderWindow($query, $now->copy()->subDays(180), $now->copy()->subDays(91));
            return;
        }

        if ($segment === 'dormant_customer') {
            $this->applyMinimumValidPurchases($query, 1);
            $query->whereDoesntHave('orders', fn ($orders) => $this->validOrders($orders)->whereDate('order_date', '>=', $now->copy()->subDays(180)->toDateString()));
        }
    }

    private function applyMinimumValidPurchases($query, int $count): void
    {
        $query->whereHas('orders', fn ($orders) => $this->validOrders($orders), '>=', $count);
    }

    private function applyLatestOrderWindow($query, Carbon $from, Carbon $through): void
    {
        $this->applyMinimumValidPurchases($query, 1);
        $query->whereHas('orders', fn ($orders) => $this->validOrders($orders)
            ->whereDate('order_date', '>=', $from->toDateString())
            ->whereDate('order_date', '<=', $through->toDateString()));
        $query->whereDoesntHave('orders', fn ($orders) => $this->validOrders($orders)
            ->whereDate('order_date', '>', $through->toDateString()));
    }

    private function applyHighFrequency($query): void
    {
        $from = now()->subDays(365)->toDateString();
        $through = now()->toDateString();
        $query->whereHas('orders', fn ($orders) => $this->validOrders($orders)
            ->whereDate('order_date', '>=', $from)
            ->whereDate('order_date', '<=', $through), '>=', 3);
    }

    private function applyHighValue($query): void
    {
        $query->where(function ($nested): void {
            foreach (self::VALUE_THRESHOLDS as $currency => $threshold) {
                $nested->orWhereExists(function ($subquery) use ($currency, $threshold): void {
                    $subquery->selectRaw('1')
                        ->from('orders')
                        ->join('order_items', 'order_items.order_id', '=', 'orders.id')
                        ->whereColumn('orders.customer_id', 'customers.id')
                        ->where('orders.order_kind', 'customer')
                        ->where('orders.order_status', '!=', 'cancelled')
                        ->where('orders.currency', $currency)
                        ->groupBy('orders.customer_id', 'orders.currency')
                        ->havingRaw('SUM(order_items.line_revenue) >= ?', [$threshold]);
                });
            }
        });
    }

    private function validOrders($query)
    {
        return $query
            ->where('order_kind', 'customer')
            ->where('order_status', '!=', 'cancelled');
    }
}
