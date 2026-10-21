<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\InventoryAdjustment;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Logistics\Infrastructure\Models\ShipmentItemPackagingSnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductionUatFulfillmentCorrectionService
{
    public function __construct(
        private readonly WooCommerceTrackingGateway $gateway,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $erpOrderId): array
    {
        $order = Order::query()
            ->with(['shipment', 'externalReferences', 'shipmentAttempts'])
            ->where('erp_order_id', $erpOrderId)
            ->first();

        if (! $order) {
            return $this->blocked($erpOrderId, 'order_not_found');
        }

        if (($order->source_type?->value ?? (string) $order->source_type) !== 'woocommerce') {
            return $this->blocked($erpOrderId, 'not_woocommerce_order', $order);
        }

        $shipment = $order->shipment;
        if (! $shipment || $shipment->shipment_status !== 'shipped') {
            return $this->blocked($erpOrderId, 'shipment_not_shipped', $order);
        }

        $descriptor = $this->gateway->descriptor();
        $connection = trim((string) ($descriptor['connection_key'] ?? ''));
        $reference = $order->externalReferences->first(
            fn ($item) => $item->provider === 'woocommerce' && $item->connection_key === $connection,
        );
        if (! $reference || ! ctype_digit((string) $reference->reference)) {
            return $this->blocked($erpOrderId, 'woocommerce_reference_missing', $order);
        }

        $remote = $this->gateway->inspectTracking((int) $reference->reference);
        if (! ($remote['has_tracking'] ?? false)
            || trim((string) ($remote['tracking_number'] ?? '')) === ''
            || trim((string) ($remote['provider'] ?? '')) === '') {
            return $this->blocked($erpOrderId, 'remote_tracking_missing', $order);
        }

        $carrier = $this->carrierForProvider((string) $remote['provider']);
        if ($carrier === null) {
            return $this->blocked($erpOrderId, 'remote_provider_unmapped', $order, ['remote' => $remote]);
        }

        $reservations = InventoryReservation::query()
            ->with('items')
            ->where('order_id', $order->id)
            ->where('status', 'committed')
            ->orderBy('id')
            ->get();
        if ($reservations->count() !== 1) {
            return $this->blocked($erpOrderId, 'committed_reservation_not_unique', $order, ['count' => $reservations->count()]);
        }

        $reservation = $reservations->first();
        if ($reservation->items->isEmpty()) {
            return $this->blocked($erpOrderId, 'reservation_items_missing', $order);
        }

        $items = [];
        $correctionCount = 0;
        foreach ($reservation->items as $item) {
            $inventory = WarehouseInventory::query()->find($item->warehouse_inventory_id);
            if (! $inventory || (int) $inventory->warehouse_id !== (int) $reservation->warehouse_id) {
                return $this->blocked($erpOrderId, 'reservation_inventory_missing', $order);
            }
            $reason = 'uat_fulfillment_correction_reversal_reservation_'.$reservation->id;
            $corrected = InventoryAdjustment::query()
                ->where('warehouse_inventory_id', $inventory->id)
                ->where('reason', $reason)
                ->exists();
            $correctionCount += $corrected ? 1 : 0;
            $items[] = [
                'reservation_item_id' => (int) $item->id,
                'warehouse_inventory_id' => (int) $inventory->id,
                'product_id' => (int) $item->product_id,
                'quantity' => (int) $item->quantity,
                'quantity_on_hand' => (int) $inventory->quantity_on_hand,
                'quantity_reserved' => (int) $inventory->quantity_reserved,
                'correction_applied' => $corrected,
            ];
        }

        $localTracking = trim((string) $shipment->tracking_number);
        $remoteTracking = trim((string) $remote['tracking_number']);
        $remoteProvider = trim((string) $remote['provider']);
        $shipmentReconciled = $shipment->warehouse_id === null
            && $localTracking === $remoteTracking
            && $shipment->carrier === $carrier;
        if ($shipmentReconciled && $correctionCount === count($items)) {
            return [
                'decision' => 'noop',
                'reason' => 'already_reconciled',
                'erp_order_id' => $erpOrderId,
                'order_id' => (int) $order->id,
                'shipment_id' => (int) $shipment->id,
            ];
        }
        if ($correctionCount > 0 || $shipmentReconciled) {
            return $this->blocked($erpOrderId, 'partial_correction_state', $order, [
                'inventory_corrections' => $correctionCount,
                'reservation_items' => count($items),
                'shipment_reconciled' => $shipmentReconciled,
            ]);
        }
        if ((int) $reservation->warehouse_id !== (int) $shipment->warehouse_id) {
            return $this->blocked($erpOrderId, 'reservation_shipment_warehouse_mismatch', $order);
        }

        $facts = [
            'erp_order_id' => $erpOrderId,
            'order_id' => (int) $order->id,
            'provider_order_id' => (int) $reference->reference,
            'shipment' => $this->shipmentSnapshot($shipment),
            'reservation' => [
                'id' => (int) $reservation->id,
                'warehouse_id' => (int) $reservation->warehouse_id,
                'status' => (string) $reservation->status,
                'committed_at' => $reservation->committed_at?->toIso8601String(),
                'items' => $items,
            ],
            'remote' => [
                'tracking_number' => $remoteTracking,
                'provider' => $remoteProvider,
                'carrier' => $carrier,
                'ship_date' => $remote['ship_date'] ?? null,
            ],
        ];
        $fingerprint = hash('sha256', json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'decision' => 'change',
            'reason' => 'production_uat_fulfillment_reconciliation',
            'fingerprint' => $fingerprint,
            ...$facts,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $erpOrderId, string $expectedFingerprint): array
    {
        $preview = $this->preview($erpOrderId);
        if (($preview['decision'] ?? null) !== 'change') {
            throw new RuntimeException('Correction preview is not in change state.');
        }
        if (! hash_equals((string) $preview['fingerprint'], $expectedFingerprint)) {
            throw new RuntimeException('Correction fingerprint mismatch.');
        }

        return DB::transaction(function () use ($erpOrderId, $preview): array {
            $order = Order::query()->where('erp_order_id', $erpOrderId)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $reservation = InventoryReservation::query()->with('items')->whereKey($preview['reservation']['id'])->lockForUpdate()->firstOrFail();

            if ((int) $shipment->id !== (int) $preview['shipment']['id']
                || (int) $shipment->warehouse_id !== (int) $preview['shipment']['warehouse_id']
                || (string) $shipment->carrier !== (string) $preview['shipment']['carrier']
                || (string) $shipment->tracking_number !== (string) $preview['shipment']['tracking_number']
                || $reservation->status !== 'committed') {
                throw new RuntimeException('Correction local state drifted before apply.');
            }

            $attemptNumber = (int) $order->shipmentAttempts()->max('attempt_number') + 1;
            $packaging = ShipmentItemPackagingSnapshot::query()->where('shipment_id', $shipment->id)->get()->toArray();
            $attempt = ShipmentAttempt::query()->create([
                ...$this->shipmentSnapshot($shipment),
                'order_id' => $order->id,
                'shipment_id' => $shipment->id,
                'attempt_number' => $attemptNumber,
                'snapshotted_by' => null,
                'snapshotted_at' => now(),
                'evidence_snapshot' => [
                    'packaging' => $packaging,
                    'correction' => [
                        'type' => 'production_uat_fulfillment_reconciliation',
                        'remote' => $preview['remote'],
                        'reservation_id' => (int) $reservation->id,
                    ],
                ],
            ]);

            $inventoryResults = [];
            foreach ($reservation->items as $item) {
                $inventory = WarehouseInventory::query()->whereKey($item->warehouse_inventory_id)->lockForUpdate()->firstOrFail();
                $reason = 'uat_fulfillment_correction_reversal_reservation_'.$reservation->id;
                if (InventoryAdjustment::query()->where('warehouse_inventory_id', $inventory->id)->where('reason', $reason)->exists()) {
                    throw new RuntimeException('Inventory correction was already applied.');
                }
                $previewItem = collect($preview['reservation']['items'])
                    ->firstWhere('warehouse_inventory_id', (int) $inventory->id);
                if (! $previewItem
                    || (int) $inventory->quantity_on_hand !== (int) $previewItem['quantity_on_hand']
                    || (int) $inventory->quantity_reserved !== (int) $previewItem['quantity_reserved']) {
                    throw new RuntimeException('Inventory state drifted before correction apply.');
                }
                $beforeQoh = (int) $inventory->quantity_on_hand;
                $reserved = (int) $inventory->quantity_reserved;
                $afterQoh = $beforeQoh + (int) $item->quantity;
                $inventory->forceFill(['quantity_on_hand' => $afterQoh])->save();
                InventoryAdjustment::query()->create([
                    'warehouse_id' => $inventory->warehouse_id,
                    'product_id' => $inventory->product_id,
                    'warehouse_inventory_id' => $inventory->id,
                    'quantity_before' => $beforeQoh,
                    'quantity_after' => $afterQoh,
                    'reserved_before' => $reserved,
                    'reserved_after' => $reserved,
                    'actor_user_id' => null,
                    'reason' => $reason,
                ]);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'inventory.uat_fulfillment_effect_reversed',
                    WarehouseInventory::class,
                    $inventory->id,
                    before: ['quantity_on_hand' => $beforeQoh, 'quantity_reserved' => $reserved],
                    after: ['quantity_on_hand' => $afterQoh, 'quantity_reserved' => $reserved],
                    reason: $reason,
                );
                $inventoryResults[] = ['warehouse_inventory_id' => (int) $inventory->id, 'quantity_restored' => (int) $item->quantity];
            }

            $beforeShipment = $this->shipmentSnapshot($shipment);
            $shipDate = trim((string) ($preview['remote']['ship_date'] ?? ''));
            $note = 'Woo Tracking reconciled from external truth; warehouse unresolved.'
                .($shipDate !== '' ? ' Woo ship date='.$shipDate.' (date-only; exact time unknown).' : '');
            $shipment->forceFill([
                'warehouse_id' => null,
                'warehouse_selection_source' => null,
                'carrier' => $preview['remote']['carrier'],
                'service' => null,
                'carrier_selection_source' => 'external_woocommerce',
                'tracking_number' => $preview['remote']['tracking_number'],
                'shipped_at' => null,
                'logistics_note' => $note,
                'handled_by' => null,
            ])->save();
            $afterShipment = $this->shipmentSnapshot($shipment);
            $this->audit->record(
                AuditActor::system(AuditSource::InternalSystem),
                'shipment.uat_fulfillment_corrected',
                Shipment::class,
                $shipment->id,
                before: $beforeShipment,
                after: $afterShipment,
                reason: 'production_uat_fulfillment_reconciliation_from_woocommerce',
            );

            return [
                'gate' => 'applied',
                'erp_order_id' => $erpOrderId,
                'order_id' => (int) $order->id,
                'shipment_id' => (int) $shipment->id,
                'attempt_id' => (int) $attempt->id,
                'inventory' => $inventoryResults,
                'remote' => $preview['remote'],
            ];
        });
    }

    /** @return array<string,mixed> */
    private function shipmentSnapshot(Shipment $shipment): array
    {
        return [
            'id' => (int) $shipment->id,
            'shipment_status' => (string) $shipment->shipment_status,
            'warehouse_id' => $shipment->warehouse_id === null ? null : (int) $shipment->warehouse_id,
            'warehouse_selection_source' => $shipment->warehouse_selection_source,
            'carrier' => $shipment->carrier,
            'service' => $shipment->service,
            'carrier_selection_source' => $shipment->carrier_selection_source,
            'tracking_number' => $shipment->tracking_number,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'logistics_note' => $shipment->logistics_note,
            'handled_by' => $shipment->handled_by,
        ];
    }

    private function carrierForProvider(string $provider): ?string
    {
        foreach ((array) config('erp_logistics.carriers', []) as $definition) {
            if (strtolower(trim((string) ($definition['aftership_slug'] ?? ''))) === strtolower(trim($provider))) {
                $carrier = trim((string) ($definition['value'] ?? ''));

                return $carrier !== '' ? $carrier : null;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function blocked(string $erpOrderId, string $reason, ?Order $order = null, array $extra = []): array
    {
        return [
            'decision' => 'blocked',
            'reason' => $reason,
            'erp_order_id' => $erpOrderId,
            'order_id' => $order?->id,
            ...$extra,
        ];
    }
}
