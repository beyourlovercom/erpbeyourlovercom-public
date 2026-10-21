<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class OverseaWarehouseReviewService
{
    public const WAREHOUSE_CODE_BY_KEY = [
        'us-ny' => 'US-NY',
        'us-moreplus' => 'US-LA',
        'us-3sm' => 'US-SM',
        'de-tianyun' => 'DE',
        'ca' => 'CA',
    ];

    public const CARRIER_BY_CODE = [
        'US-NY' => 'USPS',
        'US-LA' => 'USPS',
        'US-SM' => 'USPS',
        'DE' => 'DHL',
        'CA' => 'Canada Post',
    ];

    public static function canonicalWarehouseCode(?string $code): ?string
    {
        $normalized = strtoupper(trim((string) $code));

        return in_array($normalized, array_values(self::WAREHOUSE_CODE_BY_KEY), true)
            ? $normalized
            : null;
    }

    public static function carrierForWarehouseCode(?string $code): ?string
    {
        $canonical = self::canonicalWarehouseCode($code);

        return $canonical !== null ? (self::CARRIER_BY_CODE[$canonical] ?? null) : null;
    }

    public function __construct(
        private readonly AssistedFulfillmentService $fulfillment,
        private readonly ShipmentService $shipments,
        private readonly NotificationPublisher $notifications,
    ) {}

    public function updateDraft(Order $order, array $data, Authenticatable $actor): Order
    {
        return DB::transaction(function () use ($order, $data, $actor): Order {
            $locked = Order::query()->with(['shipment.warehouse'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以进行海外仓审核');
            $shipment = $locked->shipment;
            abort_unless($shipment && $shipment->warehouse_id, 409, '订单尚未分配海外仓');
            $review = $this->reviewForMutation($locked);

            if (array_key_exists('warehouse_id', $data) && (int) $data['warehouse_id'] !== (int) $shipment->warehouse_id) {
                $warehouse = Warehouse::query()->whereKey((int) $data['warehouse_id'])
                    ->where('is_active', true)->firstOrFail();
                $warehouseCode = self::canonicalWarehouseCode($warehouse->code);
                abort_unless($warehouseCode !== null, 422, '该订单不是海外仓订单');
                $carrier = self::CARRIER_BY_CODE[$warehouseCode] ?? null;
                $locked = $this->fulfillment->saveDraft($locked, [
                    'warehouse_id' => $warehouse->id,
                    'carrier' => $carrier,
                    'service' => null,
                    'carrier_selection_source' => 'automatic',
                ], $actor);
                $review->forceFill(['status' => 'pending', 'reviewed_warehouse_id' => null, 'reviewed_by' => null, 'reviewed_at' => null])->save();
            }

            if (array_key_exists('carrier', $data) || array_key_exists('service', $data) || array_key_exists('tracking_number', $data)) {
                $shipmentDraft = [];
                if (array_key_exists('carrier', $data)) {
                    $shipmentDraft['carrier'] = trim((string) $data['carrier']);
                    $shipmentDraft['service'] = $data['service'] ?? null;
                    $shipmentDraft['carrier_selection_source'] = 'manual';
                }
                if (array_key_exists('service', $data) && ! array_key_exists('carrier', $data)) {
                    $shipmentDraft['service'] = $data['service'];
                }
                if (array_key_exists('tracking_number', $data)) {
                    $tracking = trim((string) ($data['tracking_number'] ?? ''));
                    $shipmentDraft['tracking_number'] = $tracking === '' ? null : $tracking;
                }
                $locked = $this->shipments->saveDraft($locked, $shipmentDraft, $actor);
            }

            if (array_key_exists('progress', $data) || array_key_exists('note', $data)) {
                $review->forceFill([
                    'progress' => $data['progress'] ?? $review->progress,
                    'note' => array_key_exists('note', $data) ? $data['note'] : $review->note,
                ])->save();
            }

            return $locked->fresh(['shipment.warehouse']);
        });
    }

    public function approve(Order $order, Authenticatable $actor, ?string $note = null): void
    {
        DB::transaction(function () use ($order, $actor, $note): void {
            $locked = Order::query()->with('shipment.warehouse')->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以审核');
            abort_unless($locked->shipment?->warehouse_id, 409, '订单尚未分配海外仓');
            $warehouseCode = self::canonicalWarehouseCode($locked->shipment->warehouse?->code);
            abort_unless($warehouseCode !== null, 422, '该订单不是海外仓订单');
            $review = $this->reviewForMutation($locked);
            $carrier = self::CARRIER_BY_CODE[$warehouseCode] ?? null;
            $manualCarrier = $locked->shipment->carrier_selection_source === 'manual'
                && filled($locked->shipment->carrier);
            if (! $manualCarrier && $locked->shipment->carrier !== $carrier) {
                $this->shipments->saveDraft($locked, [
                    'carrier' => $carrier,
                    'service' => null,
                    'carrier_selection_source' => 'automatic',
                ], $actor);
            }
            $review->forceFill([
                'status' => 'approved',
                'progress' => $review->progress === 'pending_review' ? 'sending_pending' : $review->progress,
                'reviewed_warehouse_id' => $locked->shipment->warehouse_id,
                'note' => $note !== null && $note !== '' ? $note : $review->note,
                'reviewed_by' => $actor->getAuthIdentifier(),
                'reviewed_at' => now(),
            ])->save();
        });
    }

    public function reopenReview(Order $order, Authenticatable $actor): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->with('shipment.warehouse')->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以重新审核');
            abort_unless($locked->shipment?->warehouse_id, 409, '订单尚未分配海外仓');
            $review = $this->reviewForMutation($locked);
            $review->forceFill([
                'status' => 'pending',
                'progress' => 'pending_review',
                'reviewed_warehouse_id' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            return $locked->fresh(['shipment.warehouse']);
        });
    }

    public function reject(Order $order, string $note, Authenticatable $actor): void
    {
        DB::transaction(function () use ($order, $note, $actor): void {
            $locked = Order::query()->with('shipment.warehouse')->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以驳回');
            abort_unless($locked->shipment?->warehouse_id, 409, '订单尚未分配海外仓');
            $review = $this->reviewForMutation($locked);
            $review->forceFill([
                'status' => 'rejected',
                'note' => $note,
                'reviewed_warehouse_id' => $locked->shipment->warehouse_id,
                'reviewed_by' => $actor->getAuthIdentifier(),
                'reviewed_at' => now(),
            ])->save();

            $cleared = $this->fulfillment->saveDraft($locked, [
                'warehouse_id' => null,
                'carrier' => null,
                'service' => null,
            ], $actor);
            $cleared->shipment?->forceFill([
                'warehouse_selection_source' => null,
                'carrier_selection_source' => null,
            ])->save();

            User::query()->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('key', RolePreset::Operations->roleKey()))
                ->get()
                ->each(fn (User $user) => $this->notifications->publishToUser($user, [
                    'category' => 'logistics',
                    'title' => '海外仓分配被驳回',
                    'message' => "订单 {$locked->erp_order_id} 被 Dede 驳回：{$note}",
                    'action_url' => '/logistics/wait-stock',
                    'entity_type' => 'order',
                    'entity_id' => (string) $locked->getKey(),
                    'actor_name' => $actor->name ?? null,
                ]));
        });
    }

    private function reviewForMutation(Order $order): OverseaWarehouseReview
    {
        $review = OverseaWarehouseReview::query()->where('order_id', $order->getKey())->lockForUpdate()->first();
        if ($review) {
            return $review;
        }

        return OverseaWarehouseReview::query()->create([
            'order_id' => $order->getKey(),
            'submitted_warehouse_id' => $order->shipment?->warehouse_id,
            'status' => 'pending',
            'progress' => 'pending_review',
        ]);
    }
}
