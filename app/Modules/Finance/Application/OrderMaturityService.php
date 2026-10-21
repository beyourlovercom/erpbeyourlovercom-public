<?php

namespace App\Modules\Finance\Application;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

final class OrderMaturityService
{
    public const STATE_FRESH_ESTIMATE_ALLOWED = 'fresh_estimate_allowed';
    public const STATE_ESTIMATE_ALLOWED = 'estimate_allowed';
    public const STATE_ACTUAL_RECONCILIATION_DUE = 'actual_reconciliation_due';
    public const STATE_ACTUAL_COST_OVERDUE = 'actual_cost_overdue';

    /**
     * @return array{
     *   age_days: int|null,
     *   maturity_state: string|null,
     *   reconciliation_due: bool,
     *   estimate_allowed: bool,
     * }
     */
    public function forOrder(Model $order): array
    {
        $orderDate = $order->order_date;
        if ($orderDate === null) {
            return [
                'age_days' => null,
                'maturity_state' => null,
                'reconciliation_due' => false,
                'estimate_allowed' => true,
            ];
        }

        $ageDays = (int) Carbon::parse($orderDate)->startOfDay()->diffInDays(Carbon::now()->startOfDay());

        $state = match (true) {
            $ageDays <= 7  => self::STATE_FRESH_ESTIMATE_ALLOWED,
            $ageDays <= 30 => self::STATE_ESTIMATE_ALLOWED,
            $ageDays <= 45 => self::STATE_ACTUAL_RECONCILIATION_DUE,
            default        => self::STATE_ACTUAL_COST_OVERDUE,
        };

        return [
            'age_days'           => $ageDays,
            'maturity_state'     => $state,
            'reconciliation_due' => $ageDays > 30,
            'estimate_allowed'   => $ageDays <= 30,
        ];
    }
}
