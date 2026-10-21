<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ConfirmedLossProjection implements ConfirmedLossReader
{
    private const COST_TYPES = [
        'product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost',
        'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
    ];

    private const CONFIRMED_COST_PROVENANCE = [
        'actual', 'confirmed_manual', 'provider_import', 'allocated_actual',
    ];

    public function __construct(private readonly OrderAnalysisService $analysis) {}

    public function isConfirmedLoss(Model $order): bool
    {
        $summary = $this->analysis->summary($order);

        return in_array($summary['finance_status'], ['CONFIRMED', 'RECONCILED'], true)
            && $summary['profit_status'] === 'confirmed'
            && $summary['contribution_profit'] !== null
            && bccomp((string) $summary['contribution_profit'], '0.00', 2) < 0;
    }

    public function applyConfirmedLossScope(Builder $query): Builder
    {
        $costPlaceholders = implode(',', array_fill(0, count(self::COST_TYPES), '?'));
        $provenancePlaceholders = implode(',', array_fill(0, count(self::CONFIRMED_COST_PROVENANCE), '?'));

        $query->whereHas('financials', function (Builder $financials): void {
            $financials->where(function (Builder $revenue): void {
                $revenue->where(function (Builder $provider): void {
                    $provider->where('revenue_provenance', 'provider_import')
                        ->whereNotNull('merchandise_list_amount')
                        ->whereNotNull('discount_amount')
                        ->whereNotNull('shipping_revenue')
                        ->whereNotNull('refund_amount')
                        ->whereNotNull('provider_order_total')
                        ->whereNotNull('tax_collected')
                        ->whereNotNull('paid_at')
                        ->whereIn('provider_order_status', ['processing', 'completed', 'refunded'])
                        ->where('reconciliation_status', 'matched');
                })->orWhere(function (Builder $manual): void {
                    $manual->where(function (Builder $provenance): void {
                        $provenance->where('revenue_provenance', 'confirmed_manual')
                            ->orWhere(function (Builder $legacy): void {
                                $legacy->whereNull('revenue_provenance')
                                    ->whereNotNull('amount_paid')
                                    ->whereNotNull('refund_amount');
                            });
                    })->where(function (Builder $evidence): void {
                        $evidence->where(function (Builder $components): void {
                            $components->whereNotNull('merchandise_list_amount')
                                ->whereNotNull('discount_amount')
                                ->whereNotNull('shipping_revenue')
                                ->whereNotNull('refund_amount');
                        })->orWhere(function (Builder $legacy): void {
                            $legacy->whereNotNull('amount_paid')->whereNotNull('refund_amount');
                        });
                    });
                });
            });
        });

        $query->whereHas('costEntries', function (Builder $costs): void {
            $costs->whereIn('cost_type', self::COST_TYPES)
                ->whereIn('status', ['confirmed', 'adjusted'])
                ->whereIn('provenance', self::CONFIRMED_COST_PROVENANCE);
        }, '>=', count(self::COST_TYPES));

        $profitSql = "(SELECT CASE
            WHEN order_financials.merchandise_list_amount IS NOT NULL
             AND order_financials.discount_amount IS NOT NULL
             AND order_financials.shipping_revenue IS NOT NULL
             AND order_financials.refund_amount IS NOT NULL
            THEN order_financials.merchandise_list_amount
               - order_financials.discount_amount
               + order_financials.shipping_revenue
               - order_financials.refund_amount
            ELSE order_financials.amount_paid
               - order_financials.refund_amount
          END
          FROM order_financials
          WHERE order_financials.order_id = orders.id)
          - (SELECT COALESCE(SUM(order_cost_entries.amount), 0)
             FROM order_cost_entries
             WHERE order_cost_entries.order_id = orders.id
               AND order_cost_entries.cost_type IN ({$costPlaceholders})
               AND order_cost_entries.status IN ('confirmed', 'adjusted')
               AND order_cost_entries.provenance IN ({$provenancePlaceholders})) < 0";

        return $query->whereRaw($profitSql, [...self::COST_TYPES, ...self::CONFIRMED_COST_PROVENANCE]);
    }
}
