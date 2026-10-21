<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Throwable;

final class DomesticTrackingReturnService
{
    public function __construct(
        private readonly DomesticTrackingReturnReader $reader,
        private readonly WarehouseOrderReference $references,
        private readonly ShipmentService $shipments,
        private readonly WooCommerceTrackingOutboundService $trackingOutbound,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $provider, UploadedFile $file): array
    {
        $provider = $this->provider($provider);
        try {
            $normalized = $this->reader->read($provider, $file);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
        abort_if($normalized === [], 422, '回传表没有可处理的数据行。');

        $plan = $this->plan($provider, $normalized);
        $token = hash('sha256', json_encode([$provider, $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [...$plan, 'token' => $token, 'normalized_rows' => $normalized];
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    public function apply(string $provider, array $stored, string $token, Authenticatable $actor): array
    {
        $provider = $this->provider($provider);
        abort_unless(isset($stored['token'], $stored['normalized_rows']) && hash_equals((string) $stored['token'], $token), 409, '上传预览已失效，请重新上传。');

        // Re-plan at apply time. Business continuity rule: blocked rows do NOT block safe rows.
        $plan = $this->plan($provider, (array) $stored['normalized_rows']);
        $results = [];
        $applied = 0;
        $noop = 0;
        $blocked = 0;
        $failed = 0;

        foreach ($plan['rows'] as $row) {
            if ($row['status'] === 'noop') {
                $noop++;
                $results[] = $row;

                continue;
            }
            if ($row['status'] === 'blocked') {
                $blocked++;
                $results[] = $row;

                continue;
            }

            try {
                $order = Order::query()->with(['shipment', 'items', 'externalReferences', 'fulfillmentParent.externalReferences'])->findOrFail((int) $row['order_id']);
                $shipment = $order->shipment;
                $this->shipments->markShipped($order, [
                    'warehouse_id' => $shipment?->warehouse_id,
                    'carrier' => $row['carrier'],
                    'service' => $shipment?->service,
                    'tracking_number' => $row['tracking_number'],
                    'shipped_at' => now()->utc()->toIso8601String(),
                    'logistics_note' => $shipment?->logistics_note,
                ], $actor);
                $applied++;

                $sync = null;
                $fresh = $order->fresh(['shipment', 'items', 'externalReferences', 'fulfillmentParent.externalReferences']);
                if ($this->hasWooRoot($fresh)) {
                    try {
                        $push = $this->trackingOutbound->pushOne($fresh, $actor);
                        $sync = (string) ($push['status'] ?? 'failed');
                    } catch (Throwable $e) {
                        // Shipment truth is already committed; outbound Woo failure is isolated to this order.
                        $sync = 'failed: '.$e->getMessage();
                    }
                }
                $results[] = [...$row, 'status' => 'applied', 'sync' => $sync];
            } catch (Throwable $e) {
                $failed++;
                $results[] = [...$row, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return [
            'applied_count' => $applied,
            'noop_count' => $noop,
            'blocked_count' => $blocked,
            'failed_count' => $failed,
            'rows' => $results,
        ];
    }

    /** @param list<array<string,mixed>> $normalized @return array<string,mixed> */
    private function plan(string $provider, array $normalized): array
    {
        $warehouse = Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])->where('is_active', true)->firstOrFail();
        $identity = $this->identityMap((int) $warehouse->id);
        $seen = [];
        $rows = [];

        foreach ($normalized as $source) {
            $reference = trim((string) ($source['order_reference'] ?? ''));
            $tracking = trim((string) ($source['tracking_number'] ?? ''));
            $key = $this->identityKey($reference);
            $messages = [];

            if ($reference === '') {
                $messages[] = '订单号为空';
            }
            if (str_starts_with($tracking, '__CONFLICT__:')) {
                $messages[] = '纬狮运单号与服务商单号不一致，禁止自动选择';
            } elseif ($tracking === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]{0,127}\z/', $tracking) !== 1) {
                $messages[] = 'Tracking No. 无效';
            }
            if ($key !== '' && isset($seen[$key])) {
                $messages[] = '同一文件订单号重复，禁止自动合并';
            }
            if ($key !== '') {
                $seen[$key] = true;
            }

            $matches = $identity[$key] ?? [];
            $order = count($matches) === 1 ? $matches[0] : null;
            if (count($matches) > 1) {
                $messages[] = 'ERP 订单号匹配不唯一';
            } elseif (! $order) {
                $messages[] = 'ERP 找不到该订单号';
            }

            $shipment = $order?->shipment;
            if ($order && ! $shipment) {
                $messages[] = '订单没有 Shipment';
            } elseif ($shipment) {
                if ((int) $shipment->warehouse_id !== (int) $warehouse->id) {
                    $messages[] = '订单不属于中国工厂';
                }
                if (! $order->operationalMarkers->contains('marker', 'domestic_reviewed')) {
                    $messages[] = '订单尚未完成国内物流审核';
                }
                $expectedCarrier = $provider === 'generic'
                    ? trim((string) ($source['carrier'] ?? ''))
                    : ($provider === '4px' ? '4PX' : 'Weship');
                if ($expectedCarrier === '') {
                    $messages[] = '承运商为空';
                } elseif (strcasecmp(trim((string) $shipment->carrier), $expectedCarrier) !== 0) {
                    $messages[] = '订单承运商与回传文件不一致';
                }
                if ($shipment->shipment_status === 'pending' && filled($shipment->tracking_number) && strcasecmp((string) $shipment->tracking_number, $tracking) !== 0) {
                    $messages[] = 'ERP 已有不同 Tracking，禁止覆盖';
                }
            }

            $status = 'blocked';
            if ($messages === [] && $shipment) {
                if ($shipment->shipment_status === 'pending') {
                    $status = 'apply';
                } elseif ($shipment->shipment_status === 'shipped') {
                    $same = strcasecmp((string) $shipment->tracking_number, $tracking) === 0
                        && strcasecmp((string) $shipment->carrier, (string) $source['carrier']) === 0;
                    $status = $same ? 'noop' : 'blocked';
                    if (! $same) {
                        $messages[] = '订单已经发货且现有 Tracking / Carrier 与文件不一致';
                    }
                } else {
                    $messages[] = 'Shipment 当前状态不可由回传表发货';
                }
            }

            $rows[] = [
                'row' => (int) ($source['row'] ?? 0),
                'order_id' => $order?->id,
                'order_number' => $reference,
                'tracking_number' => str_starts_with($tracking, '__CONFLICT__:') ? '' : $tracking,
                'carrier' => (string) ($source['carrier'] ?? ''),
                'provider_status' => $source['provider_status'] ?? null,
                'status' => $status,
                'message' => implode('；', $messages),
            ];
        }

        return [
            'rows' => $rows,
            'apply_count' => collect($rows)->where('status', 'apply')->count(),
            'noop_count' => collect($rows)->where('status', 'noop')->count(),
            'blocked_count' => collect($rows)->where('status', 'blocked')->count(),
        ];
    }

    /** @return array<string,list<Order>> */
    private function identityMap(int $warehouseId): array
    {
        $orders = Order::query()
            ->with(['shipment', 'operationalMarkers'])
            ->whereHas('shipment', fn ($q) => $q->where('warehouse_id', $warehouseId)->whereIn('shipment_status', ['pending', 'shipped']))
            ->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))
            ->get();

        $map = [];
        foreach ($orders as $order) {
            $keys = [(string) $order->erp_order_id];
            try {
                $keys[] = $this->references->warehouse($order);
            } catch (DomainException) {
                // Exact ERP ID remains available even when legacy provider reference cannot be generated.
            }
            foreach (array_unique(array_filter($keys)) as $key) {
                $map[$this->identityKey($key)][] = $order;
            }
        }

        return $map;
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        abort_unless(in_array($provider, ['4px', 'weship', 'generic'], true), 404);

        return $provider;
    }

    private function identityKey(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
    }

    private function hasWooRoot(Order $order): bool
    {
        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        $source = $root?->source_type instanceof \BackedEnum ? $root->source_type->value : (string) $root?->source_type;

        return $source === 'woocommerce';
    }
}
