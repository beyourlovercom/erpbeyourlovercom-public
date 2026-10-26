<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\AssistedFulfillmentService;
use App\Modules\Logistics\Application\AutomaticAllocationEligibilityService;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\FulfillmentAllocationAccess;
use App\Modules\Logistics\Application\FulfillmentAllocationDecisionService;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Logistics\Application\GiftSkuResolutionService;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\PendingFulfillmentReallocationService;
use App\Modules\Logistics\Application\ReplacementFulfillmentService;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Application\UsFulfillmentZoneResolver;
use App\Modules\Logistics\Application\WarehouseRecommendationService;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderPackagingReferenceProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;

final class LogisticsController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ReplacementFulfillmentService $replacementFulfillments,
        private readonly PendingFulfillmentReallocationService $pendingReallocation,
        private readonly AutomaticAllocationEligibilityService $automaticAllocationEligibility,
        private readonly AssistedFulfillmentService $assistedFulfillment,
        private readonly WarehouseRecommendationService $recommendations,
        private readonly FulfillmentPlanPreviewService $fulfillmentPlans,
        private readonly FulfillmentAllocationDecisionService $allocationDecisions,
        private readonly FulfillmentAllocationSubmissionService $allocationSubmissions,
        private readonly FulfillmentAllocationAccess $allocationAccess,
        private readonly GiftSkuResolutionService $giftSkuResolutions,
        private readonly LogisticsExecutionAccess $executionAccess,
        private readonly LogisticsStatusSummary $statusSummary,
        private readonly UsFulfillmentZoneResolver $usZones,
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly CarrierServiceCatalog $carrierServices,
        private readonly OrderPackagingReferenceProjection $packagingReferences,
    ) {}

    public function waitStock(Request $request): Response
    {
        $orders = $this->statusSummary->pendingAllocationQuery()
            ->with(['address', 'items.product', 'owner', 'shipment.warehouse', 'fulfillmentAllocation', 'externalReferences:id,order_id,provider'])
            ->latest('order_date')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $productIds = collect($orders->items())->flatMap(fn (Order $order) => $order->items->pluck('product_id'))->filter()->unique()->values()->all();
        $context = array_replace($this->recommendations->prepare($productIds), $this->fulfillmentPlans->prepare($productIds));
        $context['gift_resolution'] = $this->giftSkuResolutions->prepare();
        $activeReservations = InventoryReservation::query()
            ->whereIn('order_id', collect($orders->items())->pluck('id')->all())
            ->where('status', 'reserved')
            ->get()
            ->keyBy('order_id')
            ->all();
        $paymentDecisions = $this->automaticAllocationEligibility->preparePaymentDecisions($orders->items());
        $allowedCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $warehouses = collect($context['warehouses'] ?? [])
            ->filter(fn (array $warehouse): bool => in_array(strtoupper((string) ($warehouse['code'] ?? '')), $allowedCodes, true))
            ->values()->all();

        return Inertia::render('Logistics/FulfillmentAllocation', [
            'orders' => $orders->through(fn (Order $order): array => $this->data($order, $context, $activeReservations, $paymentDecisions))->toArray(),
            'warehouses' => $warehouses,
            'carrierOptions' => $this->carrierServices->options(),
            'pendingTotal' => $orders->total(),
            'canOperate' => $this->allocationAccess->allows($request->user()),
        ]);
    }

    public function submitRecommendedAllocation(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'plan_key' => ['required', 'string', 'max:128'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $allocation = $this->allocationSubmissions->submitRecommended($order, $data['plan_key'], $data['decision_note'] ?? null, $request->user());
        if ($request->expectsJson()) {
            return response()->json(['status' => $allocation->status, 'decision_source' => $allocation->decision_source, 'submitted_at' => $allocation->submitted_at?->toIso8601String()]);
        }

        return back()->with('success', '已采用系统推荐并提交分仓');
    }

    public function submitManualAllocation(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.order_item_id' => ['required', 'integer'],
            'assignments.*.source_code' => ['required', 'string', 'max:32'],
            'assignments.*.gift_mapping_id' => ['nullable', 'integer'],
            'assignments.*.carrier' => ['nullable', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'assignments.*.service' => ['nullable', 'string', 'max:191'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $allocation = $this->allocationSubmissions->submitManual($order, $data['assignments'], $data['decision_note'] ?? null, $request->user());
        if ($request->expectsJson()) {
            return response()->json(['status' => $allocation->status, 'decision_source' => $allocation->decision_source, 'submitted_at' => $allocation->submitted_at?->toIso8601String()]);
        }

        return back()->with('success', '人工分仓方案已提交');
    }

    public function submitQuickAllocation(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.order_item_id' => ['required', 'integer'],
            'assignments.*.warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
        ]);

        $order->loadMissing(['address', 'items']);
        if ($order->items->contains(fn ($item): bool => strcasecmp(trim((string) $item->sku), 'gift') === 0)) {
            throw ValidationException::withMessages(['assignments' => 'gift 订单需要在“待分仓”中选择实际履约礼品 SKU。']);
        }

        $warehouseIds = collect($data['assignments'])->pluck('warehouse_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $warehouses = Warehouse::query()->whereIn('id', $warehouseIds)->where('is_active', true)->get()->keyBy('id');
        $allowedCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $normalized = collect($data['assignments'])->map(function (array $assignment) use ($order, $warehouses, $allowedCodes): array {
            $warehouse = $warehouses->get((int) $assignment['warehouse_id']);
            if (! $warehouse || ! in_array(strtoupper((string) $warehouse->code), $allowedCodes, true)) {
                throw ValidationException::withMessages(['assignments' => '所选仓库不属于待分仓履约仓库。']);
            }
            $route = $this->carrierRouting->warehouseRoute($order->address?->country, (string) $warehouse->code);
            $carrier = trim((string) ($route['carrier'] ?? ''));
            if ($carrier === '') {
                throw ValidationException::withMessages(['assignments' => "{$warehouse->name} 尚未配置默认承运商，请进入完整待分仓流程。"]);
            }

            return [
                'order_item_id' => (int) $assignment['order_item_id'],
                'source_code' => (string) $warehouse->code,
                'gift_mapping_id' => null,
                'carrier' => $carrier,
                'service' => isset($route['service']) && trim((string) $route['service']) !== '' ? trim((string) $route['service']) : null,
            ];
        })->values()->all();

        $this->allocationSubmissions->submitManual($order, $normalized, null, $request->user());

        return back()->with('success', '分仓已提交');
    }

    public function saveDraft(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate($this->draftRules(), $this->shipmentValidationMessages());
        try {
            $this->assistedFulfillment->saveDraft($order, $data, $request->user());
        } catch (HttpExceptionInterface $e) {
            $this->rethrowShipmentValidation($e);
        }

        return back()->with('success', '履约草稿已保存');
    }

    public function adoptRecommendation(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $result = $this->assistedFulfillment->adoptRecommendation($order, $request->user());

        if ($request->expectsJson()) {
            $shipment = $result->shipment;

            return response()->json([
                'warehouse_id' => $shipment?->warehouse_id,
                'warehouse_name' => $shipment?->warehouse?->name,
                'carrier' => $shipment?->carrier,
                'service' => $shipment?->service,
            ]);
        }

        return back()->with('success', '已采用系统推荐并预留库存');
    }

    public function awaitingTracking(Request $request): Response
    {
        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.logisticsReview', 'order.operationalMarkers', 'warehouse'])
            ->where('shipment_status', 'shipped')
            ->whereNotNull('shipped_at')
            ->whereNull('tracking_number')
            ->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))
            ->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/AwaitingTracking', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusSummary->statusBar(),
            'canOperate' => $this->executionAccess->allows($request->user()),
        ]);
    }

    public function inTransit(Request $request): Response
    {
        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.logisticsReview', 'warehouse'])
            ->where('shipment_status', 'shipped')
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))
            ->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/InTransit', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusSummary->statusBar(),
            'canOperate' => $this->executionAccess->allows($request->user()),
        ]);
    }

    public function exceptions(Request $request): Response
    {
        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.logisticsReview', 'warehouse'])
            ->where('shipment_status', 'exception')
            ->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))
            ->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/Exceptions', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusSummary->statusBar(),
            'canOperate' => $this->executionAccess->allows($request->user()),
        ]);
    }

    public function review(Request $request): Response
    {
        $reviews = LogisticsReview::query()->with(['order.address', 'order.items.product', 'order.shipment.warehouse'])
            ->where('status', 'pending')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('requested_at')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/Review', ['reviews' => $reviews->through(fn (LogisticsReview $review): array => [...$this->queueRow($review->order->shipment), 'review_id' => $review->id, 'reason' => $review->reason, 'trigger' => $review->trigger, 'requested_at' => $review->requested_at?->toIso8601String()])->toArray(), 'statusBar' => $this->statusSummary->statusBar(), 'canOperate' => $this->executionAccess->allows($request->user())]);
    }

    public function completed(Request $request): Response
    {
        return $this->projection($request, 'completed', 'Logistics/Completed', fn ($q) => $q->where('shipment_status', 'completed'));
    }

    public function cancelled(Request $request): Response
    {
        $orders = Order::query()->with(['address', 'items.product', 'shipment.warehouse'])->where('order_status', 'cancelled')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->latest('order_date')->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/Cancelled', ['orders' => $orders->through(fn (Order $order) => $this->orderProjection($order))->toArray(), 'statusBar' => $this->statusSummary->statusBar()]);
    }

    public function decideReview(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['destination' => ['required', 'string'], 'decision_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->decideReview($order, $data['destination'], $data['decision_note'], $request->user());

        return back()->with('success', '复核已处理');
    }

    public function pause(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->pauseException($order, $data['logistics_note'], $request->user());

        return back()->with('success', '订单已暂停');
    }

    public function redispatch(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->requestReview($order, 'redispatch', $data['logistics_note'], $request->user());

        return back()->with('success', '重新派件已提交复核');
    }

    public function reship(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->reship($order, $data, $request->user());

        return back()->with('success', '已保留原始履约证据并重置待发货');
    }

    public function contactUser(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->contactUser($order, $data['logistics_note'], $request->user());

        return back()->with('success', '已记录联系用户');
    }

    public function cancelException(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->cancelException($order, $data['logistics_note'], $request->user());

        return back()->with('success', '订单已取消，未执行实际退款');
    }

    public function markCompleted(Request $request, Order $order): RedirectResponse
    {
        $this->shipments->markCompleted($order, $request->user());

        return back()->with('success', '物流已标记完结');
    }

    public function updateTracking(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:128']]);
        $this->shipments->updateTracking($order, $data['tracking_number'], $request->user());

        return back()->with('success', '追踪号已更新');
    }

    public function markException(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->markException($order, $data['logistics_note'], $request->user());

        return back()->with('success', '已标记物流异常');
    }

    public function resolveException(Request $request, Order $order): RedirectResponse
    {
        $this->shipments->resolveException($order, $request->user());

        return back()->with('success', '异常已解除，物流已恢复运输中');
    }

    public function markShipped(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'carrier' => ['required', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'service' => ['required', 'string', 'max:128'],
            'shipped_at' => ['required', 'date'],
            'tracking_number' => $this->trackingNumberRules(),
            'logistics_note' => ['nullable', 'string', 'max:5000'],
        ], $this->shipmentValidationMessages());
        try {
            $this->assistedFulfillment->markShipped($order, $data, $request->user());
        } catch (HttpExceptionInterface $e) {
            $this->rethrowShipmentValidation($e);
        }
        $msg = empty($data['tracking_number']) ? '已标记发货，追踪号待填入' : '已标记发货，已生成待同步任务';

        return back()->with('success', $msg);
    }

    public function reopen(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['nullable', 'string', 'max:5000']]);
        $this->shipments->reopen($order, $request->user(), $data['logistics_note'] ?? null);

        return back()->with('success', '发货已重开，可修正后重新发货');
    }

    public function reallocatePendingFulfillment(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $updated = $this->pendingReallocation->reallocate(
            $order,
            (int) $data['warehouse_id'],
            $request->user(),
            $data['note'] ?? null,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'reallocated',
                'order_id' => (int) $updated->getKey(),
                'suffix' => $updated->fulfillment_suffix,
                'warehouse_id' => $updated->shipment?->warehouse_id,
                'warehouse_name' => $updated->shipment?->warehouse?->name,
            ]);
        }

        return back()->with('success', '履约 '.$updated->fulfillment_suffix.' 已调整分仓');
    }

    public function createReplacementFulfillment(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_keys(ReplacementFulfillmentService::REASONS))],
            'origin_fulfillment_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'address' => ['nullable', 'array'],
            'address.recipient_name' => ['nullable', 'string', 'max:255'],
            'address.phone' => ['nullable', 'string', 'max:64'],
            'address.country' => ['nullable', 'string', 'max:128'],
            'address.province_state' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.address_line_1' => ['nullable', 'string', 'max:500'],
            'address.address_line_2' => ['nullable', 'string', 'max:500'],
            'address.postal_code' => ['nullable', 'string', 'max:64'],
        ]);
        $replacement = $this->replacementFulfillments->create(
            $order,
            $data['reason'],
            $data['items'],
            (int) $data['warehouse_id'],
            isset($data['origin_fulfillment_id']) ? (int) $data['origin_fulfillment_id'] : null,
            $data['note'] ?? null,
            $data['address'] ?? null,
            $request->user(),
        );
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'created',
                'order_id' => (int) $replacement->getKey(),
                'erp_order_id' => $replacement->erp_order_id,
                'suffix' => $replacement->fulfillment_suffix,
            ], 201);
        }

        return back()->with('success', '补发履约单 '.$replacement->fulfillment_suffix.' 已创建');
    }

    public function payload(Order $order): JsonResponse
    {
        $order->load(['address', 'items', 'shipment.warehouse']);
        $packaging = $this->packagingReferences->forOrder($order);

        return response()->json([
            'order_number' => $order->erp_order_id,
            'source_order_number' => $order->external_order_number,
            'erp_order_id' => $order->erp_order_id,
            'recipient_name' => $order->address?->recipient_name,
            'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'province_state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'address_line_1' => $order->address?->address_line_1,
            'address_line_2' => $order->address?->address_line_2,
            'postal_code' => $order->address?->postal_code,
            'items' => $order->items->map(fn ($item): array => [
                ...$item->only(['product_summary', 'sku', 'quantity', 'unit_price']),
                'packaging_reference' => $packaging[(int) $item->id] ?? null,
            ])->values(),
            'warehouse' => $order->shipment?->warehouse?->name,
            'carrier' => $order->shipment?->carrier,
            'service' => $order->shipment?->service,
            'tracking_number' => $order->shipment?->tracking_number,
            'shipped_at' => $order->shipment?->shipped_at?->toIso8601String(),
        ]);
    }

    private function queueRow(Shipment $shipment): array
    {
        $order = $shipment->order;
        $order?->loadMissing('operationalMarkers');

        return [
            'shipment_id' => $shipment->id,
            'order_id' => $order?->id,
            'external_order_number' => $order?->external_order_number,
            'erp_order_id' => $order?->erp_order_id,
            'recipient_name' => $order?->address?->recipient_name,
            'country' => $order?->address?->country,
            'warehouse_name' => $shipment->warehouse?->name,
            'carrier' => $shipment->carrier,
            'service' => $shipment->service,
            'tracking_number' => $shipment->tracking_number,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'logistics_note' => $shipment->logistics_note,
            'shipment_status' => $shipment->shipment_status,
            'order_url' => $this->orderUrl($order),
            'items' => $order?->items->map(fn ($item): array => ['sku' => $item->resolved_fulfillment_sku ?: $item->sku, 'source_sku' => $item->source_sku ?: $item->sku, 'product_summary' => $item->product_summary, 'product_id' => $item->product_id, 'product_url' => $item->product ? "/products/{$item->product_id}" : null, 'quantity' => $item->quantity])->values()->all() ?? [],
            'review_pending' => $order?->logisticsReview?->status === 'pending',
            'paused' => $order?->operationalMarkers?->contains('marker', 'logistics_paused') ?? false,
        ];
    }

    private function projection(Request $request, string $kind, string $component, \Closure $filter): Response
    {
        $shipments = Shipment::query()->with(['order.address', 'order.items.product', 'order.logisticsReview', 'warehouse'])->where($filter)->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render($component, ['shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(), 'statusBar' => $this->statusSummary->statusBar()]);
    }

    private function orderProjection(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_id' => $order->id,
            'order_number' => $order->erp_order_id,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'order_date' => $order->order_date?->toDateString(),
            'recipient_name' => $order->address?->recipient_name,
            'country' => $order->address?->country,
            'order_url' => $this->orderUrl($order),
            'items' => $order->items->map(fn ($item) => [
                'sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                'source_sku' => $item->source_sku ?: $item->sku,
                'product_summary' => $item->product_summary,
                'product_id' => $item->product_id,
                'product_url' => $item->product ? "/products/{$item->product_id}" : null,
                'quantity' => (int) $item->quantity,
            ])->all(),
        ];
    }

    private function orderUrl(?Order $order): ?string
    {
        if (! $order || $order->order_kind?->value === 'fulfillment_derived') {
            return null;
        }

        return $order->order_kind?->value === 'kol_fulfillment' ? "/kol/orders/{$order->id}" : "/customers/orders/{$order->id}";
    }

    /** Keep user-facing Tracking rules aligned with ShipmentService::canonicalTrackingNumber(). */
    private function trackingNumberRules(): array
    {
        return [
            'nullable', 'string', 'max:128',
            static function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && trim((string) $value) !== ''
                    && preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._:\\/+\\-]*\\z/', trim((string) $value)) !== 1) {
                    $fail('Tracking 只能填写英文字母、数字及 . _ : / + -，不能包含中文、空格或备注。');
                }
            },
        ];
    }

    private function shipmentValidationMessages(): array
    {
        return [
            'tracking_number.max' => 'Tracking 最长 128 个字符。',
            'warehouse_id.required' => '请选择发货仓库。',
            'carrier.required' => '请选择物流商。',
            'service.required' => '请选择运输 / 服务类型。',
            'shipped_at.required' => '请选择发货时间。',
            'shipped_at.date' => '发货时间格式不正确。',
            'logistics_note.max' => '物流备注不能超过 5000 个字符。',
        ];
    }

    /** Map actionable business 422 failures into Inertia field errors instead of a full error page. */
    private function rethrowShipmentValidation(HttpExceptionInterface $e): never
    {
        if ($e->getStatusCode() === 422) {
            $reason = trim($e->getMessage()) ?: '请检查物流字段后重新提交。';
            $field = str_contains($reason, '追踪号') || str_contains($reason, 'Tracking')
                ? 'tracking_number' : 'fulfillment';
            throw ValidationException::withMessages([$field => $reason]);
        }
        throw $e;
    }

    private function draftRules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'carrier' => ['nullable', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'service' => ['nullable', 'string', 'max:128'],
            'tracking_number' => $this->trackingNumberRules(),
            'shipped_at' => ['nullable', 'date'],
            'logistics_note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function data(
        Order $order,
        array $context,
        array $activeReservationsByOrderId = [],
        array $paymentDecisionsByOrderId = [],
    ): array {
        $destinationContext = [
            'province_state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'address_line_1' => $order->address?->address_line_1,
            'address_line_2' => $order->address?->address_line_2,
            'postal_code' => $order->address?->postal_code,
        ];
        $planItems = $order->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'quantity' => (int) $item->quantity,
            'sku' => $item->sku,
        ])->values()->all();
        $recommendation = $this->recommendationFor($order, $context, $destinationContext, $planItems);
        $fulfillmentPlanPreview = $this->fulfillmentPlans->preview(
            $order->address?->country,
            $planItems,
            $destinationContext,
            [],
            $context,
        );

        $allocationDecision = $this->allocationDecisions->present($order, $fulfillmentPlanPreview);
        $allocation = $order->relationLoaded('fulfillmentAllocation') ? $order->fulfillmentAllocation : null;
        $usZone = strtoupper((string) $order->address?->country) === 'US'
            ? $this->usZones->resolve($order->address?->postal_code, $destinationContext)
            : null;
        $carrierRoutes = collect((array) config('warehouse_master.fulfillment_codes', []))->mapWithKeys(function (string $code) use ($order): array {
            $route = $this->carrierRouting->warehouseRoute($order->address?->country, $code);
            $carrier = trim((string) ($route['carrier'] ?? ''));

            return [$code => [
                'carrier' => $carrier !== '' ? $carrier : null,
                'carrier_label' => $carrier !== '' ? $this->carrierRouting->displayName($carrier) : null,
                'service' => isset($route['service']) && trim((string) $route['service']) !== '' ? trim((string) $route['service']) : null,
                'automatic' => $carrier !== '',
            ]];
        })->all();

        return [
            'id' => $order->id,
            'external_order_number' => $order->external_order_number,
            'order_url' => $this->orderUrl($order),
            'erp_order_id' => $order->erp_order_id,
            'internal_order_number' => $order->internal_order_number,
            'order_kind' => $order->order_kind?->value,
            'order_kind_label' => match ($order->order_kind) {
                OrderKind::Customer => '客户订单',
                OrderKind::KolFulfillment => '网红履约订单',
                OrderKind::FulfillmentDerived => '履约衍生订单',
                null => '未分类',
            },
            'recipient_name' => $order->address?->recipient_name,
            'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'province_state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'address_line_1' => $order->address?->address_line_1,
            'address_line_2' => $order->address?->address_line_2,
            'postal_code' => $order->address?->postal_code,
            'us_fulfillment_zone' => $usZone,
            'carrier_routes' => $carrierRoutes,
            'owner_label' => $order->owner ? 'Owner('.$order->owner->name.')' : null,
            'item_count' => $order->items->sum('quantity'),
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_url' => $item->product ? "/products/{$item->product_id}" : null,
                'sku' => $item->sku,
                'source_sku' => $item->source_sku ?: $item->sku,
                'resolved_fulfillment_sku' => $item->resolved_fulfillment_sku,
                'product_summary' => $item->product_summary,
                'quantity' => $item->quantity,
                'availability' => $context['availability'][$item->product_id] ?? [],
                'is_gift' => $this->giftSkuResolutions->isGiftSku($item->sku),
                'gift_options_by_warehouse' => array_key_exists('gift_resolution', $context)
                    ? $this->giftSkuResolutions->options((string) $item->sku, (int) $item->quantity, $context['gift_resolution'])
                    : [],
            ])->values()->all(),
            'warehouse_recommendation' => $recommendation,
            'fulfillment_plan_preview' => $fulfillmentPlanPreview,
            'allocation_decision' => $allocationDecision,
            'fulfillment_allocation' => $allocation ? [
                'status' => $allocation->status,
                'decision_source' => $allocation->decision_source,
                'selected_plan_key' => $allocation->selected_plan_key,
                'decision_note' => $allocation->decision_note,
                'plan_snapshot' => $allocation->plan_snapshot,
                'submitted_at' => $allocation->submitted_at?->toIso8601String(),
            ] : null,
            'automatic_allocation' => $this->automaticAllocationEligibility->evaluate(
                $order,
                $recommendation,
                $activeReservationsByOrderId,
                $paymentDecisionsByOrderId[$order->id] ?? null,
            ),
            'shipment' => $order->shipment ? [
                ...$order->shipment->only(['warehouse_id', 'warehouse_selection_source', 'carrier', 'service', 'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note']),
                'warehouse_name' => $order->shipment->warehouse?->name,
            ] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function recommendationFor(Order $order, array $context, array $destinationContext, array $items): array
    {
        try {
            return $this->recommendations->recommend(
                $order->address?->country,
                $items,
                $order->address?->postal_code,
                [],
                $destinationContext,
                $context,
            );
        } catch (QueryException|PDOException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('erp.logistics.recommendation_row_failed', [
                'order_id' => $order->id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return [
                'status' => 'blocked',
                'reason_code' => 'recommendation_failed',
                'reason_label' => '该订单推荐计算暂时失败，请人工处理。',
                'country_code' => $order->address?->country,
                'required_sku_count' => null,
                'candidate_count' => 0,
                'warehouse' => null,
                'routing_context' => null,
            ];
        }
    }
}
