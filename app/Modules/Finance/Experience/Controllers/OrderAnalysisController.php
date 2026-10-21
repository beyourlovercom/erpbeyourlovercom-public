<?php

namespace App\Modules\Finance\Experience\Controllers;

use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use App\Modules\Finance\Application\EstimatedProfitProjectionService;
use App\Modules\Finance\Application\OrderAnalysisService;
use App\Modules\Finance\Application\OrderProfitExplanationService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Shell\Application\UserUiPreferenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OrderAnalysisController
{
    private const GRID_NAMESPACE = 'grid.finance.order-profit.v1';

    private const SORTS = [
        'order_date' => 'order_date',
        'order_number' => 'erp_order_id',
        'currency' => 'currency',
        'source' => 'source_type',
    ];

    public function __construct(
        private readonly OrderAnalysisService $analysis,
        private readonly EstimatedProfitProjectionService $estimatedProfit,
        private readonly OrderProfitExplanationService $explanations,
        private readonly ConfirmedLossReader $confirmedLosses,
        private readonly UserUiPreferenceService $uiPreferences,
    ) {}

    public function index(Request $request): Response
    {
        $paginator = $this->filteredOrders($request)->paginate(50)->withQueryString();
        $orders = $paginator->getCollection()
            ->map(fn (Order $order): array => $this->row($order))
            ->values()
            ->all();
        $pagination = $paginator->toArray();
        unset($pagination['data']);

        return Inertia::render('Finance/OrderAnalysis', [
            // Preserve the inherited `orders[]` Inertia contract while exposing pagination separately.
            'orders' => $orders,
            'pagination' => $pagination,
            'fields' => $this->analysis->fields(),
            'filters' => $request->only([
                'search', 'source_type', 'completeness', 'profit_status', 'date_from', 'date_to',
                'country', 'sku', 'product', 'fx_status', 'country_missing', 'product_missing', 'sort', 'direction',
            ]),
            'kpis' => $this->kpis($request),
            'gridPreferenceNamespace' => self::GRID_NAMESPACE,
            'gridPreference' => $this->uiPreferences->get($request->user(), self::GRID_NAMESPACE),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $kind = $order->order_kind instanceof \BackedEnum ? $order->order_kind->value : (string) $order->order_kind;
        abort_unless($kind === 'customer', 404);
        abort_if($order->externalReferences()->where('provider', 'historical_logistics')->exists(), 404);
        $order->load(['address', 'financials', 'costEntries', 'items.product', 'fulfillmentChildren.items', 'fulfillmentChildren.shipment.warehouse']);

        return Inertia::render('Finance/OrderProfitShow', [
            'explanation' => $this->explanations->explain($order),
        ]);
    }

    public function update(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $fields = array_fill_keys($this->analysis->fields(), ['nullable', 'numeric', 'decimal:0,2', 'min:0']);
        $data = $request->validate([
            ...$fields,
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);
        $this->analysis->save($order, $data, $request->user());

        return back()->with('success', '成本利润已更新');
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = $this->filteredOrders($request)->reorder()->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'ERP订单号', '来源订单号', '来源', '日期', '国家', '币种', '净商业收入', '总成本',
                '预估总成本', '预估利润', '预估Margin', '最终贡献利润', '最终Margin', '利润状态', '最终完整性', '预估利润可用', 'P8.2必需成本覆盖', 'P8.2缺失必需成本', '缺失项', 'USD汇总FX状态',
            ]);
            $query->chunkById(200, function ($orders) use ($handle): void {
                foreach ($orders as $order) {
                    $summary = $this->analysis->summary($order);
                    $estimated = $this->estimatedProfit->project($order, useCurrentCandidates: false);
                    fputcsv($handle, [
                        $order->erp_order_id,
                        $order->external_order_number,
                        $order->source_type->value,
                        $order->order_date?->format('Y-m-d'),
                        $order->address?->country,
                        $order->currency,
                        $summary['net_revenue'],
                        $summary['known_cost'],
                        $estimated['canonical_estimated_total_cost'],
                        $estimated['canonical_estimated_profit'],
                        $estimated['canonical_estimated_margin'],
                        $summary['contribution_profit'],
                        $summary['margin'],
                        $summary['profit_status'],
                        $summary['finance_status'],
                        $estimated['canonical_estimated_ready'] ? 'ready' : 'incomplete',
                        $summary['p82_required_cost_covered_count'].'/'.$summary['p82_required_cost_count'],
                        implode('|', $summary['p82_missing_required_costs']),
                        implode('|', $summary['missing_facts']),
                        $summary['fx_status'],
                    ]);
                }
            });
            fclose($handle);
        }, 'order-profit.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredOrders(Request $request): Builder
    {
        $query = Order::query()
            ->with([
                'address',
                'financials',
                'costEntries',
                'owner',
                'items:id,order_id,product_id,sku,product_summary',
            ])
            ->where('order_kind', 'customer')
            ->whereDoesntHave('externalReferences', fn (Builder $references) => $references->where('provider', 'historical_logistics'))
            ->when($request->input('source_type'), fn (Builder $orders, string $source) => $orders->where('source_type', $source))
            ->when($request->input('date_from'), fn (Builder $orders, string $date) => $orders->whereDate('order_date', '>=', $date))
            ->when($request->input('date_to'), fn (Builder $orders, string $date) => $orders->whereDate('order_date', '<=', $date))
            ->when($request->input('country'), fn (Builder $orders, string $country) => $orders->whereHas('address', fn (Builder $address) => $address->where('country', strtoupper(trim($country)))))
            ->when($request->input('sku'), fn (Builder $orders, string $sku) => $orders->whereHas('items', fn (Builder $items) => $items->where('sku', 'like', '%'.trim($sku).'%')))
            ->when($request->input('product'), function (Builder $orders, string $product): void {
                $product = trim($product);
                $orders->whereHas('items', fn (Builder $items) => $items
                    ->where('sku', 'like', '%'.$product.'%')
                    ->orWhere('product_summary', 'like', '%'.$product.'%'));
            })
            ->when($request->boolean('country_missing'), fn (Builder $orders) => $orders->where(function (Builder $missing): void {
                $missing->whereDoesntHave('address')->orWhereHas('address', fn (Builder $address) => $address->whereNull('country')->orWhere('country', ''));
            }))
            ->when($request->boolean('product_missing'), fn (Builder $orders) => $orders->where(function (Builder $missing): void {
                $missing->whereDoesntHave('items')->orWhereHas('items', fn (Builder $items) => $items->whereNull('sku')->whereNull('product_summary'));
            }))
            ->when($request->input('search'), function (Builder $orders, string $search): void {
                $search = trim($search);
                $orders->where(function (Builder $nested) use ($search): void {
                    $nested->where('internal_order_number', 'like', "%{$search}%")
                        ->orWhere('erp_order_id', 'like', "%{$search}%")
                        ->orWhere('external_order_number', 'like', "%{$search}%")
                        ->orWhereHas('address', fn (Builder $address) => $address->where('recipient_name', 'like', "%{$search}%"))
                        ->orWhereHas('items', fn (Builder $items) => $items
                            ->where('product_summary', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%"));
                });
            });

        $requestedCompleteness = (string) $request->input('completeness');
        $status = strtoupper($requestedCompleteness);
        if ($requestedCompleteness === 'complete') {
            $query->whereHas('financials', fn (Builder $financials) => $financials->whereIn('completeness_status', ['CONFIRMED', 'RECONCILED']));
        } elseif ($requestedCompleteness === 'incomplete') {
            $query->where(function (Builder $orders): void {
                $orders->whereDoesntHave('financials')
                    ->orWhereHas('financials', fn (Builder $financials) => $financials->whereNotIn('completeness_status', ['CONFIRMED', 'RECONCILED']));
            });
        } elseif (in_array($status, OrderAnalysisService::FINANCE_STATUSES, true)) {
            if ($status === 'UNPOSTED') {
                $query->where(function (Builder $orders): void {
                    $orders->whereDoesntHave('financials')
                        ->orWhereHas('financials', fn (Builder $financials) => $financials->where('completeness_status', 'UNPOSTED'));
                });
            } else {
                $query->whereHas('financials', fn (Builder $financials) => $financials->where('completeness_status', $status));
            }
        }

        if ($request->input('fx_status') === 'aggregate_ready') {
            $query->where(function (Builder $orders): void {
                $orders->where(function (Builder $withoutFinance): void {
                    $withoutFinance->whereDoesntHave('financials')->where('currency', 'USD');
                })->orWhereHas('financials', function (Builder $financials): void {
                    $financials->where('currency', 'USD')->orWhere(function (Builder $foreign): void {
                        $foreign->where('currency', '!=', 'USD')
                            ->whereNotNull('fx_rate')
                            ->whereNotNull('fx_rate_date')
                            ->whereNotNull('fx_rate_source');
                    });
                });
            });
        } elseif ($request->input('fx_status') === 'pending') {
            $query->where(function (Builder $orders): void {
                $orders->where(function (Builder $withoutFinance): void {
                    $withoutFinance->whereDoesntHave('financials')->where('currency', '!=', 'USD');
                })->orWhereHas('financials', function (Builder $financials): void {
                    $financials->where('currency', '!=', 'USD')->where(function (Builder $missing): void {
                        $missing->whereNull('fx_rate')->orWhereNull('fx_rate_date')->orWhereNull('fx_rate_source');
                    });
                });
            });
        }

        if ($request->input('profit_status') === 'loss') {
            $query = $this->confirmedLosses->applyConfirmedLossScope($query);
        } elseif ($request->input('profit_status') === 'confirmed') {
            $query->whereHas('financials', fn (Builder $financials) => $financials->whereIn('completeness_status', ['CONFIRMED', 'RECONCILED']));
        } elseif ($request->input('profit_status') === 'not_confirmed') {
            $query->where(function (Builder $orders): void {
                $orders->whereDoesntHave('financials')
                    ->orWhereHas('financials', fn (Builder $financials) => $financials->whereNotIn('completeness_status', ['CONFIRMED', 'RECONCILED']));
            });
        }

        $sort = self::SORTS[$request->input('sort')] ?? 'order_date';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderByDesc('id');
    }

    private function row(Order $order): array
    {
        $products = $order->items
            ->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'name' => $item->product_summary,
            ])
            ->unique(fn (array $item): string => ($item['product_id'] ?? 'null').'|'.$item['sku'].'|'.$item['name'])
            ->values()
            ->all();

        $summary = $this->analysis->summary($order);
        $estimated = $this->estimatedProfit->project($order, useCurrentCandidates: false);

        return [
            'id' => $order->id,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'currency' => $order->currency,
            'source_type' => $order->source_type->value,
            'country' => $order->address?->country,
            'recipient_name' => $order->address?->recipient_name,
            'products' => $products,
            'revenue_provenance' => $order->financials?->revenue_provenance,
            'provider_order_status' => $order->financials?->provider_order_status,
            'provider_reconciliation_status' => $order->financials?->reconciliation_status,
            'paid_at' => $order->financials?->paid_at?->toIso8601String(),
            'summary' => array_merge($summary, [
                'canonical_estimated_ready' => $estimated['canonical_estimated_ready'],
                'canonical_estimated_total_cost' => $estimated['canonical_estimated_total_cost'],
                'canonical_estimated_cost_breakdown' => $estimated['canonical_estimated_cost_breakdown'],
                'canonical_estimated_profit' => $estimated['canonical_estimated_profit'],
                'canonical_estimated_margin' => $estimated['canonical_estimated_margin'],
                'estimated_cost_count' => $estimated['estimated_cost_count'],
                'posted_estimated_cost_count' => $estimated['posted_estimated_cost_count'],
                'fallback_estimated_cost_count' => $estimated['fallback_estimated_cost_count'],
                'confirmed_cost_count' => $estimated['confirmed_cost_count'],
                'missing_required_cost_count' => $estimated['missing_required_cost_count'],
                'projection_confidence' => $estimated['projection_confidence'],
            ]),
            'values' => $this->analysis->values($order),
        ];
    }

    private function kpis(Request $request): array
    {
        $query = $this->filteredOrders($request)->reorder();
        $eligible = (clone $query)->count();
        $confirmed = (clone $query)
            ->whereHas('financials', fn (Builder $financials) => $financials->whereIn('completeness_status', ['CONFIRMED', 'RECONCILED']))
            ->count();
        $revenueReady = $this->applyRevenueReadyScope(clone $query)->count();
        $p82RequiredReady = $this->applyP82RequiredCostReadyScope(
            $this->applyRevenueReadyScope(clone $query),
        )->count();
        $fxPending = (clone $query)
            ->where('currency', '!=', 'USD')
            ->where(function (Builder $orders): void {
                $orders->whereDoesntHave('financials')
                    ->orWhereHas('financials', fn (Builder $financials) => $financials
                        ->whereNull('fx_rate')
                        ->orWhereNull('fx_rate_date')
                        ->orWhereNull('fx_rate_source'));
            })
            ->count();

        return [
            'eligible_orders' => $eligible,
            'revenue_ready_orders' => $revenueReady,
            'estimated_profit_orders' => $revenueReady,
            'estimated_profit_percent' => $revenueReady > 0 ? 100.0 : 0,
            'p82_required_cost_ready_orders' => $p82RequiredReady,
            'p82_required_cost_percent' => $revenueReady > 0 ? round($p82RequiredReady / $revenueReady * 100, 1) : 0,
            'confirmed_profit_orders' => $confirmed,
            'incomplete_orders' => max(0, $eligible - $confirmed),
            'completeness_percent' => $eligible > 0 ? round($confirmed / $eligible * 100, 1) : 0,
            'final_profit_percent_of_revenue_ready' => $revenueReady > 0 ? round($confirmed / $revenueReady * 100, 1) : 0,
            'fx_pending_orders' => $fxPending,
        ];
    }

    private function applyRevenueReadyScope(Builder $query): Builder
    {
        return $query->whereHas('financials', function (Builder $financials): void {
            $financials->where(function (Builder $ready): void {
                $ready->where(function (Builder $provider): void {
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
                    $manual->where('revenue_provenance', 'confirmed_manual')
                        ->where(function (Builder $facts): void {
                            $facts->where(function (Builder $components): void {
                                $components->whereNotNull('merchandise_list_amount')
                                    ->whereNotNull('discount_amount')
                                    ->whereNotNull('shipping_revenue')
                                    ->whereNotNull('refund_amount');
                            })->orWhere(function (Builder $legacy): void {
                                $legacy->whereNull('merchandise_list_amount')
                                    ->whereNotNull('amount_paid')
                                    ->whereNotNull('refund_amount');
                            });
                        });
                })->orWhere(function (Builder $legacy): void {
                    $legacy->whereNull('revenue_provenance')
                        ->whereNull('merchandise_list_amount')
                        ->whereNotNull('amount_paid')
                        ->whereNotNull('refund_amount');
                });
            });
        });
    }

    private function applyP82RequiredCostReadyScope(Builder $query): Builder
    {
        foreach (OrderAnalysisService::p82RequiredCostFields() as $costType) {
            $query->whereHas('costEntries', function (Builder $costs) use ($costType): void {
                $costs->where('cost_type', $costType)
                    ->whereColumn('order_cost_entries.currency', 'orders.currency')
                    ->where(function (Builder $accepted): void {
                        $accepted->where('provenance', 'estimated_reference')
                            ->orWhere('status', 'estimated')
                            ->orWhere(function (Builder $confirmed): void {
                                $confirmed->whereIn('status', ['confirmed', 'adjusted'])
                                    ->whereIn('provenance', ['actual', 'confirmed_manual', 'provider_import', 'allocated_actual']);
                            });
                    });
            });
        }

        return $query;
    }
}
