<?php

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\Contracts\AccountingContinuousDeltaCandidateStore;
use App\Modules\Logistics\Application\Contracts\OverseasHandlingEvidenceReader;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentAccountingContinuousDeltaCandidateStore implements AccountingContinuousDeltaCandidateStore
{
    public function candidates(CarbonImmutable $windowStart, CarbonImmutable $windowEnd, int $limit): Collection
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("SET time_zone = '+00:00'");
        }

        return Order::query()
            ->where('order_kind', OrderKind::Customer->value)
            ->whereDoesntHave('externalReferences', fn (Builder $refs) => $refs
                ->where('provider', 'historical_logistics'))
            ->where(function (Builder $query) use ($windowStart, $windowEnd): void {
                $query->whereBetween('orders.updated_at', [$windowStart, $windowEnd])
                    ->orWhereHas('externalReferences', fn (Builder $refs) => $refs
                        ->where('provider', 'woocommerce')
                        ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                    ->orWhereHas('financials', fn (Builder $financials) => $financials
                        ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                    ->orWhereHas('shipment', fn (Builder $shipment) => $shipment
                        ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                    ->orWhereHas('shipment.billingEvidence', fn (Builder $billing) => $billing
                        ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                    ->when($this->multiShipmentEnabled(), fn (Builder $scope) => $scope
                        ->orWhereHas('fulfillmentChildren', fn (Builder $children) => $children
                            ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                        ->orWhereHas('fulfillmentChildren.items', fn (Builder $items) => $items
                            ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                        ->orWhereHas('fulfillmentChildren.shipment', fn (Builder $shipment) => $shipment
                            ->whereBetween('updated_at', [$windowStart, $windowEnd]))
                        ->orWhereHas('fulfillmentChildren.shipment.billingEvidence', fn (Builder $billing) => $billing
                            ->whereBetween('updated_at', [$windowStart, $windowEnd])))
                    ->when($this->overseasHandlingAvailable(), fn (Builder $scope) => $scope
                        ->orWhereHas('shipment.overseasHandlingEvidence', fn (Builder $billing) => $billing
                            ->whereBetween('updated_at', [$windowStart, $windowEnd])))
                    ->orWhereExists(fn ($evidence) => $evidence
                        ->selectRaw('1')
                        ->from('warehouse_storage_cost_import_rows as warehouse_cost_rows')
                        ->whereColumn('warehouse_cost_rows.order_id', 'orders.id')
                        ->where('warehouse_cost_rows.decision', 'ready')
                        ->whereBetween('warehouse_cost_rows.updated_at', [$windowStart, $windowEnd]))
                    ->when((bool) config('warehouse_storage_aging.order_cost_enabled', false), fn (Builder $scope) => $scope
                        ->orWhereExists(fn ($p9) => $p9
                            ->selectRaw('1')
                            ->from('warehouse_inventory_lot_consumptions as p9_consumptions')
                            ->whereBetween('p9_consumptions.updated_at', [$windowStart, $windowEnd])
                            ->where(function ($link): void {
                                $link->whereColumn('p9_consumptions.order_id', 'orders.id')
                                    ->orWhereIn('p9_consumptions.order_id', fn ($children) => $children
                                        ->select('p9_children.id')
                                        ->from('orders as p9_children')
                                        ->whereColumn('p9_children.fulfillment_parent_id', 'orders.id'));
                            })))
                    ->when((bool) config('erp_accounting.continuous_delta.kol_commission_enabled', false), fn (Builder $scope) => $scope
                        ->orWhereExists(fn ($referrals) => $referrals
                            ->selectRaw('1')
                            ->from('kol_affiliate_referrals as affiliate_referrals')
                            ->whereColumn('affiliate_referrals.order_id', 'orders.id')
                            ->where('affiliate_referrals.reconciliation_status', 'present')
                            ->whereBetween('affiliate_referrals.updated_at', [$windowStart, $windowEnd])));
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }
    private function multiShipmentEnabled(): bool
    {
        return (bool) config('erp_accounting.multi_shipment.enabled', false);
    }

    private function overseasHandlingAvailable(): bool
    {
        return interface_exists(OverseasHandlingEvidenceReader::class)
            && app()->bound(OverseasHandlingEvidenceReader::class);
    }

}
