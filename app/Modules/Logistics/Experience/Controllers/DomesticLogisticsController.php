<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Domestic\DomesticPendingBatchExporter;
use App\Modules\Logistics\Application\Domestic\DomesticTrackingReturnService;
use App\Modules\Logistics\Application\Domestic\SpeedaOutboundExporter;
use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

final class DomesticLogisticsController
{
    private const REVIEW_MARKER = 'domestic_reviewed';

    private const VIEWS = ['review', 'pending', 'today-4px', 'today-weship', 'exceptions', 'today', '4px', 'weship'];

    public function __construct(
        private readonly SpeedaOutboundExporter $speeda,
        private readonly DomesticPendingBatchExporter $pendingBatch,
        private readonly DomesticTrackingReturnService $tracking,
        private readonly WarehouseOrderReference $references,
    ) {}

    public function index(Request $request): Response
    {
        $factory = $this->factory();
        $view = in_array((string) $request->query('view', 'review'), self::VIEWS, true)
            ? (string) $request->query('view', 'review')
            : 'review';

        $query = $this->baseQuery((int) $factory->id)->with([
            'address',
            'items.product',
            'shipment.warehouse',
            'operationalMarkers',
        ]);

        $this->applyView($query, $view);
        $rows = $query->orderBy('order_date')->orderBy('id')->paginate(50)->withQueryString();
        $rows->through(fn (Order $order, int $index): array => $this->row($order, (($rows->currentPage() - 1) * $rows->perPage()) + $index + 1));

        $counts = [];
        foreach (self::VIEWS as $key) {
            $countQuery = $this->baseQuery((int) $factory->id);
            $this->applyView($countQuery, $key);
            $counts[$key] = $countQuery->count();
        }

        $previewProvider = session('domestic_tracking_preview_provider');
        $preview = $previewProvider ? session("domestic_tracking_preview_{$previewProvider}") : null;

        return Inertia::render('Logistics/Domestic/Index', [
            'view' => $view,
            'rows' => $rows,
            'viewCounts' => $counts,
            'canOperate' => $request->user()?->can('logistics.operate') ?? false,
            'trackingImportProvider' => $previewProvider,
            'trackingImportPreview' => $preview ? [
                'token' => $preview['token'],
                'rows' => $preview['rows'],
                'apply_count' => $preview['apply_count'],
                'noop_count' => $preview['noop_count'],
                'blocked_count' => $preview['blocked_count'],
            ] : null,
            'providerStatus' => [
                '4px' => ['export_ready' => true, 'return_ready' => true, 'label' => '速达 / 4PX'],
                'weship' => ['export_ready' => true, 'return_ready' => true, 'label' => '纬狮 / Weship', 'note' => '与速达共用 24 列下单模板 · XLSX 下载 · XLS/XLSX 回传'],
            ],
        ]);
    }

    public function approve(Request $request, Order $order): RedirectResponse
    {
        $this->assertDomesticPending($order);
        $order->operationalMarkers()->updateOrCreate(
            ['marker' => self::REVIEW_MARKER],
            ['marked_by' => $request->user()?->getAuthIdentifier(), 'marked_at' => now()],
        );

        return back()->with('success', '国内发货审核已通过。');
    }

    public function reopenReview(Order $order): RedirectResponse
    {
        $this->assertDomesticPending($order);
        $order->operationalMarkers()->where('marker', self::REVIEW_MARKER)->delete();

        return back()->with('success', '订单已退回待审核。');
    }

    public function export(string $provider): HttpResponse
    {
        $provider = $this->provider($provider);
        $carrier = $provider === '4px' ? '4px' : 'weship';
        $label = $this->providerLabel($provider);

        $factory = $this->factory();
        $orders = $this->baseQuery((int) $factory->id)
            ->with(['address', 'items.product', 'externalReferences', 'operationalMarkers'])
            ->whereHas('operationalMarkers', fn ($q) => $q->where('marker', self::REVIEW_MARKER))
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending')->whereRaw('LOWER(carrier) = ?', [$carrier]))
            ->orderBy('order_date')
            ->orderBy('id')
            ->get();

        try {
            $result = $this->speeda->exportReady($orders, $label);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="007-'.$provider.'-orders-'.now()->format('ymd').'.xlsx"',
            'Cache-Control' => 'no-store, private',
            'X-BYL-Exported-Orders' => (string) $result['exported_count'],
            'X-BYL-Blocked-Orders' => (string) count($result['blocked']),
        ]);
    }

    public function exportPendingBatch(): HttpResponse
    {
        $factory = $this->factory();
        $orders = $this->baseQuery((int) $factory->id)
            ->with(['address', 'items.product', 'shipment', 'externalReferences', 'operationalMarkers'])
            ->whereHas('operationalMarkers', fn ($q) => $q->where('marker', self::REVIEW_MARKER))
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
            ->orderBy('order_date')
            ->orderBy('id')
            ->get();

        try {
            $bytes = $this->pendingBatch->export($orders);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="007-domestic-pending-'.now()->format('ymd').'.xlsx"',
            'Cache-Control' => 'no-store, private',
            'X-BYL-Exported-Orders' => (string) $orders->count(),
        ]);
    }

    public function previewPendingBatch(Request $request): RedirectResponse
    {
        return $this->previewTrackingReturn($request, 'generic');
    }

    public function applyPendingBatch(Request $request): RedirectResponse
    {
        return $this->applyTrackingReturn($request, 'generic');
    }

    public function previewTrackingReturn(Request $request, string $provider): RedirectResponse
    {
        $provider = $this->provider($provider);
        $data = $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $preview = $this->tracking->preview($provider, $data['file']);

        session()->put("domestic_tracking_preview_{$provider}", $preview);
        session()->put('domestic_tracking_preview_provider', $provider);

        return redirect('/logistics/china?view=pending')->with('success', "已生成{$this->providerLabel($provider)}回传预览；确认前未写业务数据。");
    }

    public function applyTrackingReturn(Request $request, string $provider): RedirectResponse
    {
        $provider = $this->provider($provider);
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $key = "domestic_tracking_preview_{$provider}";
        $stored = session($key);
        abort_unless(is_array($stored), 409, '没有可应用的国内物流回传预览。');

        $result = $this->tracking->apply($provider, $stored, $data['token'], $request->user());
        session()->forget([$key, 'domestic_tracking_preview_provider']);

        $message = sprintf(
            '%s回传完成：成功 %d，重复 %d，阻断 %d，执行失败 %d。阻断/失败订单不会影响其他正常订单。',
            $this->providerLabel($provider),
            $result['applied_count'],
            $result['noop_count'],
            $result['blocked_count'],
            $result['failed_count'],
        );

        return redirect('/logistics/china?view=today')->with('success', $message);
    }

    private function baseQuery(int $factoryId): Builder
    {
        return Order::query()
            ->where('order_status', '!=', 'cancelled')
            ->whereDoesntHave('fulfillmentChildren')
            ->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))
            ->whereHas('shipment', fn ($q) => $q->where('warehouse_id', $factoryId));
    }

    private function applyView(Builder $query, string $view): void
    {
        match ($view) {
            'review' => $query
                ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
                ->whereDoesntHave('operationalMarkers', fn ($q) => $q->where('marker', self::REVIEW_MARKER)),
            'pending' => $query
                ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
                ->whereHas('operationalMarkers', fn ($q) => $q->where('marker', self::REVIEW_MARKER)),
            'today-4px' => $this->applyTodayProviderView($query, '4px'),
            'today-weship' => $this->applyTodayProviderView($query, 'weship'),
            'exceptions' => $query->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'exception')),
            'today' => $this->applyTodayView($query),
            '4px' => $query->whereHas('shipment', fn ($q) => $q->whereRaw('LOWER(carrier) = ?', ['4px'])),
            'weship' => $query->whereHas('shipment', fn ($q) => $q->whereRaw('LOWER(carrier) = ?', ['weship'])),
            default => null,
        };
    }

    private function applyTodayProviderView(Builder $query, string $provider): Builder
    {
        return $query->where(function (Builder $daily) use ($provider): void {
            $daily->where(function (Builder $pending) use ($provider): void {
                $pending
                    ->whereHas('shipment', fn ($q) => $q
                        ->where('shipment_status', 'pending')
                        ->whereRaw('LOWER(carrier) = ?', [$provider]))
                    ->whereHas('operationalMarkers', fn ($q) => $q->where('marker', self::REVIEW_MARKER));
            })->orWhereHas('shipment', fn ($q) => $q
                ->whereDate('shipped_at', now()->toDateString())
                ->whereRaw('LOWER(carrier) = ?', [$provider]));
        });
    }

    private function applyTodayView(Builder $query): Builder
    {
        return $query->where(function (Builder $daily): void {
            $daily->where(function (Builder $pending): void {
                $pending
                    ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
                    ->whereHas('operationalMarkers', fn ($q) => $q->where('marker', self::REVIEW_MARKER));
            })->orWhereHas('shipment', fn ($q) => $q->whereDate('shipped_at', now()->toDateString()));
        });
    }

    private function row(Order $order, int $line): array
    {
        $shipment = $order->shipment;
        $approved = $order->operationalMarkers->contains('marker', self::REVIEW_MARKER);
        $reference = null;
        try {
            $reference = $this->references->warehouse($order);
        } catch (\DomainException) {
        }

        return [
            'line' => $line,
            'order_id' => (int) $order->id,
            'erp_order_id' => $order->erp_order_id,
            'provider_reference' => $reference,
            'order_url' => '/customers/orders/'.$order->id,
            'recipient_name' => $order->address?->recipient_name,
            'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'postal_code' => $order->address?->postal_code,
            'carrier' => $shipment?->carrier,
            'service' => $shipment?->service,
            'tracking_number' => $shipment?->tracking_number,
            'shipment_status' => $shipment?->shipment_status,
            'shipped_at' => $shipment?->shipped_at?->toIso8601String(),
            'review_status' => $approved ? 'approved' : 'pending',
            'export_blockers' => in_array(strtolower((string) $shipment?->carrier), ['4px', 'weship'], true)
                ? $this->speeda->blockers($order)
                : [],
            'items' => $order->items->map(fn ($item) => [
                'sku' => $item->sku,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }

    private function assertDomesticPending(Order $order): void
    {
        $factory = $this->factory();
        $order->loadMissing(['shipment', 'externalReferences']);
        abort_if($order->externalReferences->contains('provider', 'historical_logistics'), 422, '历史物流证据订单不能进入当前国内发货。');
        abort_unless($order->shipment && (int) $order->shipment->warehouse_id === (int) $factory->id, 422, '订单不属于中国工厂。');
        abort_unless($order->shipment->shipment_status === 'pending', 422, '只有待发货订单可以审核。');
    }

    private function factory(): Warehouse
    {
        return Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])->where('is_active', true)->firstOrFail();
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        abort_unless(in_array($provider, ['4px', 'weship', 'generic'], true), 404);

        return $provider;
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            '4px' => '速达', 'weship' => '纬狮', default => '待发货批量'
        };
    }
}
