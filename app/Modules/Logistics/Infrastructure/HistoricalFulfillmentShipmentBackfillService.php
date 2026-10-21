<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\HistoricalFulfillmentReconciliationClassifier;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class HistoricalFulfillmentShipmentBackfillService
{
    public const PLAN_VERSION = 'hfr-customer-shipment-backfill-v2';

    private const COMPATIBLE_STATUSES = ['shipped', 'completed', 'completed_delivered'];

    public function __construct(
        private readonly HistoricalFulfillmentReconciliationClassifier $classifier,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $path): array
    {
        [$real, $sourceChecksum, $sourceScanSha, $candidates] = $this->readSource($path);
        $candidateKeyCounts = array_count_values(array_map(
            fn (array $candidate): string => trim((string) ($candidate['candidate_key'] ?? '')),
            $candidates,
        ));
        $trackingCounts = [];
        foreach ($candidates as $candidate) {
            $tracking = $this->tracking($candidate);
            if ($tracking !== null) {
                $trackingCounts[$tracking] = ($trackingCounts[$tracking] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($candidates as $candidate) {
            $result = $this->classifier->classify($candidate);
            $row = $this->row($candidate, $result);
            $key = (string) $result['candidate_key'];
            $tracking = $this->tracking($candidate);
            if (($candidateKeyCounts[$key] ?? 0) > 1) {
                $row = $this->review($row, 'plan_duplicate_candidate_key');
            } elseif ($result['decision'] === 'ready' && $tracking !== null && ($trackingCounts[$tracking] ?? 0) > 1) {
                $row = $this->review($row, 'plan_duplicate_ready_tracking');
            } elseif ($result['decision'] === 'ready') {
                $row = $this->databaseDecision($candidate, $row, false);
            }
            $rows[] = $row + ['candidate' => $candidate];
        }

        $readyByOrder = [];
        foreach ($rows as $index => $row) {
            if (($row['decision'] ?? null) !== 'ready') {
                continue;
            }
            $orderId = (int) data_get($row, 'order_identity.id', 0);
            if ($orderId > 0) {
                $readyByOrder[$orderId][] = $index;
            }
        }
        foreach ($readyByOrder as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            foreach ($indexes as $index) {
                $rows[$index] = $this->review($rows[$index], 'plan_multiple_ready_candidates_for_order');
            }
        }

        $manifest = array_map(fn (array $row): array => $this->manifestRow($row), $rows);
        usort($manifest, fn (array $a, array $b): int => strcmp($a['candidate_key'], $b['candidate_key']));
        $planChecksum = hash('sha256', json_encode([
            'version' => self::PLAN_VERSION,
            'source_checksum' => $sourceChecksum,
            'source_scan_sha256' => $sourceScanSha,
            'rows' => $manifest,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $counts = ['ready' => 0, 'review' => 0, 'skip' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['decision']]++;
        }

        return [
            'mode' => 'dry_run', 'original_filename' => basename($real),
            'source_checksum' => $sourceChecksum, 'source_scan_sha256' => $sourceScanSha,
            'plan_version' => self::PLAN_VERSION, 'plan_checksum' => $planChecksum,
            'total_candidates' => count($rows), 'ready_count' => $counts['ready'],
            'review_count' => $counts['review'], 'skip_count' => $counts['skip'], 'rows' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $path): array
    {
        $preview = $this->preview($path);
        if ((int) $preview['ready_count'] <= 0) {
            throw new RuntimeException('hfr3_apply_blocked:no_ready_candidates');
        }

        $created = DB::transaction(function () use ($preview): int {
            $created = 0;
            foreach ($preview['rows'] as $row) {
                if (($row['decision'] ?? null) !== 'ready') {
                    continue;
                }
                $candidate = $row['candidate'];
                $fresh = $this->classifier->classify($candidate);
                if (($fresh['classification'] ?? null) !== HistoricalFulfillmentReconciliationClassifier::READY) {
                    throw new RuntimeException('hfr3_preflight_drift:classifier_not_ready');
                }
                $locked = $this->databaseDecision($candidate, $this->row($candidate, $fresh), true);
                if (($locked['decision'] ?? null) !== 'ready') {
                    throw new RuntimeException('hfr3_preflight_drift:'.($locked['reason_code'] ?? 'unknown'));
                }
                $created += $this->createShipment($candidate, $preview, $locked);
            }

            return $created;
        });

        return array_replace($preview, ['mode' => 'apply', 'shipments_created' => $created]);
    }

    /** @return array{0:string,1:string,2:string,3:list<array<string,mixed>>} */
    private function readSource(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new InvalidArgumentException('HFR3 source file is not readable.');
        }
        $checksum = hash_file('sha256', $real);
        if (! is_string($checksum)) {
            throw new InvalidArgumentException('Unable to checksum HFR3 source.');
        }
        $decoded = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('HFR3 source JSON must be an object.');
        }
        $sourceScanSha = strtolower(trim((string) ($decoded['source_scan_sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceScanSha) !== 1) {
            throw new InvalidArgumentException('HFR3 source_scan_sha256 is required.');
        }
        $candidates = $decoded['candidates'] ?? null;
        if (! is_array($candidates)) {
            throw new InvalidArgumentException('HFR3 candidates must be an array.');
        }
        if (count($candidates) > 10000) {
            throw new InvalidArgumentException('HFR3 candidate count exceeds limit.');
        }
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                throw new InvalidArgumentException('HFR3 candidate must be an object.');
            }
        }

        return [$real, $checksum, $sourceScanSha, array_values($candidates)];
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $row @return array<string,mixed> */
    private function databaseDecision(array $candidate, array $row, bool $locked): array
    {
        $orderId = (int) data_get($row, 'order_identity.id', 0);
        $erpOrderId = trim((string) data_get($row, 'order_identity.erp_order_id', ''));
        $shipment = is_array($row['proposed_shipment'] ?? null) ? $row['proposed_shipment'] : [];
        $warehouseId = (int) ($shipment['warehouse_id'] ?? 0);
        $tracking = strtoupper(trim((string) ($shipment['tracking_number'] ?? '')));

        $orderQuery = Order::query()->whereKey($orderId);
        $warehouseQuery = Warehouse::query()->whereKey($warehouseId);
        if ($locked) {
            $orderQuery->lockForUpdate();
            $warehouseQuery->lockForUpdate();
        }
        $order = $orderQuery->first();
        if ($order === null) {
            return $this->review($row, 'order_not_found');
        }
        if ((string) $order->erp_order_id !== $erpOrderId) {
            return $this->review($row, 'order_identity_mismatch');
        }
        if ($order->order_kind?->value !== 'customer') {
            return $this->review($row, 'order_kind_not_customer');
        }
        if (! in_array($order->order_status?->value, self::COMPATIBLE_STATUSES, true)) {
            return $this->review($row, 'order_status_incompatible');
        }
        $warehouse = $warehouseQuery->first();
        if ($warehouse === null) {
            return $this->review($row, 'warehouse_not_found');
        }
        if (! $warehouse->is_active) {
            return $this->review($row, 'warehouse_inactive');
        }
        if ($tracking === '') {
            return $this->review($row, 'tracking_missing');
        }

        $existingQuery = Shipment::query()->where('order_id', $order->id);
        $attemptOrderQuery = ShipmentAttempt::query()->where('order_id', $order->id);
        $trackingShipmentQuery = Shipment::query()->where('tracking_number', $tracking);
        $trackingAttemptQuery = ShipmentAttempt::query()->where('tracking_number', $tracking);
        if ($locked) {
            $existingQuery->lockForUpdate();
            $attemptOrderQuery->lockForUpdate();
            $trackingShipmentQuery->lockForUpdate();
            $trackingAttemptQuery->lockForUpdate();
        }
        $existing = $existingQuery->first();
        if ($existing !== null) {
            if ($this->sameShipment($existing, $shipment)) {
                return $this->skip($row, 'already_applied', (int) $existing->id, (int) $warehouse->id);
            }

            return $this->review($row, 'existing_shipment_conflict');
        }
        if ($attemptOrderQuery->exists()) {
            return $this->review($row, 'shipment_attempt_exists');
        }
        if ($trackingShipmentQuery->exists()) {
            return $this->review($row, 'tracking_conflict');
        }
        if ($trackingAttemptQuery->exists()) {
            return $this->review($row, 'tracking_attempt_conflict');
        }

        $shippedAt = $this->timestamp($shipment['shipped_at'] ?? null);
        if ($shippedAt === null) {
            return $this->review($row, 'shipped_at_invalid');
        }
        if (trim((string) ($shipment['carrier'] ?? '')) === '' || trim((string) ($shipment['service'] ?? '')) === '') {
            return $this->review($row, 'carrier_service_missing');
        }

        return array_replace($row, ['warehouse_id' => (int) $warehouse->id, 'normalized_shipped_at' => $shippedAt]);
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $preview @param array<string,mixed> $row */
    private function createShipment(array $candidate, array $preview, array $row): int
    {
        $orderId = (int) data_get($row, 'order_identity.id', 0);
        $shipmentData = $row['proposed_shipment'];
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $shipment = $order->shipment()->create([
            'shipment_status' => 'shipped',
            'warehouse_id' => (int) $shipmentData['warehouse_id'],
            'warehouse_selection_source' => 'historical_exact',
            'carrier' => (string) $shipmentData['carrier'],
            'service' => (string) $shipmentData['service'],
            'carrier_selection_source' => 'historical_exact',
            'tracking_number' => strtoupper((string) $shipmentData['tracking_number']),
            'shipped_at' => $row['normalized_shipped_at'],
            'logistics_note' => $this->note($candidate, $preview),
            'handled_by' => null,
        ]);
        $this->audit->record(
            AuditActor::system(AuditSource::InternalSystem),
            'logistics.hfr3_customer_shipment_backfill.applied',
            Shipment::class,
            $shipment->id,
            null,
            [
                'order_id' => (int) $order->id,
                'erp_order_id' => (string) $order->erp_order_id,
                'shipment_id' => (int) $shipment->id,
                'candidate_key' => (string) $candidate['candidate_key'],
                'evidence_source' => (string) $candidate['evidence_source'],
                'source_checksum' => (string) $preview['source_checksum'],
                'source_scan_sha256' => (string) $preview['source_scan_sha256'],
                'plan_checksum' => (string) $preview['plan_checksum'],
            ],
            'historical_fulfillment_reconciliation_hfr3',
        );

        return 1;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $result @return array<string,mixed> */
    private function row(array $candidate, array $result): array
    {
        return [
            'candidate_key' => (string) $result['candidate_key'],
            'evidence_source' => (string) $result['evidence_source'],
            'order_identity' => $result['order_identity'],
            'classification' => (string) $result['classification'],
            'decision' => (string) $result['decision'],
            'reason_codes' => array_values($result['reason_codes'] ?? []),
            'reason_code' => $result['reason_codes'][0] ?? null,
            'proposed_shipment' => $result['proposed_shipment'],
            'shipment_id' => null,
            'warehouse_id' => null,
            'normalized_shipped_at' => null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function review(array $row, string $reason): array
    {
        $row['decision'] = 'review';
        $row['reason_code'] = $reason;
        $row['reason_codes'] = array_values(array_unique(array_merge($row['reason_codes'] ?? [], [$reason])));

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function skip(array $row, string $reason, ?int $shipmentId = null, ?int $warehouseId = null): array
    {
        $row['decision'] = 'skip';
        $row['reason_code'] = $reason;
        $row['reason_codes'] = array_values(array_unique(array_merge($row['reason_codes'] ?? [], [$reason])));
        $row['shipment_id'] = $shipmentId;
        $row['warehouse_id'] = $warehouseId;

        return $row;
    }

    /** @param array<string,mixed> $proposed */
    private function sameShipment(Shipment $shipment, array $proposed): bool
    {
        $existingTime = $shipment->shipped_at?->utc()->format('Y-m-d H:i:s');

        return $shipment->shipment_status === 'shipped'
            && (int) $shipment->warehouse_id === (int) ($proposed['warehouse_id'] ?? 0)
            && $shipment->warehouse_selection_source === 'historical_exact'
            && (string) $shipment->carrier === (string) ($proposed['carrier'] ?? '')
            && (string) $shipment->service === (string) ($proposed['service'] ?? '')
            && $shipment->carrier_selection_source === 'historical_exact'
            && strtoupper((string) $shipment->tracking_number) === strtoupper((string) ($proposed['tracking_number'] ?? ''))
            && $existingTime === $this->timestamp($proposed['shipped_at'] ?? null);
    }

    private function timestamp(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $candidate */
    private function tracking(array $candidate): ?string
    {
        $value = strtoupper(trim((string) data_get($candidate, 'evidence.tracking_number', '')));

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function manifestRow(array $row): array
    {
        return [
            'candidate_key' => $row['candidate_key'], 'order_identity' => $row['order_identity'],
            'classification' => $row['classification'], 'decision' => $row['decision'],
            'reason_codes' => $row['reason_codes'], 'proposed_shipment' => $row['proposed_shipment'],
            'shipment_id' => $row['shipment_id'], 'warehouse_id' => $row['warehouse_id'],
            'normalized_shipped_at' => $row['normalized_shipped_at'],
        ];
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $preview */
    private function note(array $candidate, array $preview): string
    {
        return 'HFR3 historical customer Shipment backfill'
            .'; candidate_key='.(string) $candidate['candidate_key']
            .'; evidence_source='.(string) $candidate['evidence_source']
            .'; source_sha='.(string) $preview['source_checksum']
            .'; source_scan_sha='.(string) $preview['source_scan_sha256']
            .'; plan_sha='.(string) $preview['plan_checksum'];
    }
}
