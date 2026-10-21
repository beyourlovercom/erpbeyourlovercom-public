<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationRow;
use App\Modules\Logistics\Infrastructure\Models\HistoricalShipmentAttemptProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalShipmentAttemptProjectionRow;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HistoricalShipmentAttemptProjectionService
{
    private const VERSION = 'historical-shipment-attempt-projection-v1';

    private const WAREHOUSES = [
        'USA 美西仓' => 'US-LA',
        '美东仓' => 'US-NY',
        'Factory 国内仓' => 'FACTORY',
        'Canada 加拿大仓' => 'CA',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function preview(HistoricalOrderMaterializationBatch $materializationBatch, Authenticatable $actor): HistoricalShipmentAttemptProjectionBatch
    {
        $this->authorize($actor);
        if ($materializationBatch->status !== 'applied') {
            throw ValidationException::withMessages(['batch' => 'Historical shipment attempt projection requires an applied materialization batch.']);
        }

        return DB::transaction(function () use ($materializationBatch, $actor): HistoricalShipmentAttemptProjectionBatch {
            $batch = HistoricalShipmentAttemptProjectionBatch::firstOrCreate(
                ['materialization_batch_id' => $materializationBatch->id, 'source_checksum' => $materializationBatch->source_checksum],
                ['status' => 'preview', 'created_by' => $actor->getAuthIdentifier()],
            );
            if (! $batch->wasRecentlyCreated) return $batch->fresh('rows');

            $counts = ['ready' => 0, 'review' => 0];
            $rows = HistoricalOrderMaterializationRow::query()
                ->where('materialization_batch_id', $materializationBatch->id)
                ->where('decision', 'applied')
                ->with(['evidence', 'order.shipment'])
                ->orderBy('source_row_number')->get();
            foreach ($rows as $row) {
                $decision = $this->decide($row, $materializationBatch->source_checksum, (int) $materializationBatch->historical_import_batch_id);
                $counts[$decision['decision']]++;
                $batch->rows()->create([
                    'materialization_row_id' => $row->id,
                    'historical_evidence_id' => $row->historical_evidence_id,
                    'source_row_number' => $row->source_row_number,
                    'decision' => $decision['decision'],
                    'decision_reason' => $decision['reason'],
                    'projection_manifest' => $decision['manifest'],
                ]);
            }
            $batch->update(['total_rows' => $counts['ready'] + $counts['review'], 'ready_count' => $counts['ready'], 'review_count' => $counts['review']]);
            $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'logistics.historical_shipment_attempt_projection.previewed', 'logistics_hist_attempt_projection_batch', $batch->id, null, ['source_checksum' => $batch->source_checksum, ...$counts], 'historical_shipment_attempt_projection_preview');
            return $batch->fresh('rows');
        });
    }

    public function apply(HistoricalShipmentAttemptProjectionBatch $batch, Authenticatable $actor): HistoricalShipmentAttemptProjectionBatch
    {
        $this->authorize($actor);
        if (! config('logistics.historical_shipment_attempt_apply_enabled', false)) abort(403, 'Historical shipment attempt projection is disabled in this environment.');

        return DB::transaction(function () use ($batch, $actor): HistoricalShipmentAttemptProjectionBatch {
            $locked = HistoricalShipmentAttemptProjectionBatch::query()->whereKey($batch->id)->lockForUpdate()->with(['materializationBatch', 'rows.materializationRow.evidence', 'rows.materializationRow.order.shipment'])->firstOrFail();
            if ($locked->status === 'applied') return $locked;
            if ($locked->status !== 'preview' || $locked->materializationBatch->status !== 'applied') throw ValidationException::withMessages(['batch' => 'Historical shipment attempt projection batch is not applyable.']);

            foreach ($locked->rows->where('decision', 'ready') as $row) {
                $source = $row->materializationRow;
                $current = $this->decide($source, $locked->source_checksum, (int) $locked->materializationBatch->historical_import_batch_id);
                if ($current['decision'] !== 'ready' || ! $this->manifestsEqual($current['manifest'], $row->projection_manifest)) {
                    throw ValidationException::withMessages(['batch' => 'Frozen historical shipment attempt evidence drifted; re-preview is required.']);
                }
                if (ShipmentAttempt::query()->where('order_id', $source->order_id)->exists()) throw ValidationException::withMessages(['batch' => 'A shipment attempt now exists for a frozen historical order.']);
            }

            $applied = 0;
            foreach ($locked->rows->where('decision', 'ready') as $row) {
                $source = $row->materializationRow;
                $manifest = $row->projection_manifest;
                $attempt = ShipmentAttempt::query()->create([
                    'order_id' => $manifest['order_id'], 'shipment_id' => null, 'attempt_number' => 1,
                    'shipment_status' => 'historical_evidence', 'warehouse_id' => $manifest['warehouse_id'],
                    'warehouse_selection_source' => $manifest['warehouse_selection_source'], 'carrier' => null,
                    'service' => null, 'carrier_selection_source' => null, 'tracking_number' => $manifest['tracking_number'],
                    'shipped_at' => null, 'logistics_note' => 'Historical logistics evidence #'.$source->historical_evidence_id,
                    'handled_by' => null, 'snapshotted_by' => $actor->getAuthIdentifier(), 'snapshotted_at' => now(),
                    'evidence_snapshot' => ['projection_manifest' => $manifest, 'provenance' => ['materialization_batch_id' => $locked->materialization_batch_id, 'materialization_row_id' => $source->id, 'historical_evidence_id' => $source->historical_evidence_id]],
                ]);
                $row->update(['shipment_attempt_id' => $attempt->id]);
                $applied++;
            }
            $locked->update(['status' => 'applied', 'applied_count' => $applied]);
            $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'logistics.historical_shipment_attempt_projection.applied', 'logistics_hist_attempt_projection_batch', $locked->id, null, ['source_checksum' => $locked->source_checksum, 'applied_count' => $applied], 'historical_shipment_attempt_projection_apply');
            return $locked->fresh('rows');
        });
    }

    private function decide(HistoricalOrderMaterializationRow $row, string $checksum, int $importBatchId): array
    {
        $evidence = $row->evidence;
        $snapshot = $evidence?->evidence_snapshot ?? [];
        $tracking = trim((string) ($evidence?->normalized_tracking_candidate ?? ''));
        $rawWarehouse = $snapshot['source_warehouse_raw'] ?? null;
        $warehouseCode = self::WAREHOUSES[(string) $rawWarehouse] ?? null;
        $warehouse = $warehouseCode ? Warehouse::query()->where('code', $warehouseCode)->first() : null;
        $warehouseResolution = $warehouseCode === null ? 'unresolved' : ($warehouse === null ? 'master_missing' : 'exact');
        $manifest = [
            'order_id' => (int) $row->order_id, 'historical_evidence_id' => (int) $row->historical_evidence_id,
            'historical_evidence_hash' => (string) ($evidence?->evidence_hash ?? ''), 'source_checksum' => $checksum,
            'source_row_number' => (int) $row->source_row_number, 'source_order_no' => $row->source_order_no,
            'evidence_id' => (int) $row->historical_evidence_id, 'evidence_hash' => (string) ($evidence?->evidence_hash ?? ''),
            'tracking_number' => $tracking, 'warehouse_resolution' => $warehouseResolution,
            'warehouse_code' => $warehouseCode, 'warehouse_id' => $warehouse?->id, 'raw_warehouse' => $rawWarehouse, 'warehouse_raw' => $rawWarehouse,
            'raw_agent' => $snapshot['source_carrier_raw'] ?? null, 'raw_carrier' => $snapshot['source_carrier_raw'] ?? null,
            'shipment_status' => 'historical_evidence', 'status' => 'historical_evidence', 'shipped_at' => null, 'carrier' => null, 'service' => null,
            'carrier_selection_source' => null, 'warehouse_selection_source' => $warehouse !== null ? 'historical_exact' : null,
            'attempt_number' => 1, 'version' => self::VERSION,
        ];
        if ($row->decision !== 'applied' || $evidence === null || $row->order_id === null || $row->order === null || $evidence->source_checksum !== $checksum || (int) $evidence->batch_id !== $importBatchId) return ['decision' => 'review', 'reason' => 'source_evidence_invalid', 'manifest' => $manifest];
        if ($warehouseCode !== null && $warehouse === null) return ['decision' => 'review', 'reason' => 'warehouse_master_missing', 'manifest' => $manifest];
        if ($tracking === '') return ['decision' => 'review', 'reason' => 'tracking_missing', 'manifest' => $manifest];
        if (Shipment::query()->where('tracking_number', $tracking)->whereNotIn('shipment_status', ['cancelled', 'completed'])->exists()) return ['decision' => 'review', 'reason' => 'tracking_conflict_active_shipment', 'manifest' => $manifest];
        if (ShipmentAttempt::query()->where('tracking_number', $tracking)->exists()) return ['decision' => 'review', 'reason' => 'tracking_conflict_shipment_attempt', 'manifest' => $manifest];
        if (ShipmentAttempt::query()->where('order_id', $row->order_id)->exists()) return ['decision' => 'review', 'reason' => 'preexisting_shipment_attempt', 'manifest' => $manifest];
        if (Shipment::query()->where('order_id', $row->order_id)->whereNotIn('shipment_status', ['cancelled', 'completed'])->exists()) return ['decision' => 'review', 'reason' => 'active_shipment', 'manifest' => $manifest];
        return ['decision' => 'ready', 'reason' => 'deterministic_historical_attempt', 'manifest' => $manifest];
    }

    private function manifestsEqual(array $left, array $right): bool
    {
        return $this->canonicalize($left) === $this->canonicalize($right);
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) ksort($value);
        return $value;
    }

    private function authorize(Authenticatable $actor): void { if (! $actor->can('logistics.operate')) abort(403); }
}
