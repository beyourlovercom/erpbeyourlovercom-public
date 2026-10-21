<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Infrastructure\Models\BackupRun;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueOrderProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueShipmentAttemptProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalShipmentAttemptProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use Illuminate\Support\Collection;

final class HistoricalLogisticsControlCenterService
{
    private const PROVIDER = 'historical_logistics';

    public function __construct(private readonly HistoricalRescueShipmentAttemptProjectionService $attempts) {}

    public function view(
        HistoricalImportBatch $batch,
        int $attemptPage = 1,
        ?string $attemptSearch = null,
        string $attemptDecision = 'all',
        string $attemptWarehouse = 'all',
    ): array {
        $materialization = HistoricalOrderMaterializationBatch::query()
            ->where('historical_import_batch_id', $batch->id)->latest('id')->first();
        $originalAttemptBatch = $materialization
            ? HistoricalShipmentAttemptProjectionBatch::query()->where('materialization_batch_id', $materialization->id)->latest('id')->first()
            : null;
        $rescueOrderBatch = HistoricalRescueOrderProjectionBatch::query()
            ->where('historical_import_batch_id', $batch->id)->latest('id')->first();
        $rescueAttemptBatch = $rescueOrderBatch
            ? HistoricalRescueShipmentAttemptProjectionBatch::query()->where('rescue_order_projection_batch_id', $rescueOrderBatch->id)->latest('id')->first()
            : null;

        $freshPlan = null;
        if ($rescueOrderBatch?->status === 'applied' && $rescueAttemptBatch?->status !== 'applied') {
            $freshPlan = $this->attempts->inspect($rescueOrderBatch);
        }

        $r5Rows = $rescueAttemptBatch?->rows()->with('order')->get() ?? collect();
        $attemptRows = $this->attemptRows($r5Rows, $attemptSearch, $attemptDecision, $attemptWarehouse, $attemptPage);
        $reviewRows = $rescueOrderBatch
            ? $rescueOrderBatch->rows()->where('decision', 'review')->get()->map(fn ($row): array => [
                'id' => $row->id,
                'source_row_number' => (int) $row->source_row_number,
                'source_order_no' => $row->source_order_no,
                'reason' => $row->decision_reason,
                'tracking_leg_count' => (int) ($row->projection_manifest['tracking_leg_count'] ?? 0),
            ])->values()->all()
            : [];

        $candidateRows = collect($freshPlan['rows'] ?? []);
        $storedRows = $r5Rows;
        $warehouseRows = $rescueAttemptBatch ? $storedRows : $candidateRows;
        $warehouseSummary = $this->warehouseSummary($warehouseRows, $rescueAttemptBatch !== null);
        $candidateTotal = (int) ($freshPlan ? count($freshPlan['rows']) : ($rescueAttemptBatch?->total_rows ?? 0));
        $candidateReady = (int) ($freshPlan['ready_count'] ?? ($rescueAttemptBatch?->ready_count ?? 0));
        $candidateReview = (int) ($freshPlan['review_count'] ?? ($rescueAttemptBatch?->review_count ?? 0));
        $candidateSha = $freshPlan['ready_manifest_sha256'] ?? $rescueAttemptBatch?->ready_manifest_sha256;
        $shaMatches = $rescueAttemptBatch?->status === 'preview'
            ? hash_equals((string) $rescueAttemptBatch->ready_manifest_sha256, (string) ($freshPlan['ready_manifest_sha256'] ?? ''))
            : true;

        $latestBackup = BackupRun::query()
            ->with('artifacts')
            ->where('backup_type', BackupType::Database->value)
            ->whereIn('state', [BackupState::Ready->value, BackupState::Verified->value])
            ->latest('id')->first();
        $backupArtifact = $latestBackup?->artifacts->sortByDesc('id')->first();

        $rescueLegCandidates = $rescueOrderBatch
            ? $rescueOrderBatch->rows()->where('decision', 'applied')->get()
                ->sum(fn ($row): int => count($row->projection_manifest['evidence'] ?? []))
            : 0;
        $historicalOrderIds = OrderExternalReference::query()->where('provider', self::PROVIDER)->select('order_id')->distinct();
        $historicalAttempts = ShipmentAttempt::query()->whereIn('order_id', $historicalOrderIds)->count();
        $activeHistoricalShipments = Shipment::query()
            ->whereIn('order_id', OrderExternalReference::query()->where('provider', self::PROVIDER)->select('order_id')->distinct())
            ->whereNotIn('shipment_status', ['cancelled', 'completed'])->count();

        return [
            'summary' => [
                'source_rows' => (int) $batch->total_rows,
                'evidences' => HistoricalEvidence::query()->where('batch_id', $batch->id)->count(),
                'historical_orders' => OrderExternalReference::query()->where('provider', self::PROVIDER)->distinct()->count('order_id'),
                'original_attempts' => $originalAttemptBatch?->rows()->whereNotNull('shipment_attempt_id')->count() ?? 0,
                'rescue_orders' => $rescueOrderBatch?->rows()->whereNotNull('order_id')->count() ?? 0,
                'rescue_tracking_legs' => $rescueLegCandidates,
                'permanent_review' => count($reviewRows),
                'historical_attempts_total' => $historicalAttempts,
                'active_shipment_contamination' => $activeHistoricalShipments,
            ],
            'r5' => [
                'batch_id' => $rescueAttemptBatch?->id,
                'status' => $rescueAttemptBatch?->status ?? 'not_previewed',
                'total' => $candidateTotal,
                'ready' => $candidateReady,
                'review' => $candidateReview,
                'applied' => (int) ($rescueAttemptBatch?->applied_count ?? 0),
                'manifest_sha256' => $candidateSha,
                'stored_manifest_sha256' => $rescueAttemptBatch?->ready_manifest_sha256,
                'fresh_sha_matches' => $shaMatches,
                'global_apply_gate' => (bool) config('logistics.historical_rescue_shipment_attempt_apply_enabled', false),
                'can_apply' => $rescueAttemptBatch?->status === 'preview' && $candidateReady > 0 && $candidateReview === 0 && $shaMatches,
                'confirmation' => $rescueAttemptBatch?->status === 'preview' ? 'APPLY '.$candidateReady : null,
                'warehouse' => $warehouseSummary,
                'unique_tracking' => $this->uniqueTrackingCount($warehouseRows, $rescueAttemptBatch !== null),
                'review_reasons' => $this->reviewReasons($freshPlan['rows'] ?? [], $rescueAttemptBatch?->status === 'applied' ? [] : null),
            ],
            'attempts' => $attemptRows,
            'permanent_review_rows' => $reviewRows,
            'latest_backup' => $latestBackup ? [
                'id' => $latestBackup->id,
                'uuid' => $latestBackup->run_uuid,
                'state' => $latestBackup->state->value,
                'created_at' => $latestBackup->created_at?->toIso8601String(),
                'checksum' => $backupArtifact?->checksum,
                'size' => $backupArtifact?->size,
            ] : null,
        ];
    }

    private function attemptRows(Collection $rows, ?string $search, string $decision, string $warehouse, int $page): array
    {
        $needle = mb_strtolower(trim((string) $search));
        $mapped = $rows->map(function ($row): array {
            $manifest = $row->projection_manifest ?? [];
            return [
                'id' => $row->id,
                'order_id' => (int) $row->order_id,
                'order_url' => '/kol/orders/'.$row->order_id,
                'erp_order_id' => $row->order?->erp_order_id,
                'source_order_no' => $manifest['source_order_no'] ?? $row->order?->external_order_number,
                'source_row_number' => (int) $row->source_row_number,
                'attempt_number' => (int) $row->attempt_number,
                'tracking_number' => $manifest['tracking_number'] ?? null,
                'warehouse_code' => $manifest['warehouse_code'] ?? null,
                'warehouse_raw' => $manifest['raw_warehouse'] ?? null,
                'warehouse_resolution' => $manifest['warehouse_resolution'] ?? 'unresolved',
                'raw_agent' => $manifest['raw_agent'] ?? null,
                'decision' => $row->decision,
                'reason' => $row->decision_reason,
            ];
        });
        if ($needle !== '') {
            $mapped = $mapped->filter(fn (array $row): bool => collect([
                $row['erp_order_id'], $row['source_order_no'], $row['tracking_number'], $row['warehouse_raw'], $row['warehouse_code'],
            ])->filter()->contains(fn ($value): bool => str_contains(mb_strtolower((string) $value), $needle)));
        }
        if ($decision !== 'all') $mapped = $mapped->where('decision', $decision);
        if ($warehouse === 'exact') $mapped = $mapped->where('warehouse_resolution', 'exact');
        if ($warehouse === 'unresolved') $mapped = $mapped->where('warehouse_resolution', 'unresolved');
        $mapped = $mapped->values();
        $perPage = 50;
        $total = $mapped->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        return [
            'data' => $mapped->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'filters' => ['q' => (string) $search, 'decision' => $decision, 'warehouse' => $warehouse],
        ];
    }

    private function warehouseSummary(Collection $rows, bool $stored): array
    {
        $summary = ['exact' => 0, 'unresolved' => 0, 'master_missing' => 0];
        foreach ($rows as $row) {
            $manifest = $stored ? ($row->projection_manifest ?? []) : ($row['manifest'] ?? []);
            $key = (string) ($manifest['warehouse_resolution'] ?? 'unresolved');
            $summary[$key] = ($summary[$key] ?? 0) + 1;
        }
        return $summary;
    }

    private function uniqueTrackingCount(Collection $rows, bool $stored): int
    {
        return $rows->map(function ($row) use ($stored): string {
            $manifest = $stored ? ($row->projection_manifest ?? []) : ($row['manifest'] ?? []);
            return trim((string) ($manifest['tracking_number'] ?? ''));
        })->filter()->unique()->count();
    }

    private function reviewReasons(array $rows, ?array $fallback = null): array
    {
        if ($fallback !== null) return $fallback;
        return collect($rows)->where('decision', 'review')->groupBy('reason')
            ->map(fn (Collection $group, string $reason): array => ['reason' => $reason, 'count' => $group->count()])
            ->values()->all();
    }
}
