<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\ProductPackagingStandardReader;
use App\Modules\Inventory\Application\Contracts\WarehouseLotConsumptionCapture;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReviewHistory;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Logistics\Infrastructure\Models\ShipmentItemPackagingSnapshot;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Sync\Application\ManualSyncService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ShipmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ManualSyncService $manualSync,
        private readonly ProductPackagingStandardReader $packagingStandards,
        private readonly WarehouseLotConsumptionCapture $lotConsumptions,
        private readonly ShipmentCompletionSemantics $completionSemantics,
        private readonly ShipmentAggregateStatusService $aggregateStatus,
        private readonly CarrierServiceCatalog $carrierServices,
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly FulfillmentPaymentGate $paymentGate,
    ) {}

    public function saveDraft(Model $order, array $attributes, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $attributes, $actor): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            $this->paymentGate->assertAllowed($lockedOrder);
            abort_unless($lockedOrder->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以保存履约草稿');

            $shipment = $lockedOrder->shipment()->firstOrCreate(['order_id' => $lockedOrder->getKey()]);
            $before = $this->snapshot($shipment);
            $warehouseProvided = array_key_exists('warehouse_id', $attributes);
            $carrierProvided = array_key_exists('carrier', $attributes) || array_key_exists('service', $attributes);
            $trackingNumber = array_key_exists('tracking_number', $attributes)
                ? $this->canonicalTrackingNumber($attributes['tracking_number'])
                : $shipment->tracking_number;
            $selectedWarehouseId = $warehouseProvided ? $attributes['warehouse_id'] : $shipment->warehouse_id;
            $automaticRoute = $this->automaticRoute($lockedOrder, $selectedWarehouseId);
            $explicitCarrier = array_key_exists('carrier', $attributes) && trim((string) $attributes['carrier']) !== '';
            $selectedCarrier = $explicitCarrier
                ? trim((string) $attributes['carrier'])
                : (trim((string) $shipment->carrier) !== '' && ! $warehouseProvided ? trim((string) $shipment->carrier) : trim((string) ($automaticRoute['carrier'] ?? '')));
            $carrierChanged = $selectedCarrier !== trim((string) $shipment->carrier);
            $selectedService = array_key_exists('service', $attributes) && trim((string) $attributes['service']) !== ''
                ? $attributes['service']
                : ($explicitCarrier ? null : ($carrierChanged || $warehouseProvided ? ($automaticRoute['service'] ?? null) : $shipment->service));
            $effectiveCarrierProvided = $carrierProvided || $selectedCarrier !== '';
            $normalizedService = $effectiveCarrierProvided
                ? $this->normalizeCarrierService($lockedOrder, $selectedCarrier, $selectedService, $shipment->carrier_service_id)
                : ['service' => $shipment->service, 'carrier_service_id' => $shipment->carrier_service_id];
            $automaticSelection = $this->matchesAutomaticRoute($selectedCarrier, $normalizedService['service'], $automaticRoute);
            $shipment->fill([
                'shipment_status' => 'pending',
                'warehouse_id' => $selectedWarehouseId,
                'warehouse_selection_source' => $warehouseProvided
                    ? ($attributes['warehouse_selection_source'] ?? 'manual')
                    : $shipment->warehouse_selection_source,
                'carrier' => $selectedCarrier !== '' ? $selectedCarrier : null,
                'service' => $normalizedService['service'],
                'carrier_service_id' => $normalizedService['carrier_service_id'],
                'carrier_selection_source' => $selectedCarrier !== ''
                    ? ($automaticSelection ? 'automatic' : ($attributes['carrier_selection_source'] ?? 'manual'))
                    : $shipment->carrier_selection_source,
                'tracking_number' => $trackingNumber,
                'logistics_note' => array_key_exists('logistics_note', $attributes) ? $attributes['logistics_note'] : $shipment->logistics_note,
                'handled_by' => $actor?->getAuthIdentifier(),
            ]);
            $changed = $shipment->isDirty();
            $shipment->save();
            $this->syncPackagingSnapshots($lockedOrder, $shipment);

            if ($changed) {
                $this->record($actor, 'shipment.fulfillment_draft_saved', $shipment, $before, $this->snapshot($shipment), 'manual_fulfillment_draft');
            }

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync']);
        });
    }

    public function markShipped(Model $order, array $attributes, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $attributes, $actor): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            $this->paymentGate->assertAllowed($lockedOrder);
            $shipment = $lockedOrder->shipment()->firstOrCreate(['order_id' => $lockedOrder->getKey()]);
            $alreadyShipped = $lockedOrder->order_status?->value === 'shipped' && $shipment->shipment_status === 'shipped';
            $before = $this->snapshot($shipment);
            $trackingNumber = $this->canonicalTrackingNumber($attributes['tracking_number'] ?? null);
            $selectedWarehouseId = $attributes['warehouse_id'] ?? $shipment->warehouse_id;
            $automaticRoute = $this->automaticRoute($lockedOrder, $selectedWarehouseId);
            $explicitCarrier = array_key_exists('carrier', $attributes) && trim((string) $attributes['carrier']) !== '';
            $selectedCarrier = $explicitCarrier
                ? trim((string) $attributes['carrier'])
                : (trim((string) $shipment->carrier) !== '' ? trim((string) $shipment->carrier) : trim((string) ($automaticRoute['carrier'] ?? '')));
            $selectedService = array_key_exists('service', $attributes) && trim((string) $attributes['service']) !== ''
                ? $attributes['service']
                : (($shipment->service && ! $explicitCarrier) ? $shipment->service : ($automaticRoute['service'] ?? null));
            $normalizedService = $this->normalizeCarrierService($lockedOrder, $selectedCarrier, $selectedService, $shipment->carrier_service_id);
            $automaticSelection = $this->matchesAutomaticRoute($selectedCarrier, $normalizedService['service'], $automaticRoute);
            $preserveRecommendedSource = $shipment->warehouse_selection_source === 'recommended'
                && (int) $shipment->warehouse_id === (int) $selectedWarehouseId;
            $shipment->fill([
                'shipment_status' => 'shipped',
                'warehouse_id' => $selectedWarehouseId,
                'warehouse_selection_source' => array_key_exists('warehouse_id', $attributes) && $attributes['warehouse_id'] !== null
                    ? ($preserveRecommendedSource ? 'recommended' : 'manual')
                    : $shipment->warehouse_selection_source,
                'carrier' => $selectedCarrier,
                'service' => $normalizedService['service'],
                'carrier_service_id' => $normalizedService['carrier_service_id'],
                'carrier_selection_source' => $automaticSelection ? 'automatic' : 'manual',
                'tracking_number' => $trackingNumber ?: null,
                'shipped_at' => $attributes['shipped_at'],
                'logistics_note' => $attributes['logistics_note'] ?? null,
                'handled_by' => $actor?->getAuthIdentifier(),
            ]);
            $changed = $shipment->isDirty() || $lockedOrder->order_status?->value !== 'shipped';
            $shipment->save();
            if (! $alreadyShipped) {
                $this->syncPackagingSnapshots($lockedOrder, $shipment);
            }
            if ($lockedOrder->order_status?->value !== 'shipped') {
                $lockedOrder->forceFill(['order_status' => 'shipped'])->save();
            }
            if ((bool) config('warehouse_storage_aging.fifo_consumption_enabled', false)) {
                $this->lotConsumptions->captureForShipment($shipment);
            }
            if ($trackingNumber) {
                $this->manualSync->ensurePending($lockedOrder, $actor);
            }
            if ($changed) {
                $this->record($actor, 'shipment.marked_shipped', $shipment, $before, $this->snapshot($shipment), 'manual_fulfillment');
            }
            $this->aggregateStatus->reconcile($lockedOrder, $actor);

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync', 'financials', 'costEntries']);
        });
    }

    public function updateTracking(Model $order, string $trackingNumber, ?Authenticatable $actor): Model
    {
        $trackingNumber = $this->canonicalTrackingNumber($trackingNumber);
        abort_unless($trackingNumber !== null, 422, '追踪号只能填写单号本体，备注请填写到物流备注');

        return $this->writeTransaction(function () use ($order, $trackingNumber, $actor): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            abort_unless(in_array($shipment->shipment_status, ['shipped', 'exception'], true), 409, '只有已发货或异常状态的物流单可更新追踪号');
            $before = $this->snapshot($shipment);
            $shipment->fill(['tracking_number' => $trackingNumber]);
            $changed = $shipment->isDirty();
            $shipment->save();
            $this->manualSync->ensurePending($lockedOrder, $actor);
            if ($changed) {
                $this->record($actor, 'shipment.tracking_updated', $shipment, $before, $this->snapshot($shipment), 'tracking_update');
            }
            $this->aggregateStatus->reconcile($lockedOrder, $actor);

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync']);
        });
    }

    public function markException(Model $order, string $note, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $note, $actor): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            if ($shipment->shipment_status === 'exception') {
                return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync']);
            }
            abort_unless($shipment->shipment_status === 'shipped', 409, '只有运输中的物流单可标记异常');
            $before = $this->snapshot($shipment);
            $shipment->fill(['shipment_status' => 'exception', 'logistics_note' => $note]);
            $shipment->save();
            $this->record($actor, 'shipment.exception_marked', $shipment, $before, $this->snapshot($shipment), 'logistics_exception');
            $this->aggregateStatus->reconcile($lockedOrder, $actor);

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync']);
        });
    }

    public function resolveException(Model $order, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $actor): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            if ($shipment->shipment_status === 'shipped') {
                return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync']);
            }
            abort_unless($shipment->shipment_status === 'exception', 409, '只有异常状态的物流单可解除异常');
            $before = $this->snapshot($shipment);
            $shipment->fill(['shipment_status' => 'shipped']);
            $shipment->save();
            $this->record($actor, 'shipment.exception_resolved', $shipment, $before, $this->snapshot($shipment), 'logistics_exception_resolved');
            $this->aggregateStatus->reconcile($lockedOrder, $actor);

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync']);
        });
    }

    public function pauseException(Model $order, string $note, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $note, $actor): Model {
            $lockedOrder = $order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            abort_unless($shipment->shipment_status === 'exception', 409, '只有异常订单可以暂停');
            $before = $this->snapshot($shipment);
            $shipment->forceFill(['logistics_note' => $note])->save();
            $lockedOrder->operationalMarkers()->updateOrCreate(['marker' => 'logistics_paused'], ['marked_by' => $actor?->getAuthIdentifier(), 'marked_at' => now()]);
            $this->record($actor, 'shipment.exception_paused', $shipment, $before, $this->snapshot($shipment), 'logistics_exception_pause');

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync']);
        });
    }

    public function requestReview(Model $order, string $trigger, string $reason, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $trigger, $reason, $actor): Model {
            $lockedOrder = $order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            abort_unless($shipment->shipment_status === 'exception', 409, '只有异常订单可以提交复核');
            $review = LogisticsReview::query()->where('order_id', $lockedOrder->getKey())->lockForUpdate()->first();
            if ($review?->status === 'pending') {
                return $lockedOrder->fresh(['shipment.warehouse', 'logisticsReview']);
            }
            $review = LogisticsReview::query()->updateOrCreate(['order_id' => $lockedOrder->getKey()], [
                'status' => 'pending', 'requested_by' => $actor?->getAuthIdentifier(), 'reviewed_by' => null,
                'requested_at' => now(), 'reviewed_at' => null, 'reason' => $reason, 'trigger' => $trigger,
                'destination' => null, 'decision_note' => null,
            ]);
            $this->reviewHistory($review, $lockedOrder, 'requested', $actor, null, $reason, $this->snapshot($shipment));
            $this->record($actor, 'logistics.review_requested', $shipment, $this->snapshot($shipment), $this->snapshot($shipment), $trigger);

            return $lockedOrder->fresh(['shipment.warehouse', 'logisticsReview']);
        });
    }

    public function decideReview(Model $order, string $destination, string $note, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $destination, $note, $actor): Model {
            $lockedOrder = $order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $review = $lockedOrder->logisticsReview()->lockForUpdate()->firstOrFail();
            abort_unless($review->status === 'pending', 409, '复核已处理');
            abort_unless(in_array($destination, ['pending', 'awaiting_tracking', 'in_transit', 'exception', 'completed', 'cancelled'], true), 422, '无效复核去向');
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            if (in_array($destination, ['awaiting_tracking', 'in_transit'], true)) {
                abort_unless($shipment->shipped_at !== null, 422, '该去向需要发货时间');
            }
            if ($destination === 'awaiting_tracking') {
                abort_unless(blank($shipment->tracking_number), 422, '已发货去单号必须没有追踪号');
            }
            if ($destination === 'in_transit') {
                abort_unless(filled($shipment->tracking_number), 422, '运输中必须有追踪号');
            }
            if ($destination === 'completed') {
                abort_unless(in_array($shipment->shipment_status, ['shipped', 'exception'], true), 422, '完结必须来自已发货或异常');
                $this->completionSemantics->assertShipmentHasFulfillmentEvidence($shipment, 422);
            }
            $before = $this->snapshot($shipment);
            if ($destination === 'pending') {
                $this->snapshotAndReset($lockedOrder, $shipment, $actor, $note);
            } else {
                $status = $destination === 'awaiting_tracking' || $destination === 'in_transit' ? 'shipped' : ($destination === 'cancelled' ? $shipment->shipment_status : $destination);
                $shipment->forceFill(['shipment_status' => $status])->save();
            }
            $lockedOrder->forceFill(['order_status' => $destination === 'cancelled' ? OrderStatus::Cancelled : ($destination === 'pending' ? OrderStatus::PendingShipment : OrderStatus::Shipped)])->save();
            $review->forceFill(['status' => 'decided', 'reviewed_by' => $actor?->getAuthIdentifier(), 'reviewed_at' => now(), 'destination' => $destination, 'decision_note' => $note])->save();
            $this->reviewHistory($review, $lockedOrder, 'decided', $actor, $destination, $note, $this->snapshot($shipment));
            $this->record($actor, 'logistics.review_decided', $shipment, $before, $this->snapshot($shipment), 'logistics_review');
            $this->aggregateStatus->reconcile($lockedOrder, $actor);

            return $lockedOrder->fresh(['items', 'shipment.warehouse', 'logisticsReview']);
        });
    }

    public function reship(Model $order, array $attributes, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $attributes, $actor): Model {
            $lockedOrder = $order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            abort_unless($shipment->shipment_status === 'exception', 409, '只有异常订单可以重新发货');
            $attempt = (int) $lockedOrder->shipmentAttempts()->max('attempt_number') + 1;
            $before = $this->snapshot($shipment);
            $this->snapshotAndReset($lockedOrder, $shipment, $actor, $attributes['logistics_note'] ?? '重新发货', $attempt);
            $this->record($actor, 'shipment.reshipped', $shipment, $before, $this->snapshot($shipment), 'logistics_reship');

            return $lockedOrder->fresh(['items', 'shipment.warehouse', 'shipmentAttempts']);
        });
    }

    public function contactUser(Model $order, string $note, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $note, $actor): Model {
            $lockedOrder = $order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            abort_unless($shipment->shipment_status === 'exception', 409, '只有异常订单可以联系用户');
            $this->record($actor, 'shipment.user_contact_recorded', $shipment, $this->snapshot($shipment), ['note' => $note, ...$this->snapshot($shipment)], 'logistics_contact_user');

            return $lockedOrder->fresh(['shipment.warehouse']);
        });
    }

    public function cancelException(Model $order, string $note, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $note, $actor): Model {
            $lockedOrder = $order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            abort_unless($shipment->shipment_status === 'exception', 409, '只有异常订单可以退款取消');
            $before = $this->snapshot($shipment);
            $shipment->forceFill(['logistics_note' => $note])->save();
            $lockedOrder->forceFill(['order_status' => OrderStatus::Cancelled])->save();
            $this->record($actor, 'order.cancelled_from_exception', $shipment, $before, ['order_status' => 'cancelled', ...$this->snapshot($shipment)], 'logistics_refund_cancel');

            return $lockedOrder->fresh(['shipment.warehouse']);
        });
    }

    public function markCompleted(Model $order, ?Authenticatable $actor): Model
    {
        return $this->writeTransaction(function () use ($order, $actor): Model {
            $lockedOrder = $order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            abort_unless($shipment->shipment_status === 'shipped', 409, '只有已实际发货的运输中订单可以完结');
            $this->completionSemantics->assertShipmentHasFulfillmentEvidence($shipment, 409);
            $before = $this->snapshot($shipment);
            $shipment->forceFill(['shipment_status' => 'completed', 'handled_by' => $actor?->getAuthIdentifier()])->save();
            $this->record($actor, 'shipment.completed', $shipment, $before, $this->snapshot($shipment), 'logistics_completed');
            $this->aggregateStatus->reconcile($lockedOrder, $actor);

            return $lockedOrder->fresh(['shipment.warehouse']);
        });
    }

    public function reopen(Model $order, ?Authenticatable $actor, ?string $note = null): Model
    {
        return $this->writeTransaction(function () use ($order, $actor, $note): Model {
            $orderClass = get_class($order);
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->assertNoPendingReview($lockedOrder);
            $shipment = $lockedOrder->shipment()->lockForUpdate()->firstOrFail();
            if ($shipment->shipment_status !== 'pending') {
                $this->snapshotAndReset($lockedOrder, $shipment, $actor, $note);
            }
            $before = $this->snapshot($shipment);
            $shipment->fill([
                'shipment_status' => 'pending',
                'warehouse_id' => null,
                'warehouse_selection_source' => null,
                'carrier' => null,
                'service' => null,
                'carrier_selection_source' => null,
                'tracking_number' => null,
                'shipped_at' => null,
                'logistics_note' => null,
                'handled_by' => null,
            ]);
            $changed = $shipment->isDirty() || $lockedOrder->order_status?->value !== 'pending_shipment';
            $shipment->save();
            if ($lockedOrder->order_status?->value !== 'pending_shipment') {
                $lockedOrder->forceFill(['order_status' => 'pending_shipment'])->save();
            }
            $this->manualSync->reopen($lockedOrder, $actor, $note);
            if ($changed) {
                $this->record($actor, 'shipment.reopened', $shipment, $before, $this->snapshot($shipment), 'shipment_correction');
            }
            $this->aggregateStatus->reconcile($lockedOrder, $actor);

            return $lockedOrder->fresh(['items', 'owner', 'shipment.warehouse', 'manualSync', 'financials', 'costEntries']);
        });
    }

    private function writeTransaction(\Closure $callback): mixed
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("SET time_zone = '+00:00'");
        }

        return DB::transaction($callback);
    }

    private function syncPackagingSnapshots(Model $order, Model $shipment): void
    {
        $order->loadMissing('items');
        $items = $order->items;
        $standards = $this->packagingStandards->forProductIds($items->pluck('product_id')->filter()->unique()->values()->all());
        $validOrderItemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($items as $item) {
            $existing = ShipmentItemPackagingSnapshot::query()
                ->where('shipment_id', $shipment->getKey())
                ->where('order_item_id', $item->id)
                ->first();
            if ($existing) {
                continue;
            }

            $standard = $item->product_id ? ($standards[(int) $item->product_id] ?? null) : null;
            if (! $standard) {
                continue;
            }

            ShipmentItemPackagingSnapshot::query()->create([
                'shipment_id' => $shipment->getKey(),
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_packaging_profile_id' => $standard['product_packaging_profile_id'],
                'packaging_measurement_record_id' => $standard['measurement_record_id'],
                'packaging_method_key' => $standard['packaging_method_key'],
                'packaging_method_label' => $standard['packaging_method_label'],
                'packaging_length_cm' => $standard['packaging_length_cm'],
                'packaging_width_cm' => $standard['packaging_width_cm'],
                'packaging_height_cm' => $standard['packaging_height_cm'],
                'packaged_weight_kg' => $standard['packaged_weight_kg'],
                'measured_at' => $standard['measured_at'],
                'captured_at' => now(),
            ]);
        }

        $stale = ShipmentItemPackagingSnapshot::query()->where('shipment_id', $shipment->getKey());
        $validOrderItemIds === [] ? $stale->delete() : $stale->whereNotIn('order_item_id', $validOrderItemIds)->delete();
    }

    /** @return array<string,mixed> */
    private function automaticRoute(Model $order, mixed $warehouseId): array
    {
        $warehouseId = (int) $warehouseId;
        if ($warehouseId <= 0) return [];
        $warehouse = Warehouse::query()->find($warehouseId);
        if (! $warehouse) return [];
        return $this->carrierRouting->warehouseRoute($order->address?->country, (string) $warehouse->code);
    }

    /** @param array<string,mixed> $route */
    private function matchesAutomaticRoute(string $carrier, ?string $service, array $route): bool
    {
        $routeCarrier = trim((string) ($route['carrier'] ?? ''));
        $routeService = trim((string) ($route['service'] ?? ''));
        if ($routeCarrier === '' || strcasecmp(trim($carrier), $routeCarrier) !== 0) return false;
        if ($routeService === '') return true;
        return strcasecmp(trim((string) $service), $routeService) === 0;
    }

    /** @return array{service:?string,carrier_service_id:?int} */
    private function normalizeCarrierService(Model $order, string $carrier, mixed $service, mixed $existingServiceId = null): array
    {
        $carrier = trim($carrier);
        if ($carrier === '') return ['service' => null, 'carrier_service_id' => null];
        $serviceText = trim((string) $service);
        $destination = $order->address?->country;
        $catalog = $this->carrierServices->activeServices($carrier);
        if ($catalog->isEmpty()) {
            return ['service' => $serviceText !== '' ? $serviceText : null, 'carrier_service_id' => $existingServiceId ? (int) $existingServiceId : null];
        }
        $resolved = $this->carrierServices->resolve($carrier, $serviceText !== '' ? $serviceText : null, $destination);
        abort_unless($resolved, 422, '请选择该物流商已启用的运输 / 服务类型');
        return [
            'service' => $serviceText !== '' ? $serviceText : $resolved->service_value,
            'carrier_service_id' => (int) $resolved->id,
        ];
    }

    private function canonicalTrackingNumber(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trackingNumber = trim((string) $value);
        if ($trackingNumber === '') {
            return null;
        }

        $isCanonical = strlen($trackingNumber) <= 128
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]*\z/', $trackingNumber) === 1;
        abort_unless($isCanonical, 422, '追踪号只能填写单号本体，不能包含空格、中文或备注文字；备注请填写到物流备注');

        return $trackingNumber;
    }

    private function snapshot(Model $shipment): array
    {
        return $shipment->only([
            'shipment_status', 'warehouse_id', 'warehouse_selection_source', 'carrier', 'service',
            'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by',
        ]);
    }

    private function snapshotAndReset(Model $order, Model $shipment, ?Authenticatable $actor, ?string $note = null, ?int $attemptNumber = null): ShipmentAttempt
    {
        if ((bool) config('warehouse_storage_aging.fifo_consumption_enabled', false)) {
            $this->lotConsumptions->assertShipmentMayReset($shipment);
        }
        $attemptNumber ??= (int) $order->shipmentAttempts()->max('attempt_number') + 1;
        $packaging = ShipmentItemPackagingSnapshot::query()->where('shipment_id', $shipment->getKey())->get()->toArray();
        $billingModel = $shipment->billingEvidence()->first();
        $billing = $billingModel?->toArray();
        $attempt = ShipmentAttempt::query()->create([...$this->snapshot($shipment), 'order_id' => $order->getKey(), 'shipment_id' => $shipment->getKey(), 'attempt_number' => $attemptNumber, 'snapshotted_by' => $actor?->getAuthIdentifier(), 'snapshotted_at' => now(), 'evidence_snapshot' => ['packaging' => $packaging, 'billing' => $billing]]);
        ShipmentItemPackagingSnapshot::query()->where('shipment_id', $shipment->getKey())->delete();
        $billingModel?->delete();
        $shipment->forceFill(['shipment_status' => 'pending', 'warehouse_id' => null, 'warehouse_selection_source' => null, 'carrier' => null, 'service' => null, 'carrier_selection_source' => null, 'tracking_number' => null, 'shipped_at' => null, 'logistics_note' => null, 'handled_by' => null])->save();
        $order->forceFill(['order_status' => OrderStatus::PendingShipment])->save();
        $this->manualSync->reopen($order, $actor, $note);

        return $attempt;
    }

    private function record(?Authenticatable $actor, string $action, Model $shipment, array $before, array $after, string $reason): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action,
            get_class($shipment),
            $shipment->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }

    private function assertNoPendingReview(Model $order): void
    {
        abort_unless($order->logisticsReview()->where('status', 'pending')->doesntExist(), 409, '订单正在复核，暂不可执行履约操作');
    }

    private function reviewHistory(LogisticsReview $review, Model $order, string $event, ?Authenticatable $actor, ?string $destination, ?string $note, array $snapshot): void
    {
        LogisticsReviewHistory::query()->create(['logistics_review_id' => $review->getKey(), 'order_id' => $order->getKey(), 'event' => $event, 'status' => $review->status, 'actor_id' => $actor?->getAuthIdentifier(), 'destination' => $destination, 'note' => $note, 'snapshot' => $snapshot, 'created_at' => now()]);
    }
}
