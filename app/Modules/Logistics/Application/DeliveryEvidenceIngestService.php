<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Application\Contracts\DeliveryEvidenceGateway;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentCarrierBilling;
use App\Modules\Sync\Application\WooCommerceOrderStatusOutboundService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DeliveryEvidenceIngestService
{
    public function __construct(
        private readonly DeliveryEvidenceGateway $gateway,
        private readonly AuditRecorder $audit,
        private readonly ShipmentAggregateStatusService $aggregateStatus,
        private readonly WooCommerceOrderStatusOutboundService $orderStatusOutbound,
    ) {}

    /** @return array<string,mixed> */
    public function preview(int $shipmentId): array
    {
        $shipment = Shipment::query()->with(['order', 'billingEvidence'])->findOrFail($shipmentId);
        if (! in_array($shipment->shipment_status, ['shipped', 'exception', 'completed'], true)) {
            return $this->blocked($shipment, 'shipment_not_trackable');
        }

        $trackingNumber = trim((string) $shipment->tracking_number);
        if ($trackingNumber === '') {
            return $this->blocked($shipment, 'tracking_number_missing');
        }

        $slug = $this->afterShipSlug((string) $shipment->carrier);
        if ($slug === null) {
            return $this->blocked($shipment, 'aftership_slug_missing');
        }

        $descriptor = $this->gateway->descriptor();
        if (! ($descriptor['configured'] ?? false)) {
            return $this->blocked($shipment, 'delivery_source_not_configured', ['source' => $descriptor]);
        }

        $remote = $this->gateway->inspect($slug, $trackingNumber);
        if (! ($remote['found'] ?? false)) {
            return $this->blocked($shipment, 'aftership_tracking_not_registered', ['source' => $descriptor, 'remote' => $remote]);
        }

        $deliveryStatus = $this->normalizeTag((string) ($remote['tag'] ?? ''));
        if ($deliveryStatus === null) {
            return $this->blocked($shipment, 'aftership_tag_unsupported', ['source' => $descriptor, 'remote' => $remote]);
        }

        $billing = $shipment->billingEvidence;
        $evidence = $this->evidencePayload($descriptor, $remote, $deliveryStatus);
        $currentEvidence = is_array($billing?->raw_evidence) ? ($billing->raw_evidence['delivery_evidence'] ?? null) : null;
        $decision = $billing?->delivery_status === $deliveryStatus && $currentEvidence === $evidence ? 'noop' : 'change';
        $fingerprintPayload = [
            'shipment_id' => (int) $shipment->getKey(),
            'shipment_status' => $shipment->shipment_status,
            'carrier' => $shipment->carrier,
            'tracking_number' => $trackingNumber,
            'current_delivery_status' => $billing?->delivery_status,
            'current_delivery_evidence' => $currentEvidence,
            'target_delivery_status' => $deliveryStatus,
            'remote' => $remote,
        ];

        return [
            'decision' => $decision,
            'reason' => $decision === 'noop' ? 'delivery_evidence_current' : 'delivery_evidence_change',
            'shipment_id' => (int) $shipment->getKey(),
            'erp_order_id' => $shipment->order?->erp_order_id,
            'shipment_status' => $shipment->shipment_status,
            'carrier' => $shipment->carrier,
            'tracking_number' => $trackingNumber,
            'current_delivery_status' => $billing?->delivery_status,
            'current_delivery_evidence' => $currentEvidence,
            'target_delivery_status' => $deliveryStatus,
            'source' => $descriptor,
            'remote' => $remote,
            'evidence' => $evidence,
            'fingerprint' => hash('sha256', (string) json_encode($fingerprintPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /** @return array<string,mixed> */
    public function apply(int $shipmentId, string $expectedFingerprint): array
    {
        $preview = $this->preview($shipmentId);
        if (($preview['decision'] ?? null) !== 'change') {
            throw new RuntimeException('Delivery evidence preview is not change.');
        }
        if (! hash_equals((string) $preview['fingerprint'], $expectedFingerprint)) {
            throw new RuntimeException('Delivery evidence fingerprint mismatch.');
        }

        $result = DB::transaction(function () use ($shipmentId, $preview, $expectedFingerprint): array {
            $shipment = Shipment::query()->whereKey($shipmentId)->lockForUpdate()->firstOrFail();
            if ((string) $shipment->carrier !== (string) $preview['carrier']
                || trim((string) $shipment->tracking_number) !== (string) $preview['tracking_number']
                || (string) $shipment->shipment_status !== (string) $preview['shipment_status']) {
                throw new RuntimeException('Shipment changed after delivery evidence preview.');
            }

            $billing = ShipmentCarrierBilling::query()->where('shipment_id', $shipmentId)->lockForUpdate()->first();
            $lockedEvidence = is_array($billing?->raw_evidence) ? ($billing->raw_evidence['delivery_evidence'] ?? null) : null;
            if (($billing?->delivery_status) !== ($preview['current_delivery_status'] ?? null)
                || $lockedEvidence !== ($preview['current_delivery_evidence'] ?? null)) {
                throw new RuntimeException('Delivery evidence changed after preview.');
            }
            $before = $billing?->only(['delivery_status', 'data_source', 'provider_reference', 'raw_evidence']);
            $billing ??= new ShipmentCarrierBilling(['shipment_id' => $shipmentId]);
            $rawEvidence = is_array($billing->raw_evidence) ? $billing->raw_evidence : [];
            $rawEvidence['delivery_evidence'] = $preview['evidence'];
            $billing->fill([
                'delivery_status' => $preview['target_delivery_status'],
                'data_source' => filled($billing->data_source) ? $billing->data_source : (string) ($preview['source']['source'] ?? 'aftership_branded_page'),
                'provider_reference' => filled($billing->provider_reference)
                    ? $billing->provider_reference
                    : ($preview['remote']['provider_reference'] ?? null),
                'raw_evidence' => $rawEvidence,
            ]);
            $billing->save();

            $after = $billing->only(['delivery_status', 'data_source', 'provider_reference', 'raw_evidence']);
            $this->audit->record(
                AuditActor::system(AuditSource::InternalSystem),
                'shipment.delivery_evidence_ingested',
                ShipmentCarrierBilling::class,
                $billing->getKey(),
                before: $before,
                after: $after,
                reason: (string) ($preview['source']['source'] ?? 'aftership_branded_page'),
            );

            return [
                'gate' => 'applied',
                'fingerprint' => $expectedFingerprint,
                'shipment_id' => $shipmentId,
                'billing_id' => (int) $billing->getKey(),
                'delivery_status' => $billing->delivery_status,
                'source' => (string) ($preview['source']['source'] ?? 'aftership_branded_page'),
            ];
        });

        $shipment = Shipment::query()->with('order')->findOrFail($shipmentId);
        $aggregate = $this->aggregateStatus->reconcile($shipment->order);
        $statusSync = $this->orderStatusOutbound->pushOne($shipment->order);

        return [
            ...$result,
            'aggregate' => $aggregate['aggregate'],
            'woo_status' => $aggregate['woo_status'],
            'woo_status_sync' => $statusSync['status'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function evidencePayload(array $descriptor, array $remote, string $deliveryStatus): array
    {
        return array_filter([
            'source' => $descriptor['source'] ?? 'aftership_branded_page',
            'version' => $descriptor['version'] ?? null,
            'provider_reference' => $remote['provider_reference'] ?? null,
            'tracking_number' => $remote['tracking_number'] ?? null,
            'slug' => $remote['slug'] ?? null,
            'tag' => $remote['tag'] ?? null,
            'subtag' => $remote['subtag'] ?? null,
            'subtag_message' => $remote['subtag_message'] ?? null,
            'provider_updated_at' => $remote['provider_updated_at'] ?? null,
            'checkpoint' => $remote['checkpoint'] ?? null,
            'normalized_delivery_status' => $deliveryStatus,
        ], static fn ($value): bool => $value !== null);
    }

    private function normalizeTag(string $tag): ?string
    {
        return match (strtolower(trim($tag))) {
            'delivered' => 'delivered',
            'exception', 'attemptfail', 'expired' => 'exception',
            'pending', 'inforeceived', 'intransit', 'outfordelivery', 'availableforpickup' => 'in_transit',
            default => null,
        };
    }

    private function afterShipSlug(string $carrier): ?string
    {
        $carrier = strtolower(trim($carrier));
        foreach ((array) config('erp_logistics.carriers', []) as $definition) {
            $names = [strtolower(trim((string) ($definition['value'] ?? '')))];
            foreach ((array) ($definition['aliases'] ?? []) as $alias) {
                $names[] = strtolower(trim((string) $alias));
            }
            if (in_array($carrier, $names, true)) {
                $slug = trim((string) ($definition['aftership_slug'] ?? ''));

                return $slug === '' ? null : $slug;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function blocked(Shipment $shipment, string $reason, array $extra = []): array
    {
        return [
            'decision' => 'blocked',
            'reason' => $reason,
            'shipment_id' => (int) $shipment->getKey(),
            'erp_order_id' => $shipment->order?->erp_order_id,
            'shipment_status' => $shipment->shipment_status,
            'carrier' => $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            ...$extra,
        ];
    }
}
