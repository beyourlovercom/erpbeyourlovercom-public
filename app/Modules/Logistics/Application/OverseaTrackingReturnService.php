<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\OverseaFiles\OverseaTrackingReturnFileReader;
use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class OverseaTrackingReturnService
{
    private const int MAX_ROWS = 500;

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly WooCommerceTrackingOutboundService $trackingOutbound,
        private readonly OverseaTrackingReturnFileReader $files,
        private readonly WarehouseOrderReference $references,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $warehouseKey, UploadedFile $file): array
    {
        try {
            $normalized = $this->files->read($warehouseKey, $file);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
        abort_if($normalized === [], 422, '单号表没有可处理的数据行。');
        abort_if(count($normalized) > self::MAX_ROWS, 422, '单次最多上传 500 行。');

        $plan = $this->plan($warehouseKey, $normalized);
        $token = hash('sha256', json_encode([$warehouseKey, $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [...$plan, 'token' => $token, 'normalized_rows' => $normalized];
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    public function apply(string $warehouseKey, array $stored, string $token, Authenticatable $actor): array
    {
        abort_unless(isset($stored['token'], $stored['normalized_rows']) && hash_equals((string) $stored['token'], $token), 409, '上传预览已失效，请重新上传。');
        $plan = $this->plan($warehouseKey, (array) $stored['normalized_rows']);
        abort_if((int) $plan['blocked_count'] > 0, 409, '当前数据已变化或预览仍有阻断项，请重新预览。');

        $applyRows = collect($plan['rows'])->where('status', 'apply')->values();
        $appliedIds = [];
        DB::transaction(function () use ($applyRows, $actor, &$appliedIds): void {
            foreach ($applyRows as $row) {
                $order = Order::query()->with(['shipment.overseaReview'])->findOrFail((int) $row['order_id']);
                $shipment = $order->shipment;
                $this->shipments->markShipped($order, [
                    'warehouse_id' => $shipment?->warehouse_id,
                    'carrier' => $row['carrier'],
                    'service' => $shipment?->service,
                    'tracking_number' => $row['tracking_number'],
                    'shipped_at' => $row['shipped_at'],
                    'logistics_note' => $shipment?->logistics_note,
                ], $actor);
                OverseaWarehouseReview::query()->where('order_id', $order->id)->update(['progress' => 'shipped']);
                $appliedIds[] = (int) $order->id;
            }
        });

        $sync = [];
        foreach ($appliedIds as $orderId) {
            $order = Order::query()->with(['shipment', 'items', 'externalReferences', 'fulfillmentParent.externalReferences'])->findOrFail($orderId);
            if (! $this->hasWooRoot($order)) {
                continue;
            }
            try {
                $result = $this->trackingOutbound->pushOne($order, $actor);
                $sync[] = ['order_id' => $orderId, 'status' => (string) ($result['status'] ?? 'failed')];
            } catch (DomainException $e) {
                $sync[] = ['order_id' => $orderId, 'status' => 'blocked', 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                $sync[] = ['order_id' => $orderId, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return [
            'applied_count' => count($appliedIds),
            'noop_count' => (int) $plan['noop_count'],
            'sync' => $sync,
        ];
    }

    /** @param list<array<string,mixed>> $normalized @return array<string,mixed> */
    private function plan(string $warehouseKey, array $normalized): array
    {
        $code = OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY[$warehouseKey] ?? null;
        abort_unless($code, 404, '未知海外仓。');
        $warehouse = Warehouse::query()->where('code', $code)->where('is_active', true)->firstOrFail();
        $activeCarriers = Carrier::query()->where('is_active', true)->pluck('name')->mapWithKeys(
            fn ($name) => [mb_strtolower(trim((string) $name)) => (string) $name]
        );
        $identityMap = $this->identityMap($warehouseKey, (int) $warehouse->id);

        $seen = [];
        $planned = [];
        foreach ($normalized as $row) {
            $number = trim((string) ($row['order_reference'] ?? ''));
            $tracking = trim((string) ($row['tracking_number'] ?? ''));
            $carrierInput = trim((string) ($row['carrier'] ?? ''));
            $messages = [];

            if (filled($row['parse_error'] ?? null)) {
                $messages[] = (string) $row['parse_error'];
            }
            if ((bool) ($row['cancelled'] ?? false)) {
                $messages[] = 'Provider 回传显示订单已取消，禁止标记发货';
            }
            if ($this->knownWarehouseMismatch($warehouseKey, $row['provider_warehouse'] ?? null)) {
                $messages[] = 'Provider 回传仓库与当前 ERP 仓库明确不一致';
            }
            if ($number === '') {
                $messages[] = '订单号为空';
            }
            if ($tracking === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]{0,127}\z/', $tracking) !== 1) {
                $messages[] = 'Tracking No. 无效';
            }
            $identityKey = $this->identityKey($number);
            if ($number !== '' && isset($seen[$identityKey])) {
                $messages[] = '同一文件订单号重复或包含多个包裹，当前 Shipment 无法安全自动合并';
            }
            if ($number !== '') {
                $seen[$identityKey] = true;
            }

            $matches = $identityMap[$identityKey] ?? [];
            $order = count($matches) === 1 ? $matches[0] : null;
            if (count($matches) > 1) {
                $messages[] = 'ERP 订单号匹配不唯一';
            } elseif (! $order) {
                $messages[] = 'ERP 找不到该订单号';
            }

            $shipment = $order?->shipment;
            $review = $shipment?->overseaReview;
            if ($order && ! $shipment) {
                $messages[] = '订单没有 Shipment';
            } elseif ($shipment) {
                if ((int) $shipment->warehouse_id !== (int) $warehouse->id) {
                    $messages[] = '订单不属于当前海外仓';
                }
                if ($review?->status !== 'approved' || (int) $review?->reviewed_warehouse_id !== (int) $warehouse->id) {
                    $messages[] = '订单尚未完成当前仓库审核';
                }
            }

            $carrier = $carrierInput !== ''
                ? $activeCarriers->get(mb_strtolower($carrierInput))
                : ($shipment?->carrier ?: (OverseaWarehouseReviewService::CARRIER_BY_CODE[$code] ?? null));
            if (! $carrier) {
                $messages[] = '承运商为空或未启用';
            }
            if ($carrierInput !== '' && ! $activeCarriers->has(mb_strtolower($carrierInput))) {
                $messages[] = '文件中的承运商未启用';
            }

            $providerShippedAt = $this->providerShippedAt($row['shipped_at'] ?? null);
            $effectiveShippedAt = $providerShippedAt ?? now()->utc()->toIso8601String();
            $shippedTimeSource = $providerShippedAt !== null ? 'provider' : 'apply';

            if ($shipment && $shipment->shipment_status === 'pending' && filled($shipment->tracking_number) && $shipment->tracking_number !== $tracking) {
                $messages[] = 'ERP 已有不同 Tracking，禁止覆盖';
            }

            $status = 'blocked';
            if ($messages === [] && $shipment) {
                if ($shipment->shipment_status === 'pending' && $order?->order_status?->value === 'pending_shipment') {
                    $status = 'apply';
                } elseif ($shipment->shipment_status === 'shipped') {
                    $sameCarrier = mb_strtolower((string) $shipment->carrier) === mb_strtolower((string) $carrier);
                    $sameTracking = $shipment->tracking_number === $tracking;
                    $sameTime = $providerShippedAt === null
                        || ($shipment->shipped_at && CarbonImmutable::parse($shipment->shipped_at)->equalTo(CarbonImmutable::parse($providerShippedAt)));
                    $status = $sameTracking && $sameCarrier && $sameTime ? 'noop' : 'blocked';
                    if ($status === 'blocked') {
                        $messages[] = '订单已经发货且现有履约事实与文件不一致';
                    }
                } else {
                    $messages[] = 'Shipment 当前状态不可由回传表发货';
                }
            }

            $planned[] = [
                'row' => (int) ($row['row'] ?? 0),
                'order_id' => $order?->id,
                'order_number' => $number,
                'tracking_number' => $tracking,
                'carrier' => $carrier,
                'shipped_at' => $effectiveShippedAt,
                'shipped_time_source' => $shippedTimeSource,
                'provider_order_reference' => $row['provider_order_reference'] ?? null,
                'provider_service' => $row['provider_service'] ?? null,
                'provider_status' => $row['provider_status'] ?? null,
                'provider_warehouse' => $row['provider_warehouse'] ?? null,
                'tracking_status' => $row['tracking_status'] ?? null,
                'package_tracking' => $row['package_tracking'] ?? null,
                'return_tracking' => $row['return_tracking'] ?? null,
                'cancellation_reason' => $row['cancellation_reason'] ?? null,
                'provider_evidence' => $row['provider_evidence'] ?? [],
                'status' => $status,
                'message' => implode('；', $messages),
            ];
        }

        return [
            'rows' => $planned,
            'apply_count' => collect($planned)->where('status', 'apply')->count(),
            'noop_count' => collect($planned)->where('status', 'noop')->count(),
            'blocked_count' => collect($planned)->where('status', 'blocked')->count(),
        ];
    }

    /** @return array<string,list<Order>> */
    private function identityMap(string $warehouseKey, int $warehouseId): array
    {
        $orders = Order::query()
            ->with(['shipment.overseaReview'])
            ->whereHas('shipment', fn ($q) => $q->where('warehouse_id', $warehouseId)->whereIn('shipment_status', ['pending', 'shipped']))
            ->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))
            ->get();

        $map = [];
        foreach ($orders as $order) {
            $keys = [(string) $order->erp_order_id];
            try {
                $keys[] = $warehouseKey === 'ca' ? $this->references->canada($order) : $this->references->warehouse($order);
            } catch (DomainException) {
                // Legacy/non-canonical ERP IDs remain matchable by their exact ERP ID only.
            }
            foreach (array_unique(array_filter($keys)) as $key) {
                $map[$this->identityKey($key)][] = $order;
            }
        }

        return $map;
    }

    private function identityKey(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
    }

    private function knownWarehouseMismatch(string $warehouseKey, mixed $providerWarehouse): bool
    {
        $value = mb_strtolower(trim((string) ($providerWarehouse ?? '')));
        if ($value === '') {
            return false;
        }

        $known = [
            'us-ny' => ['us01'],
            'us-3sm' => ['yangfan'],
            'de-tianyun' => ['hannover'],
            'ca' => ['ca02_多伦多仓'],
        ];
        $detected = null;
        foreach ($known as $key => $markers) {
            foreach ($markers as $marker) {
                if (str_contains($value, mb_strtolower($marker))) {
                    $detected = $key;
                    break 2;
                }
            }
        }

        return $detected !== null && $detected !== $warehouseKey;
    }

    private function providerShippedAt(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || preg_match('/(?:Z|[+-]\\d{2}:?\\d{2})$/i', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function hasWooRoot(Order $order): bool
    {
        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        $source = $root?->source_type instanceof \BackedEnum ? $root->source_type->value : (string) $root?->source_type;

        return $source === 'woocommerce';
    }
}
