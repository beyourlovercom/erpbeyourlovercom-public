<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Finance\Application\OrderAnalysisService;
use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\FulfillmentAllocationAccess;
use App\Modules\Logistics\Application\FulfillmentAllocationDecisionService;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\ShipmentCompletionSemantics;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderPackagingReferenceProjection;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderItemAmendmentPreviewService;
use App\Modules\Orders\Application\OrderItemAtomicReplanService;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly InventoryAvailabilityReader $availability,
        private readonly SavedViewService $views,
        private readonly OrderPackagingReferenceProjection $packagingReferences,
        private readonly OrderAnalysisService $orderAnalysis,
        private readonly FulfillmentPlanPreviewService $fulfillmentPlans,
        private readonly FulfillmentAllocationDecisionService $allocationDecisions,
        private readonly FulfillmentAllocationAccess $fulfillmentAllocationAccess,
        private readonly LogisticsExecutionAccess $logisticsExecutionAccess,
        private readonly ShipmentCompletionSemantics $shipmentCompletion,
        private readonly CarrierServiceCatalog $carrierServices,
        private readonly CarrierRoutingPolicy $carrierRouting,
    ) {}

    public function index(Request $request): Response
    {
        $kind = $this->kindOrNull($request);
        $filters = $request->only(['search', 'source_type', 'order_status', 'view']);
        $scope = (string) $request->route('order_scope');
        if ($scope === 'external_warehouse') {
            $filters['warehouse_ownership'] = 'external';
        }
        if ($kind) {
            $filters['order_kind'] = $kind->value;
        }
        $filters = KolOrderAccessScope::scopeFilters($request->user(), $filters);
        $orders = $this->orders->list($filters);

        $isCustomerKind = $kind?->value === 'customer';
        $canExport = $isCustomerKind
            ? ($request->user()->can('customers.export') && $request->user()->can('system.permissions.manage'))
            : $request->user()->can('orders.view');

        return Inertia::render('Orders/Index', [
            'orderKind' => $kind?->value ?? 'all',
            'orderScope' => $scope === 'external_warehouse' ? 'external_warehouse' : 'all',
            'orders' => $orders->through(fn (Order $order): array => $this->orderData($order))->toArray(),
            'filters' => $request->only(['search', 'source_type', 'order_status', 'view']),
            'savedViews' => $this->views->forUser('orders', (int) $request->user()->getAuthIdentifier(), false),
            'sourceTypes' => array_map(fn (OrderSourceType $source): array => ['value' => $source->value, 'label' => $this->sourceLabel($source)], OrderSourceType::cases()),
            'canExport' => $canExport,
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = $this->kind($request);
        KolOrderAccessScope::authorizeCreate($request->user(), $kind);

        return Inertia::render('Orders/Create', [
            'defaultOrderKind' => $kind->value,
            'orderKindLabel' => $this->kindLabel($kind),
            'generatedExternalOrderNumber' => $this->orders->generateExternalOrderNumber($kind),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        KolOrderAccessScope::authorizeCreate($request->user(), $this->kind($request));
        $data = $request->validate($this->rules());
        $items = $data['items'];
        unset($data['items']);
        // Route context is authority: order_kind/source_type/owner cannot be overridden by client input.
        $data['order_kind'] = $this->kind($request)->value;
        $data['source_type'] = OrderSourceType::Manual->value;
        $data['currency'] ??= 'USD';
        $data['owner_id'] = $request->user()->getAuthIdentifier();
        $order = $this->orders->create($data, $items, $request->user());

        $routeName = $order->order_kind === OrderKind::KolFulfillment ? 'kol.orders.show' : 'customers.orders.show';

        return redirect()->route($routeName, $order)->with('success', '订单已创建');
    }

    public function showAny(Request $request, Order $order): RedirectResponse
    {
        $target = $order;
        if ($order->order_kind === OrderKind::FulfillmentDerived) {
            $target = $order->fulfillmentParent()->firstOrFail();
        }

        KolOrderAccessScope::authorize($request->user(), $target);

        return match ($target->order_kind) {
            OrderKind::Customer => redirect()->route('customers.orders.show', $target),
            OrderKind::KolFulfillment => redirect()->route('kol.orders.show', $target),
            default => abort(404),
        };
    }

    public function show(Request $request, Order $order): Response
    {
        $this->authorizeKind($request, $order);
        KolOrderAccessScope::authorize($request->user(), $order);
        $order->load(['items', 'owner', 'address', 'shipment.warehouse', 'manualSync', 'operationalMarkers', 'procurementOrder', 'fulfillmentAllocation', 'fulfillmentChildren.items', 'fulfillmentChildren.shipment.warehouse', 'fulfillmentChildren.fulfillmentOrigin']);
        $productIds = $order->items->pluck('product_id')->filter()->unique()->values()->all();
        $inventoryAvailability = $this->availability->forProductIds($productIds);
        $warehouses = $this->availability->activeWarehouses();
        $allocationUi = $this->allocationUi($request, $order, $productIds, $warehouses, $inventoryAvailability);

        return Inertia::render('Orders/Show', [
            'order' => $this->orderData($order),
            'inventoryAvailability' => $inventoryAvailability,
            'allocationUi' => $allocationUi,
            'packagingReferences' => $this->packagingReferences->forOrder($order),
            'warehouses' => $warehouses,
            'warehouseRouting' => collect($warehouses)->mapWithKeys(function (array $warehouse) use ($order): array {
                $route = $this->carrierRouting->warehouseRoute($order->address?->country, (string) ($warehouse['code'] ?? ''));

                return [(int) $warehouse['id'] => [
                    'carrier' => $route['carrier'] ?? null,
                    'service' => $route['service'] ?? null,
                    'carrier_service_id' => $route['carrier_service_id'] ?? null,
                    'automatic' => filled($route['carrier'] ?? null),
                ]];
            })->all(),
            'carrierOptions' => $this->carrierServices->options(),
            'canOperateLogistics' => $this->logisticsExecutionAccess->allows($request->user()),
            'canViewProfit' => $request->user()?->can('analysis.view') ?? false,
            'canMarkOperational' => $request->user()?->can('orders.edit') ?? false,
            'canEditOrder' => $request->user()?->can('orders.edit') ?? false,
            'canCreateReplacement' => $this->logisticsExecutionAccess->allows($request->user()) && $this->originalLogisticsStarted($order),
            'canCreateProcurement' => ($request->user()?->can('procurement.create') ?? false) && $order->operationalMarkers->contains('marker', 'stockout'),
            'canManageProductPackaging' => $request->user()?->can('products.operate') ?? false,
            'canOperateLogistics' => $this->logisticsExecutionAccess->allows($request->user()),
            'canViewPackagingEvidence' => ($request->user()?->can('inventory.view') ?? false) || ($request->user()?->can('inventory.packaging.review') ?? false),
            'audit' => AuditRecord::query()
                ->where('entity_type', Order::class)
                ->where('entity_id', (string) $order->getKey())
                ->latest('created_at')->limit(30)->get()
                ->map(fn (AuditRecord $record): array => [
                    'action' => $record->action, 'actor_id' => $record->actor_id,
                    'created_at' => $record->created_at?->toIso8601String(), 'reason' => $record->reason,
                ])->all(),
        ]);
    }

    /** @param array<int,int> $productIds @param array<int,array<string,mixed>> $warehouses @param array<int,array<int,array<string,mixed>>> $availability */
    private function allocationUi(Request $request, Order $order, array $productIds, array $warehouses, array $availability): array
    {
        $allowedCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $selectableWarehouseIds = collect($warehouses)
            ->filter(fn (array $warehouse): bool => in_array(strtoupper((string) ($warehouse['code'] ?? '')), $allowedCodes, true))
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        $recommendedByItem = collect($order->items)->mapWithKeys(fn ($item): array => [(int) $item->id => null])->all();
        $recommendedPlanKey = null;
        $canUseRecommendation = false;

        if ($order->order_status?->value === 'pending_shipment' && $productIds !== []) {
            try {
                $context = array_replace(
                    ['warehouses' => $warehouses, 'availability' => $availability],
                    $this->fulfillmentPlans->prepare($productIds),
                );
                $destination = [
                    'province_state' => $order->address?->province_state,
                    'city' => $order->address?->city,
                    'address_line_1' => $order->address?->address_line_1,
                    'address_line_2' => $order->address?->address_line_2,
                    'postal_code' => $order->address?->postal_code,
                ];
                $items = $order->items->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'quantity' => (int) $item->quantity,
                    'sku' => $item->sku,
                ])->values()->all();
                $preview = $this->fulfillmentPlans->preview($order->address?->country, $items, $destination, [], $context);
                $decision = $this->allocationDecisions->present($order, $preview);
                $recommendedPlanKey = $decision['recommended_plan_key'] ?? null;
                $canUseRecommendation = (bool) ($decision['can_use_recommendation'] ?? false);
                foreach (($decision['recommended_plan']['segments'] ?? []) as $segment) {
                    foreach (($segment['items'] ?? []) as $planItem) {
                        $matched = $order->items->first(function ($item) use ($planItem): bool {
                            if ($item->product_id !== null && isset($planItem['product_id'])) {
                                return (int) $item->product_id === (int) $planItem['product_id'];
                            }

                            return trim((string) $item->sku) !== '' && trim((string) $item->sku) === trim((string) ($planItem['sku'] ?? ''));
                        });
                        if ($matched && isset($segment['warehouse_id'])) {
                            $recommendedByItem[(int) $matched->id] = (int) $segment['warehouse_id'];
                        }
                    }
                }
            } catch (\Throwable) {
                // Inventory remains visible even when recommendation evidence is incomplete.
            }
        }

        $selectedByItem = collect($order->items)->mapWithKeys(fn ($item): array => [(int) $item->id => null])->all();
        $snapshot = $order->fulfillmentAllocation?->plan_snapshot;
        foreach ((array) ($snapshot['segments'] ?? []) as $segment) {
            foreach ((array) ($segment['items'] ?? []) as $planItem) {
                $matched = $order->items->first(function ($item) use ($planItem): bool {
                    if ($item->product_id !== null && isset($planItem['product_id'])) {
                        return (int) $item->product_id === (int) $planItem['product_id'];
                    }

                    return trim((string) $item->sku) !== '' && trim((string) $item->sku) === trim((string) ($planItem['sku'] ?? ''));
                });
                if ($matched && isset($segment['warehouse_id'])) {
                    $selectedByItem[(int) $matched->id] = (int) $segment['warehouse_id'];
                }
            }
        }
        if (! $order->fulfillmentAllocation && $order->shipment?->warehouse_id) {
            foreach ($selectedByItem as $itemId => $_) {
                $selectedByItem[$itemId] = (int) $order->shipment->warehouse_id;
            }
        }

        $originalChildren = $order->relationLoaded('fulfillmentChildren')
            ? $order->fulfillmentChildren->filter(fn (Order $child): bool => (string) ($child->fulfillment_role ?? '') !== 'replacement')->values()
            : collect();
        $hasCommittedDerivedFulfillment = $originalChildren->isNotEmpty();
        $allocationCommitted = $order->fulfillmentAllocation !== null || $hasCommittedDerivedFulfillment;
        $fulfillmentSegments = $originalChildren->isNotEmpty() ? $originalChildren : collect([$order]);
        $logisticsStarted = $fulfillmentSegments->contains(function (Order $segment): bool {
            $shipment = $segment->shipment;
            if (! $shipment) {
                return false;
            }

            return $shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null;
        });
        $requiresFullFlow = $order->items->contains(fn ($item): bool => strcasecmp(trim((string) $item->sku), 'gift') === 0);
        $allocationAllowed = $this->fulfillmentAllocationAccess->allows($request->user());
        $canReallocate = $order->order_status?->value === 'pending_shipment'
            && $allocationCommitted
            && ! $logisticsStarted
            && $allocationAllowed
            && ! $requiresFullFlow;
        $canPartialReallocate = $allocationCommitted
            && $originalChildren->count() > 1
            && $logisticsStarted
            && $allocationAllowed;

        return [
            'status' => $allocationCommitted ? 'submitted' : ($order->order_status?->value === 'pending_shipment' ? 'pending' : 'locked'),
            'can_operate' => $order->order_status?->value === 'pending_shipment' && ! $allocationCommitted && $allocationAllowed,
            'can_reallocate' => $canReallocate,
            'can_partial_reallocate' => $canPartialReallocate,
            'logistics_started' => $logisticsStarted,
            'requires_full_flow' => $requiresFullFlow,
            'selectable_warehouse_ids' => $selectableWarehouseIds,
            'recommended_warehouse_ids' => $recommendedByItem,
            'selected_warehouse_ids' => $selectedByItem,
            'recommended_plan_key' => $recommendedPlanKey,
            'can_use_recommendation' => $canUseRecommendation,
            'full_allocation_url' => route('logistics.wait-stock'),
        ];
    }

    public function previewItemAmendment(Request $request, Order $order, OrderItemAmendmentPreviewService $previews): JsonResponse
    {
        $this->authorizeKind($request, $order);
        KolOrderAccessScope::authorize($request->user(), $order);
        $validator = Validator::make($request->all(), [
            ...$this->updateRules($order),
            'items' => ['required', 'array', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => '订单商品存在无效字段，请检查红色提示后重试。',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }
        $validated = $validator->validated();

        try {
            return response()->json($previews->preview($order, $validated['items']));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => '订单商品预检未通过。',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /** A5 explicit operator decision; transaction remains in application service. */
    public function commitItemReplan(Request $request, Order $order, OrderItemAtomicReplanService $replans): JsonResponse
    {
        $this->authorizeKind($request, $order);
        KolOrderAccessScope::authorize($request->user(), $order);
        $validated = $request->validate([
            ...$this->updateRules($order),
            'items' => ['required', 'array', 'min:1'],
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.item_index' => ['required', 'integer', 'min:0'],
            'assignments.*.source_code' => ['required', 'string', 'max:24'],
            'assignments.*.carrier' => ['sometimes', 'nullable', 'string', 'max:128'],
            'assignments.*.service' => ['sometimes', 'nullable', 'string', 'max:128'],
            'expected_revision' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);
        return response()->json($replans->commit(
            $order, $validated['items'], $validated['assignments'],
            $validated['expected_revision'], $validated['reason'], $request->user(),
        ));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeKind($request, $order);
        KolOrderAccessScope::authorize($request->user(), $order);
        $data = $request->validate($this->updateRules($order));
        $items = $data['items'] ?? null;
        unset($data['items']);
        $this->orders->update($order, $data, $items, $request->user());

        return back()->with('success', '订单已更新');
    }

    public function setOperationalMarker(Request $request, Order $order, string $marker): RedirectResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $this->orders->setOperationalMarker($order, $marker, (bool) $data['active'], $request->user());

        return back()->with('success', '订单业务标记已更新');
    }

    public function export(Request $request): StreamedResponse
    {
        $kind = $this->kindOrNull($request);
        $filters = $request->only(['search', 'source_type', 'order_status', 'view']);
        $scope = (string) $request->route('order_scope');
        if ($scope === 'external_warehouse') {
            $filters['warehouse_ownership'] = 'external';
        }
        if ($kind) {
            $filters['order_kind'] = $kind->value;
        }
        $filters = KolOrderAccessScope::scopeFilters($request->user(), $filters);
        $orders = $this->orders->exportCursor($filters);

        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ERP订单号', '来源订单号', '来源', '订单日期', '收件人', '国家', '状态', '负责人']);
            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order->erp_order_id, $order->external_order_number, $this->sourceLabel($order->source_type),
                    $order->order_date?->toDateString(), $order->address?->recipient_name,
                    $order->address?->country, $order->order_status->value, $order->owner?->name,
                ]);
            }
            fclose($handle);
        }, 'orders.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function kind(Request $request): OrderKind
    {
        return $this->kindOrNull($request) ?? abort(404);
    }

    private function kindOrNull(Request $request): ?OrderKind
    {
        $value = (string) $request->route('order_kind');

        return $value === '' || $value === 'all' ? null : OrderKind::from($value);
    }

    private function authorizeKind(Request $request, Order $order): void
    {
        abort_if($order->order_kind !== $this->kind($request), HttpResponse::HTTP_NOT_FOUND);
    }

    private function orderData(Order $order): array
    {
        return [
            'id' => $order->id,
            'internal_order_number' => $order->internal_order_number,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'source_type' => $order->source_type->value,
            'source_label' => $this->sourceLabel($order->source_type),
            'order_kind' => $order->order_kind?->value,
            'order_kind_label' => $order->order_kind ? $this->kindLabel($order->order_kind) : null,
            'order_date' => $order->order_date?->toDateString(),
            'order_status' => $order->order_status->value,
            'order_status_label' => $this->statusLabel($order->order_status->value),
            'order_note' => $order->order_note,
            'currency' => $order->currency,
            'owner' => $order->owner ? ['id' => $order->owner->id, 'name' => $order->owner->name] : null,
            'owner_label' => $order->owner ? 'Owner('.$order->owner->name.')' : null,
            'address' => $order->address?->only(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code']),
            'items' => $order->items->map(fn ($item): array => $item->only(['id', 'product_id', 'product_summary', 'sku', 'quantity', 'unit_price', 'line_revenue']))->values()->all(),
            'shipment' => $order->shipment ? [
                ...$order->shipment->only(['shipment_status', 'warehouse_id', 'warehouse_selection_source', 'carrier', 'service', 'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by']),
                'warehouse_name' => $order->shipment->warehouse?->name,
            ] : null,
            'fulfillment' => $order->relationLoaded('fulfillmentChildren') ? $this->fulfillmentData($order) : null,
            'operational_markers' => $order->relationLoaded('operationalMarkers') ? $order->operationalMarkers->pluck('marker')->values()->all() : [],
            'procurement_order' => $order->relationLoaded('procurementOrder') && $order->procurementOrder ? ['id' => $order->procurementOrder->id, 'procurement_number' => $order->procurementOrder->procurement_number] : null,
            'manual_sync' => $order->manualSync ? [
                'status' => $order->manualSync->website_sync_status->value,
                'synced_at' => $order->manualSync->synced_at?->toIso8601String(),
                'synced_by' => $order->manualSync->synced_by,
                'sync_note' => $order->manualSync->sync_note,
            ] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function fulfillmentData(Order $order): array
    {
        $originalChildren = $order->fulfillmentChildren
            ->filter(fn (Order $child): bool => (string) ($child->fulfillment_role ?? '') !== 'replacement')
            ->sortBy('fulfillment_suffix')->values();
        $replacements = $order->fulfillmentChildren
            ->filter(fn (Order $child): bool => (string) ($child->fulfillment_role ?? '') === 'replacement')
            ->sortBy(fn (Order $child): int => preg_match('/^R(\d+)$/', (string) $child->fulfillment_suffix, $m) ? (int) $m[1] : PHP_INT_MAX)
            ->values();
        $isSplit = $originalChildren->isNotEmpty();
        $originalSegments = $isSplit ? $originalChildren : collect([$order]);
        $allSegments = $originalSegments->concat($replacements)->values();
        $startedOriginalIds = $originalSegments->filter(fn (Order $segment): bool => $this->segmentLogisticsStarted($segment))->pluck('id')->all();
        $aggregate = $this->shipmentCompletion->aggregateForOrder($order);
        $fulfilledCount = $originalSegments->filter(
            fn (Order $segment): bool => $segment->shipment !== null && $this->shipmentCompletion->isFulfillmentComplete($segment->shipment)
        )->count();
        $hasException = $allSegments->contains(
            fn (Order $segment): bool => $segment->shipment?->shipment_status === 'exception'
        );

        return [
            'is_split' => $isSplit,
            'aggregate_status' => $aggregate,
            'aggregate_status_label' => $this->fulfillmentAggregateLabel($aggregate),
            'segment_count' => $allSegments->count(),
            'original_segment_count' => $originalSegments->count(),
            'replacement_count' => $replacements->count(),
            'fulfilled_count' => $fulfilledCount,
            'has_exception' => $hasException,
            'original_logistics_started' => $this->originalLogisticsStarted($order),
            'segments' => $allSegments->map(fn (Order $segment): array => [
                'order_id' => (int) $segment->getKey(),
                'erp_order_id' => $segment->erp_order_id,
                'suffix' => $segment->fulfillment_suffix,
                'role' => (string) ($segment->fulfillment_role ?? '') === 'replacement' ? 'replacement' : 'original',
                'role_label' => (string) ($segment->fulfillment_role ?? '') === 'replacement' ? '售后补发' : '原始履约',
                'reason' => $segment->fulfillment_reason,
                'reason_label' => $this->replacementReasonLabel($segment->fulfillment_reason),
                'origin_fulfillment_id' => $segment->fulfillment_origin_id,
                'origin_erp_order_id' => $segment->fulfillmentOrigin?->erp_order_id,
                'can_reallocate' => $isSplit
                    && (string) ($segment->fulfillment_role ?? '') !== 'replacement'
                    && ! $this->segmentLogisticsStarted($segment)
                    && collect($startedOriginalIds)->contains(fn ($id): bool => (int) $id !== (int) $segment->getKey()),
                'items' => $segment->items->map(fn ($item): array => [
                    'id' => (int) $item->id,
                    'sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                    'product_summary' => $item->product_summary,
                    'quantity' => (int) $item->quantity,
                ])->values()->all(),
                'shipment' => $segment->shipment ? [
                    ...$segment->shipment->only(['shipment_status', 'warehouse_id', 'carrier', 'service', 'tracking_number', 'shipped_at', 'logistics_note']),
                    'status_label' => $this->fulfillmentSegmentStatusLabel(
                        $segment->shipment->shipment_status,
                        $segment->shipment->tracking_number,
                    ),
                    'warehouse_name' => $segment->shipment->warehouse?->name,
                ] : [
                    'shipment_status' => 'pending',
                    'warehouse_id' => null,
                    'carrier' => null,
                    'service' => null,
                    'tracking_number' => null,
                    'shipped_at' => null,
                    'logistics_note' => null,
                    'status_label' => '待发货',
                    'warehouse_name' => null,
                ],
            ])->all(),
        ];
    }

    private function originalLogisticsStarted(Order $order): bool
    {
        $originalChildren = $order->fulfillmentChildren
            ->filter(fn (Order $child): bool => (string) ($child->fulfillment_role ?? '') !== 'replacement')->values();
        $segments = $originalChildren->isNotEmpty() ? $originalChildren : collect([$order]);

        return $segments->contains(fn (Order $segment): bool => $this->segmentLogisticsStarted($segment));
    }

    private function segmentLogisticsStarted(Order $segment): bool
    {
        $shipment = $segment->shipment;
        if (! $shipment) {
            return false;
        }

        return $shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null;
    }

    private function replacementReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'lost' => '物流丢件',
            'damaged' => '产品损坏',
            'after_sales' => '售后换新',
            'maintenance' => '维护补件',
            'missing_item' => '少发漏发',
            'other' => '其他',
            default => null,
        };
    }

    private function fulfillmentAggregateLabel(string $status): string
    {
        return match ($status) {
            ShipmentCompletionSemantics::PartiallyShipped => '部分发货',
            ShipmentCompletionSemantics::Completed => '已发货',
            ShipmentCompletionSemantics::PartiallyDelivered => '部分送达',
            ShipmentCompletionSemantics::CompletedDelivered => '已完结',
            default => '待发货',
        };
    }

    private function fulfillmentSegmentStatusLabel(?string $status, ?string $trackingNumber): string
    {
        return match ($status) {
            'shipped' => filled($trackingNumber) ? '运输中' : '已发货待单号',
            'exception' => '异常',
            'completed' => '已完结',
            'cancelled' => '已取消',
            default => '待发货',
        };
    }

    private function rules(): array
    {
        return [
            // order_kind/source_type/owner_id are forced from route context in store(); not client-settable.
            'external_order_number' => ['required', 'string', 'max:128', Rule::unique('orders', 'external_order_number')],
            'order_date' => ['required', 'date'],
            'order_note' => ['nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'recipient_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:128'], 'province_state' => ['nullable', 'string', 'max:128'],
            'city' => ['nullable', 'string', 'max:128'], 'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'], 'postal_code' => ['nullable', 'string', 'max:32'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_summary' => ['nullable', 'string', 'max:255'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNotNull('sku')->where('sku', '!=', '')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'decimal:0,2', 'min:0'],
        ];
    }

    private function updateRules(Order $order): array
    {
        return [
            // external_order_number and owner_id are immutable via the generic update path once saved (audit-preserving).
            'order_kind' => ['sometimes', Rule::in(array_column(OrderKind::cases(), 'value'))],
            'source_type' => ['sometimes', Rule::in(array_column(OrderSourceType::cases(), 'value'))],
            'order_date' => ['sometimes', 'date'],
            'order_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country' => ['sometimes', 'nullable', 'string', 'max:128'], 'province_state' => ['sometimes', 'nullable', 'string', 'max:128'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'], 'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'], 'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'items' => ['sometimes', 'array', 'min:1'], 'items.*.id' => ['sometimes', 'nullable', 'integer', Rule::exists('order_items', 'id')->where('order_id', $order->getKey())],
            'items.*.product_summary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.product_id' => ['required_with:items', 'integer', Rule::exists('products', 'id')->whereNotNull('sku')->where('sku', '!=', '')],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'decimal:0,2', 'min:0'],
        ];
    }

    private function sourceLabel(OrderSourceType $source): string
    {
        return match ($source) {
            OrderSourceType::Website => 'Website',
            OrderSourceType::Kol => 'KOL',
            OrderSourceType::ManualOther => 'Manual Other',
            OrderSourceType::Manual => '手动创建',
            OrderSourceType::WooCommerce => 'WooCommerce',
        };
    }

    private function kindLabel(OrderKind $kind): string
    {
        return match ($kind) {
            OrderKind::Customer => '客户订单', OrderKind::KolFulfillment => '网红履约订单', OrderKind::FulfillmentDerived => '履约衍生订单'
        };
    }

    private function statusLabel(string $status): string
    {
        return ['pending_shipment' => '待发货', 'shipped' => '已发货', 'completed' => '已完结', 'completed_delivered' => '完结&送达', 'cancelled' => '已取消'][$status] ?? $status;
    }
}
