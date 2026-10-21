<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueOrderProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueOrderProjectionRow;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueShipmentAttemptProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HistoricalRescueShipmentAttemptProjectionService
{
    private const VERSION = 'historical-rescue-shipment-attempt-projection-v1';
    private const RESCUE_VERSION = 'historical-review-rescue-v1';
    private const PROVIDER = 'historical_logistics';

    private const WAREHOUSES = [
        'USA 美西仓' => 'US-LA',
        '美东仓' => 'US-NY',
        'Factory 国内仓' => 'FACTORY',
        'Canada 加拿大仓' => 'CA',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function preview(HistoricalRescueOrderProjectionBatch $orderBatch, Authenticatable $actor): HistoricalRescueShipmentAttemptProjectionBatch
    {
        $this->authorize($actor);
        if ($orderBatch->status !== 'applied') {
            throw ValidationException::withMessages(['batch' => 'Historical rescue shipment attempt projection requires an applied rescue order projection batch.']);
        }

        return DB::transaction(function () use ($orderBatch, $actor): HistoricalRescueShipmentAttemptProjectionBatch {
            $batch = HistoricalRescueShipmentAttemptProjectionBatch::firstOrCreate(
                [
                    'rescue_order_projection_batch_id' => $orderBatch->id,
                    'source_checksum' => $orderBatch->source_checksum,
                    'projection_version' => self::VERSION,
                ],
                ['status' => 'preview', 'created_by' => $actor->getAuthIdentifier()],
            );
            if (! $batch->wasRecentlyCreated) {
                return $batch->fresh('rows');
            }

            $plan = $this->buildPlan($orderBatch);
            foreach ($plan['rows'] as $row) {
                $batch->rows()->create([
                    'rescue_order_projection_row_id' => $row['rescue_order_projection_row_id'],
                    'historical_evidence_id' => $row['historical_evidence_id'],
                    'order_id' => $row['order_id'],
                    'source_row_number' => $row['source_row_number'],
                    'attempt_number' => $row['attempt_number'],
                    'decision' => $row['decision'],
                    'decision_reason' => $row['reason'],
                    'projection_manifest' => $row['manifest'],
                ]);
            }
            $batch->update([
                'total_rows' => count($plan['rows']),
                'ready_count' => $plan['ready_count'],
                'review_count' => $plan['review_count'],
                'ready_manifest_sha256' => $plan['ready_manifest_sha256'],
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'logistics.historical_rescue_shipment_attempt_projection.previewed',
                'logistics_hist_rescue_attempt_projection_batch',
                $batch->id,
                null,
                [
                    'source_checksum' => $batch->source_checksum,
                    'ready_count' => $plan['ready_count'],
                    'review_count' => $plan['review_count'],
                    'ready_manifest_sha256' => $plan['ready_manifest_sha256'],
                ],
                'historical_rescue_shipment_attempt_projection_preview',
            );

            return $batch->fresh('rows');
        });
    }

    public function inspect(HistoricalRescueOrderProjectionBatch $orderBatch): array
    {
        if ($orderBatch->status !== 'applied') {
            throw ValidationException::withMessages(['batch' => 'Historical rescue shipment attempt inspection requires an applied rescue order projection batch.']);
        }

        return $this->buildPlan($orderBatch);
    }

    public function apply(HistoricalRescueShipmentAttemptProjectionBatch $batch, Authenticatable $actor): HistoricalRescueShipmentAttemptProjectionBatch
    {
        $this->authorize($actor);
        if (! config('logistics.historical_rescue_shipment_attempt_apply_enabled', false)) {
            abort(403, 'Historical rescue shipment attempt projection is disabled in this environment.');
        }
        return DB::transaction(function () use ($batch, $actor): HistoricalRescueShipmentAttemptProjectionBatch {
            $locked = HistoricalRescueShipmentAttemptProjectionBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->with(['rescueOrderProjectionBatch', 'rows'])
                ->firstOrFail();

            if ($locked->status === 'applied') {
                return $locked->fresh('rows');
            }
            if ($locked->status !== 'preview' || $locked->rescueOrderProjectionBatch->status !== 'applied') {
                throw ValidationException::withMessages(['batch' => 'Historical rescue shipment attempt projection batch is not applyable.']);
            }

            $sourceRows = HistoricalRescueOrderProjectionRow::query()
                ->where('projection_batch_id', $locked->rescue_order_projection_batch_id)
                ->lockForUpdate()
                ->get();
            $evidenceIds = $sourceRows->flatMap(fn (HistoricalRescueOrderProjectionRow $row): array => array_column($row->projection_manifest['evidence'] ?? [], 'historical_evidence_id'))
                ->filter()->unique()->values();
            HistoricalEvidence::query()->whereIn('id', $evidenceIds)->lockForUpdate()->get();
            $sourceOrderIds = $sourceRows->pluck('order_id')->filter()->unique()->values();
            Order::query()->whereIn('id', $sourceOrderIds)->lockForUpdate()->get(['id']);
            $current = $this->buildPlan($locked->rescueOrderProjectionBatch);
            $storedRows = $locked->rows->keyBy('historical_evidence_id');
            if (count($current['rows']) !== $storedRows->count() || $current['ready_manifest_sha256'] !== $locked->ready_manifest_sha256) {
                throw ValidationException::withMessages(['batch' => 'Frozen historical rescue shipment attempt evidence drifted; re-preview is required.']);
            }

            foreach ($current['rows'] as $row) {
                $stored = $storedRows->get($row['historical_evidence_id']);
                if ($stored === null
                    || (int) $stored->order_id !== $row['order_id']
                    || (int) $stored->attempt_number !== $row['attempt_number']
                    || $stored->decision !== $row['decision']
                    || $stored->decision_reason !== $row['reason']
                    || ! $this->manifestsEqual($stored->projection_manifest, $row['manifest'])) {
                    throw ValidationException::withMessages(['batch' => 'Frozen historical rescue shipment attempt evidence drifted; re-preview is required.']);
                }
            }

            $readyRows = $locked->rows->where('decision', 'ready')->sortBy([
                ['source_row_number', 'asc'],
                ['attempt_number', 'asc'],
            ]);
            $orderIds = $readyRows->pluck('order_id')->unique()->values();
            $trackings = $readyRows->map(fn ($row): string => (string) ($row->projection_manifest['tracking_number'] ?? ''))->filter()->unique()->values();
            ShipmentAttempt::query()->whereIn('order_id', $orderIds)->lockForUpdate()->get();
            Shipment::query()->whereIn('order_id', $orderIds)->lockForUpdate()->get();

            foreach ($readyRows as $row) {
                $manifest = $row->projection_manifest;
                $tracking = (string) $manifest['tracking_number'];
                if (ShipmentAttempt::query()->where('order_id', $row->order_id)->where('attempt_number', $row->attempt_number)->exists()) {
                    throw ValidationException::withMessages(['batch' => 'A frozen historical rescue attempt number now exists; re-preview is required.']);
                }
                if (ShipmentAttempt::query()->where('tracking_number', $tracking)->exists()) {
                    throw ValidationException::withMessages(['batch' => 'A frozen historical rescue tracking number now exists; re-preview is required.']);
                }
                if (Shipment::query()->where('tracking_number', $tracking)->exists()) {
                    throw ValidationException::withMessages(['batch' => 'A frozen historical rescue tracking number now exists on a shipment; re-preview is required.']);
                }
                if (Shipment::query()->where('order_id', $row->order_id)->whereNotIn('shipment_status', ['cancelled', 'completed'])->exists()) {
                    throw ValidationException::withMessages(['batch' => 'A frozen historical rescue order now has an active shipment; re-preview is required.']);
                }
            }
            $applied = 0;
            foreach ($readyRows as $row) {
                $manifest = $row->projection_manifest;
                $attempt = ShipmentAttempt::query()->create([
                    'order_id' => $row->order_id,
                    'shipment_id' => null,
                    'attempt_number' => $row->attempt_number,
                    'shipment_status' => 'historical_evidence',
                    'warehouse_id' => $manifest['warehouse_id'],
                    'warehouse_selection_source' => $manifest['warehouse_selection_source'],
                    'carrier' => null,
                    'service' => null,
                    'carrier_selection_source' => null,
                    'tracking_number' => $manifest['tracking_number'],
                    'shipped_at' => null,
                    'logistics_note' => 'Historical rescue logistics evidence #'.$row->historical_evidence_id,
                    'handled_by' => null,
                    'snapshotted_by' => $actor->getAuthIdentifier(),
                    'snapshotted_at' => now(),
                    'evidence_snapshot' => [
                        'projection_manifest' => $manifest,
                        'provenance' => [
                            'rescue_order_projection_batch_id' => $locked->rescue_order_projection_batch_id,
                            'rescue_order_projection_row_id' => $row->rescue_order_projection_row_id,
                            'historical_evidence_id' => $row->historical_evidence_id,
                            'rescue_attempt_projection_batch_id' => $locked->id,
                        ],
                    ],
                ]);
                $row->update(['shipment_attempt_id' => $attempt->id, 'decision' => 'applied', 'decision_reason' => 'materialized']);
                $applied++;
            }

            $locked->update(['status' => 'applied', 'applied_count' => $applied]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'logistics.historical_rescue_shipment_attempt_projection.applied',
                'logistics_hist_rescue_attempt_projection_batch',
                $locked->id,
                null,
                ['source_checksum' => $locked->source_checksum, 'applied_count' => $applied, 'ready_manifest_sha256' => $locked->ready_manifest_sha256],
                'historical_rescue_shipment_attempt_projection_apply',
            );

            return $locked->fresh('rows');
        });
    }
    private function buildPlan(HistoricalRescueOrderProjectionBatch $orderBatch): array
    {
        $sourceRows = HistoricalRescueOrderProjectionRow::query()
            ->where('projection_batch_id', $orderBatch->id)
            ->where('decision', 'applied')
            ->with('order')
            ->orderBy('source_row_number')
            ->get();

        $legSpecs = collect();
        foreach ($sourceRows as $sourceRow) {
            $evidenceSpecs = collect($sourceRow->projection_manifest['evidence'] ?? [])->sortBy('leg_index')->values();
            foreach ($evidenceSpecs as $spec) {
                $legSpecs->push([
                    'source_row' => $sourceRow,
                    'spec' => $spec,
                    'historical_evidence_id' => (int) ($spec['historical_evidence_id'] ?? 0),
                    'attempt_number' => (int) ($spec['leg_index'] ?? 0),
                ]);
            }
        }

        $evidenceIds = $legSpecs->pluck('historical_evidence_id')->filter()->unique()->values();
        $evidences = HistoricalEvidence::query()->whereIn('id', $evidenceIds)->get()->keyBy('id');
        $trackingKeys = [];
        foreach ($legSpecs as $leg) {
            $evidence = $evidences->get($leg['historical_evidence_id']);
            $key = $this->trackingKey((string) ($evidence?->normalized_tracking_candidate ?? ''));
            if ($key !== '') {
                $trackingKeys[$key] = ($trackingKeys[$key] ?? 0) + 1;
            }
        }

        $trackings = $evidences->pluck('normalized_tracking_candidate')->map(fn ($tracking): string => trim((string) $tracking))->filter()->unique()->values();
        $attemptTracking = ShipmentAttempt::query()->whereIn('tracking_number', $trackings)->pluck('tracking_number')
            ->mapWithKeys(fn (string $tracking): array => [$this->trackingKey($tracking) => true]);
        $shipmentTracking = Shipment::query()->whereIn('tracking_number', $trackings)->pluck('tracking_number')
            ->mapWithKeys(fn (string $tracking): array => [$this->trackingKey($tracking) => true]);
        $orderIds = $sourceRows->pluck('order_id')->filter()->unique()->values();
        $attemptNumbers = ShipmentAttempt::query()->whereIn('order_id', $orderIds)->get(['order_id', 'attempt_number'])
            ->mapWithKeys(fn (ShipmentAttempt $attempt): array => [$attempt->order_id.':'.$attempt->attempt_number => true]);
        $activeShipmentOrders = Shipment::query()->whereIn('order_id', $orderIds)->whereNotIn('shipment_status', ['cancelled', 'completed'])->pluck('order_id')->flip();

        $warehouseCodes = array_values(array_unique(self::WAREHOUSES));
        $warehouses = Warehouse::query()->whereIn('code', $warehouseCodes)->get()->keyBy('code');
        $rows = [];
        foreach ($legSpecs as $leg) {
            /** @var HistoricalRescueOrderProjectionRow $sourceRow */
            $sourceRow = $leg['source_row'];
            $evidence = $evidences->get($leg['historical_evidence_id']);
            $rows[] = $this->decideLeg(
                $orderBatch,
                $sourceRow,
                $leg['spec'],
                $evidence,
                $leg['attempt_number'],
                $trackingKeys,
                $attemptTracking,
                $shipmentTracking,
                $attemptNumbers,
                $activeShipmentOrders,
                $warehouses,
            );
        }

        $ready = array_values(array_filter($rows, fn (array $row): bool => $row['decision'] === 'ready'));
        $review = array_values(array_filter($rows, fn (array $row): bool => $row['decision'] === 'review'));
        $readyManifests = array_map(fn (array $row): array => $row['manifest'], $ready);

        return [
            'rows' => $rows,
            'ready_count' => count($ready),
            'review_count' => count($review),
            'ready_manifest_sha256' => hash(
                'sha256',
                json_encode($this->canonicalize($readyManifests), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
        ];
    }

    private function decideLeg(
        HistoricalRescueOrderProjectionBatch $orderBatch,
        HistoricalRescueOrderProjectionRow $sourceRow,
        array $spec,
        ?HistoricalEvidence $evidence,
        int $attemptNumber,
        array $trackingCounts,
        Collection $attemptTracking,
        Collection $shipmentTracking,
        Collection $attemptNumbers,
        Collection $activeShipmentOrders,
        Collection $warehouses,
    ): array {
        $tracking = trim((string) ($evidence?->normalized_tracking_candidate ?? ''));
        $trackingKey = $this->trackingKey($tracking);
        $snapshot = $evidence?->evidence_snapshot ?? [];
        $rawWarehouse = $snapshot['source_warehouse_raw'] ?? null;
        $warehouseCode = self::WAREHOUSES[(string) $rawWarehouse] ?? null;
        /** @var Warehouse|null $warehouse */
        $warehouse = $warehouseCode !== null ? $warehouses->get($warehouseCode) : null;
        $warehouseResolution = $warehouseCode === null ? 'unresolved' : ($warehouse === null ? 'master_missing' : 'exact');
        $manifest = [
            'version' => self::VERSION,
            'rescue_version' => self::RESCUE_VERSION,
            'rescue_order_projection_batch_id' => (int) $orderBatch->id,
            'rescue_order_projection_row_id' => (int) $sourceRow->id,
            'historical_evidence_id' => (int) ($evidence?->id ?? 0),
            'historical_evidence_hash' => (string) ($evidence?->evidence_hash ?? ''),
            'source_checksum' => (string) $orderBatch->source_checksum,
            'source_row_number' => (int) $sourceRow->source_row_number,
            'source_order_no' => $sourceRow->source_order_no,
            'order_id' => (int) $sourceRow->order_id,
            'attempt_number' => $attemptNumber,
            'tracking_number' => $tracking,
            'warehouse_resolution' => $warehouseResolution,
            'warehouse_code' => $warehouseCode,
            'warehouse_id' => $warehouse?->id,
            'warehouse_selection_source' => $warehouse !== null ? 'historical_exact' : null,
            'raw_warehouse' => $rawWarehouse,
            'raw_agent' => $snapshot['source_carrier_raw'] ?? null,
            'shipment_status' => 'historical_evidence',
            'carrier' => null,
            'service' => null,
            'carrier_selection_source' => null,
            'shipped_at' => null,
            'leg_index' => (int) ($spec['leg_index'] ?? 0),
            'leg_count' => (int) ($spec['leg_count'] ?? 0),
        ];

        $review = function (string $reason) use ($sourceRow, $attemptNumber, $evidence, &$manifest): array {
            return [
                'rescue_order_projection_row_id' => (int) $sourceRow->id,
                'historical_evidence_id' => (int) ($evidence?->id ?? 0),
                'order_id' => (int) $sourceRow->order_id,
                'source_row_number' => (int) $sourceRow->source_row_number,
                'attempt_number' => $attemptNumber,
                'decision' => 'review',
                'reason' => $reason,
                'manifest' => $manifest,
            ];
        };
        $expectedLegCount = count($sourceRow->projection_manifest['evidence'] ?? []);
        if ($sourceRow->decision !== 'applied' || $sourceRow->order_id === null || $sourceRow->order === null || $evidence === null) {
            return $review('source_evidence_invalid');
        }
        if ((int) $evidence->batch_id !== (int) $orderBatch->historical_import_batch_id
            || (string) $evidence->source_checksum !== (string) $orderBatch->source_checksum
            || (int) $evidence->source_row_number !== (int) $sourceRow->source_row_number
            || data_get($snapshot, 'rescue.version') !== self::RESCUE_VERSION
            || (int) ($spec['historical_evidence_id'] ?? 0) !== (int) $evidence->id
            || (string) ($spec['evidence_hash'] ?? '') !== (string) $evidence->evidence_hash
            || trim((string) ($spec['tracking_number'] ?? '')) !== $tracking
            || (int) ($spec['leg_index'] ?? 0) !== $attemptNumber
            || (int) ($spec['leg_count'] ?? 0) !== $expectedLegCount
            || $attemptNumber < 1
            || $attemptNumber > $expectedLegCount) {
            return $review('source_evidence_invalid');
        }
        $expectedOrderNo = trim((string) ($sourceRow->projection_manifest['source_order_no'] ?? ''));
        $expectedErpOrderId = trim((string) ($sourceRow->projection_manifest['erp_order_id'] ?? ''));
        $expectedConnectionKey = trim((string) ($sourceRow->projection_manifest['connection_key'] ?? ''));
        if ($expectedOrderNo === ''
            || $expectedErpOrderId === ''
            || $expectedConnectionKey === ''
            || trim((string) $sourceRow->order->external_order_number) !== $expectedOrderNo
            || trim((string) $sourceRow->order->erp_order_id) !== $expectedErpOrderId) {
            return $review('source_order_identity_drift');
        }
        if (! OrderExternalReference::query()
            ->where('order_id', $sourceRow->order_id)
            ->where('provider', self::PROVIDER)
            ->where('connection_key', $expectedConnectionKey)
            ->where('reference', $expectedOrderNo)
            ->exists()) {
            return $review('historical_external_reference_missing');
        }
        if ($trackingKey === '') {
            return $review('tracking_missing');
        }
        if (($trackingCounts[$trackingKey] ?? 0) !== 1) {
            return $review('tracking_cross_source_conflict');
        }
        if ($warehouseCode !== null && $warehouse === null) {
            return $review('warehouse_master_missing');
        }
        if ($attemptTracking->has($trackingKey)) {
            return $review('tracking_conflict_shipment_attempt');
        }
        if ($shipmentTracking->has($trackingKey)) {
            return $review('tracking_conflict_shipment');
        }
        if ($attemptNumbers->has($sourceRow->order_id.':'.$attemptNumber)) {
            return $review('attempt_number_conflict');
        }
        if ($activeShipmentOrders->has((int) $sourceRow->order_id)) {
            return $review('active_shipment');
        }

        return [
            'rescue_order_projection_row_id' => (int) $sourceRow->id,
            'historical_evidence_id' => (int) $evidence->id,
            'order_id' => (int) $sourceRow->order_id,
            'source_row_number' => (int) $sourceRow->source_row_number,
            'attempt_number' => $attemptNumber,
            'decision' => 'ready',
            'reason' => 'deterministic_rescue_historical_attempt',
            'manifest' => $manifest,
        ];
    }

    private function trackingKey(string $tracking): string
    {
        return mb_strtoupper(trim($tracking));
    }

    private function manifestsEqual(array $left, array $right): bool
    {
        return $this->canonicalize($left) === $this->canonicalize($right);
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    private function authorize(Authenticatable $actor): void
    {
        if (! $actor->can('logistics.operate')) {
            abort(403);
        }
    }
}
