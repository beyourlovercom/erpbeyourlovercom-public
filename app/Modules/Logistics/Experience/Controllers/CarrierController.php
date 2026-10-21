<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CarrierController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly CarrierServiceCatalog $carrierServices,
    ) {}

    public function index(Request $request): Response
    {
        $masters = Carrier::query()->orderBy('id')->get();
        $shipments = Shipment::query()
            ->with('billingEvidence')
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->whereIn('carrier', $masters->pluck('name'))
            ->get()
            ->groupBy(fn (Shipment $shipment): string => trim((string) $shipment->carrier));

        $carriers = $masters->map(fn (Carrier $carrier): array => [
            'id' => $carrier->id,
            'name' => $carrier->name,
            'display_name' => $carrier->displayName(),
            'is_active' => $carrier->is_active,
            ...$this->summary($shipments->get($carrier->name, collect())),
        ])->values();

        return Inertia::render('Logistics/Carriers/Index', [
            'carriers' => $carriers,
            'canOperate' => (bool) $request->user()?->can('logistics.operate'),
            'statusBar' => $this->statusBar(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'unique:logistics_carriers,name'],
        ]);

        Carrier::query()->create([
            'name' => $validated['name'],
            'is_active' => true,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return redirect()->route('logistics.carriers')->with('success', '承运商已新增');
    }

    public function show(Request $request, string $carrier): Response
    {
        $carrier = trim($carrier);
        abort_if($carrier === '', 404);
        $master = Carrier::query()->with(['services' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'defaultService'])->where('name', $carrier)->firstOrFail();

        $summaryRows = Shipment::query()
            ->with('billingEvidence')
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->where('carrier', $carrier)
            ->get();

        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'warehouse', 'handler', 'billingEvidence', 'carrierService'])
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->where('carrier', $carrier)
            ->latest('shipped_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Logistics/Carriers/Show', [
            'carrier' => $carrier,
            'carrierDisplayName' => $master->displayName(),
            'carrierMaster' => $this->carrierMasterData($master),
            'canEditCarrier' => (bool) $request->user()?->can('logistics.operate'),
            'summary' => $this->summary($summaryRows),
            'shipments' => $shipments->through(fn (Shipment $shipment): array => $this->ledgerRow($shipment))->toArray(),
            'statusBar' => $this->statusBar(),
        ]);
    }

    public function update(Request $request, string $carrier): RedirectResponse
    {
        $master = Carrier::query()->with('services')->where('name', trim($carrier))->firstOrFail();
        $payload = $request->validate([
            'name_zh' => ['required', 'string', 'max:128'],
            'name_en' => ['required', 'string', 'max:128'],
            'is_active' => ['required', 'boolean'],
            'default_service_id' => ['nullable', 'integer'],
            'default_service_key' => ['nullable', 'string', 'max:80'],
            'services' => ['required', 'array', 'min:1', 'max:50'],
            'services.*.id' => ['nullable', 'integer'],
            'services.*.service_key' => ['required', 'string', 'max:80'],
            'services.*.service_value' => ['required', 'string', 'max:128'],
            'services.*.name_en' => ['nullable', 'string', 'max:128'],
            'services.*.name_zh' => ['nullable', 'string', 'max:128'],
            'services.*.is_active' => ['required', 'boolean'],
            'services.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $actorId = $request->user()?->getAuthIdentifier();
        DB::transaction(function () use ($master, $payload, $actorId): void {
            $seenKeys = [];
            $seenValues = [];
            $saved = [];
            foreach ($payload['services'] as $row) {
                $key = trim((string) $row['service_key']);
                $value = trim((string) $row['service_value']);
                abort_if(isset($seenKeys[strtolower($key)]), 422, '运输 / 服务类型内部标识不能重复');
                abort_if(isset($seenValues[strtolower($value)]), 422, '运输 / 服务类型记录值不能重复');
                $seenKeys[strtolower($key)] = true;
                $seenValues[strtolower($value)] = true;

                $service = isset($row['id']) && $row['id']
                    ? $master->services()->whereKey((int) $row['id'])->firstOrFail()
                    : new CarrierService(['carrier_id' => $master->id, 'created_by' => $actorId]);
                if ($service->exists && $service->service_key !== $key) {
                    abort(422, '已存在的内部 Service 标识不可修改，可停用后新增');
                }
                $service->fill([
                    'carrier_id' => $master->id,
                    'service_key' => $key,
                    'service_value' => $value,
                    'name_en' => trim((string) ($row['name_en'] ?? '')) ?: null,
                    'name_zh' => trim((string) ($row['name_zh'] ?? '')) ?: null,
                    'is_active' => (bool) $row['is_active'],
                    'sort_order' => (int) $row['sort_order'],
                    'updated_by' => $actorId,
                ])->save();
                $saved[] = $service;
            }

            $defaultKey = trim((string) ($payload['default_service_key'] ?? ''));
            $defaultId = (int) ($payload['default_service_id'] ?? 0);
            $default = collect($saved)->first(function (CarrierService $service) use ($defaultKey, $defaultId): bool {
                if ($defaultKey !== '') {
                    return $service->service_key === $defaultKey;
                }

                return (int) $service->id === $defaultId;
            });
            abort_unless($default && $default->is_active, 422, '默认运输 / 服务类型必须是已启用选项');

            $master->forceFill([
                'name_zh' => trim((string) $payload['name_zh']),
                'name_en' => trim((string) $payload['name_en']),
                'is_active' => (bool) $payload['is_active'],
                'default_service_id' => $default->id,
                'updated_by' => $actorId,
            ])->save();
        });

        return back()->with('success', '承运商配置已保存');
    }

    /** @return array<string,mixed> */
    private function carrierMasterData(Carrier $carrier): array
    {
        return [
            'id' => (int) $carrier->id,
            'name' => $carrier->name,
            'name_en' => $carrier->name_en,
            'name_zh' => $carrier->name_zh,
            'display_name' => $carrier->displayName(),
            'is_active' => (bool) $carrier->is_active,
            'default_service_id' => $carrier->default_service_id ? (int) $carrier->default_service_id : null,
            'services' => $carrier->services->map(fn (CarrierService $service): array => [
                'id' => (int) $service->id,
                'service_key' => $service->service_key,
                'service_value' => $service->service_value,
                'name_en' => $service->name_en,
                'name_zh' => $service->name_zh,
                'display_name' => $service->displayName(),
                'is_active' => (bool) $service->is_active,
                'sort_order' => (int) $service->sort_order,
            ])->values()->all(),
        ];
    }

    private function summary(Collection $shipments): array
    {
        $costs = [];
        $weight = 0.0;
        $weightRows = 0;
        $deliveryCoverage = 0;
        $delivery = ['in_transit' => 0, 'delivered' => 0, 'exception' => 0];

        foreach ($shipments as $shipment) {
            $billing = $shipment->billingEvidence;
            if ($billing?->chargeable_weight_kg !== null) {
                $weight += (float) $billing->chargeable_weight_kg;
                $weightRows++;
            }
            if ($billing?->actual_cost !== null) {
                $currency = strtoupper(trim((string) $billing->currency)) ?: 'UNSPECIFIED';
                $costs[$currency] ??= ['currency' => $currency, 'total' => 0.0, 'shipments' => 0];
                $costs[$currency]['total'] += (float) $billing->actual_cost;
                $costs[$currency]['shipments']++;
            }
            $status = strtolower(trim((string) ($billing?->delivery_status ?? '')));
            if ($status !== '') {
                $deliveryCoverage++;
                if (array_key_exists($status, $delivery)) {
                    $delivery[$status]++;
                }
            }
        }

        $costs = collect($costs)->map(function (array $cost): array {
            $cost['total'] = round($cost['total'], 4);
            $cost['average'] = $cost['shipments'] > 0 ? round($cost['total'] / $cost['shipments'], 4) : null;

            return $cost;
        })->values()->all();

        $lastShipped = $shipments->pluck('shipped_at')->filter()->sortDesc()->first();
        $thisMonth = $shipments->filter(fn (Shipment $shipment): bool => $shipment->shipped_at?->gte(now()->startOfMonth()) ?? false)->count();

        return [
            'shipment_count' => $shipments->count(),
            'chargeable_weight_kg' => $weightRows > 0 ? round($weight, 3) : null,
            'chargeable_weight_coverage' => $weightRows,
            'costs_by_currency' => $costs,
            'this_month_count' => $thisMonth,
            'delivery_status_coverage' => $deliveryCoverage,
            'in_transit_count' => $delivery['in_transit'],
            'delivered_count' => $delivery['delivered'],
            'exception_count' => $delivery['exception'],
            'last_shipped_at' => $lastShipped?->toIso8601String(),
        ];
    }

    private function ledgerRow(Shipment $shipment): array
    {
        $order = $shipment->order;
        $billing = $shipment->billingEvidence;

        return [
            'id' => $shipment->id,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'erp_order_id' => $order?->erp_order_id,
            'order_kind' => $order?->order_kind?->value,
            'order_url' => $this->orderUrl($order),
            'warehouse_name' => $shipment->warehouse?->name,
            'destination_country' => $order?->address?->country,
            'carrier' => $shipment->carrier,
            'service' => $shipment->service,
            'service_display_name' => $shipment->carrierService?->displayName() ?: $this->carrierServices->serviceDisplayName((string) $shipment->carrier, $shipment->service),
            'tracking_number' => $shipment->tracking_number,
            'items' => $order?->items->map(fn ($item): array => [
                'sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                'source_sku' => $item->source_sku ?: $item->sku,
                'product_summary' => $item->product_summary,
                'product_id' => $item->product_id,
                'product_url' => $item->product ? "/products/{$item->product_id}" : null,
                'quantity' => $item->quantity,
            ])->values()->all() ?? [],
            'chargeable_weight_kg' => $billing?->chargeable_weight_kg,
            'actual_cost' => $billing?->actual_cost,
            'currency' => $billing?->currency,
            'shipment_status' => $shipment->shipment_status,
            'delivery_status' => $billing?->delivery_status,
            'handler_name' => $shipment->handler?->name,
            'recipient_name' => $order?->address?->recipient_name,
            'billing_document_number' => $billing?->billing_document_number,
            'provider_reference' => $billing?->provider_reference,
            'data_source' => $billing?->data_source,
            'billed_at' => $billing?->billed_at?->toIso8601String(),
            'raw_evidence' => $billing?->raw_evidence,
        ];
    }

    private function orderUrl(?Order $order): ?string
    {
        if (! $order || $order->order_kind?->value === 'fulfillment_derived') {
            return null;
        }

        return $order->order_kind?->value === 'kol_fulfillment'
            ? "/kol/orders/{$order->id}"
            : "/customers/orders/{$order->id}";
    }

    private function statusBar(): array
    {
        return [
            ['key' => 'awaiting', 'label' => '已发货', 'url' => '/logistics/awaiting-tracking', 'count' => Shipment::query()->where('shipment_status', 'shipped')->whereNotNull('shipped_at')->where(fn ($q) => $q->whereNull('tracking_number')->orWhere('tracking_number', ''))->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'transit', 'label' => '运输中', 'url' => '/logistics/in-transit', 'count' => Shipment::query()->where('shipment_status', 'shipped')->whereNotNull('tracking_number')->where('tracking_number', '!=', '')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'exception', 'label' => '异常单', 'url' => '/logistics/exceptions', 'count' => Shipment::query()->where('shipment_status', 'exception')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'review', 'label' => '复核单', 'url' => '/logistics/review', 'count' => LogisticsReview::query()->where('status', 'pending')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'completed', 'label' => '已完结', 'url' => '/logistics/completed', 'count' => Shipment::query()->where('shipment_status', 'completed')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'cancelled', 'label' => '已取消', 'url' => '/logistics/cancelled', 'count' => Order::query()->where('order_status', 'cancelled')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->count()],
        ];
    }
}
