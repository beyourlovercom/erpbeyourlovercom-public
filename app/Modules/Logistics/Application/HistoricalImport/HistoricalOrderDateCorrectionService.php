<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderDateCorrectionBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderDateCorrectionRow;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationRow;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HistoricalOrderDateCorrectionService
{
    private const VERSION = 'historical-order-date-correction-v1';
    private const AS_OF = '2026-09-06';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly OrderIdentityService $orderIdentities,
    ) {}

    public function preview(HistoricalOrderMaterializationBatch $materializationBatch, Authenticatable $actor): HistoricalOrderDateCorrectionBatch
    {
        $this->authorize($actor);
        if ($materializationBatch->status !== 'applied') {
            throw ValidationException::withMessages(['batch' => 'Historical order date correction requires an applied materialization batch.']);
        }

        return DB::transaction(function () use ($materializationBatch, $actor): HistoricalOrderDateCorrectionBatch {
            $batch = HistoricalOrderDateCorrectionBatch::firstOrCreate(
                [
                    'materialization_batch_id' => $materializationBatch->id,
                    'source_checksum' => $materializationBatch->source_checksum,
                    'correction_version' => self::VERSION,
                    'as_of_date' => self::AS_OF,
                ],
                ['status' => 'preview', 'created_by' => $actor->getAuthIdentifier()],
            );
            if (! $batch->wasRecentlyCreated) return $batch->fresh('rows');

            $anchor = $this->rolloverAnchor($materializationBatch);
            $counts = ['ready' => 0, 'review' => 0];
            $rows = HistoricalOrderMaterializationRow::query()
                ->where('materialization_batch_id', $materializationBatch->id)
                ->where('decision', 'applied')
                ->whereNotNull('order_id')
                ->with(['evidence.row', 'order'])
                ->orderBy('source_row_number')
                ->get()
                ->filter(fn (HistoricalOrderMaterializationRow $row): bool => $row->order !== null && $row->order->order_date?->toDateString() > self::AS_OF);

            foreach ($rows as $row) {
                $decision = $this->decide($row, $materializationBatch->source_checksum, (int) $materializationBatch->historical_import_batch_id, $anchor);
                $counts[$decision['decision']]++;
                $batch->rows()->create([
                    'materialization_row_id' => $row->id,
                    'order_id' => $row->order_id,
                    'historical_evidence_id' => $row->historical_evidence_id,
                    'source_row_number' => $row->source_row_number,
                    'decision' => $decision['decision'],
                    'decision_reason' => $decision['reason'],
                    'current_order_date' => $row->order->order_date->toDateString(),
                    'proposed_order_date' => $decision['proposed_order_date'],
                    'correction_manifest' => $decision['manifest'],
                ]);
            }

            $batch->update(['total_rows' => array_sum($counts), 'ready_count' => $counts['ready'], 'review_count' => $counts['review']]);
            $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'logistics.historical_order_date_correction.previewed', 'logistics_hist_order_date_correction_batch', $batch->id, null, ['source_checksum' => $batch->source_checksum, ...$counts], 'historical_order_date_correction_preview');

            return $batch->fresh('rows');
        });
    }

    public function apply(HistoricalOrderDateCorrectionBatch $batch, Authenticatable $actor): HistoricalOrderDateCorrectionBatch
    {
        $this->authorize($actor);
        if (! config('logistics.historical_order_date_correction_apply_enabled', false)) abort(403, 'Historical order date correction is disabled in this environment.');

        return DB::transaction(function () use ($batch, $actor): HistoricalOrderDateCorrectionBatch {
            $locked = HistoricalOrderDateCorrectionBatch::query()->whereKey($batch->id)->lockForUpdate()->with('materializationBatch')->firstOrFail();
            if ($locked->status === 'applied') return $locked->fresh('rows');
            if ($locked->status !== 'preview' || $locked->materializationBatch->status !== 'applied') {
                throw ValidationException::withMessages(['batch' => 'Historical order date correction batch is not applyable.']);
            }

            $anchor = $this->rolloverAnchor($locked->materializationBatch);
            $rows = HistoricalOrderDateCorrectionRow::query()->where('correction_batch_id', $locked->id)->lockForUpdate()->get();
            foreach ($rows->where('decision', 'ready') as $correction) {
                $source = HistoricalOrderMaterializationRow::query()->whereKey($correction->materialization_row_id)->lockForUpdate()->with(['evidence.row'])->firstOrFail();
                $order = Order::query()->whereKey($correction->order_id)->lockForUpdate()->firstOrFail();
                if (! $this->stillMatches($correction, $source, $order, $locked->source_checksum, (int) $locked->materializationBatch->historical_import_batch_id, $anchor)) {
                    throw ValidationException::withMessages(['batch' => 'Frozen historical order date evidence drifted; re-preview is required.']);
                }
            }

            $applied = 0;
            foreach ($rows->where('decision', 'ready') as $correction) {
                $source = HistoricalOrderMaterializationRow::query()->whereKey($correction->materialization_row_id)->lockForUpdate()->firstOrFail();
                $order = Order::query()->whereKey($correction->order_id)->lockForUpdate()->firstOrFail();
                $proposedDate = $correction->proposed_order_date->toDateString();
                $order->forceFill(['order_date' => $proposedDate])->saveQuietly();
                $manifest = $source->materialization_manifest ?? [];
                $manifest['order_date'] = $proposedDate;
                $source->forceFill(['materialization_manifest' => $manifest])->saveQuietly();
                $applied++;
            }

            $locked->update(['status' => 'applied', 'applied_count' => $applied]);
            $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'logistics.historical_order_date_correction.applied', 'logistics_hist_order_date_correction_batch', $locked->id, null, ['source_checksum' => $locked->source_checksum, 'applied_count' => $applied], 'historical_order_date_correction_apply');
            return $locked->fresh('rows');
        });
    }

    private function decide(HistoricalOrderMaterializationRow $row, string $checksum, int $importBatchId, ?array $anchor): array
    {
        $evidence = $row->evidence;
        $source = $evidence?->row;
        $snapshot = $evidence?->evidence_snapshot ?? [];
        $current = $row->order->order_date->toDateString();
        $rawOrderNo = $source?->source_order_no ?? ($snapshot['source_order_no'] ?? $row->source_order_no);
        $rawInput = $source?->source_input_time_raw ?? ($snapshot['source_input_time_raw'] ?? null);
        $parsed = $this->parseNumericDate($rawInput);
        $currentErpOrderId = trim((string) $row->order->erp_order_id);
        $manifest = [
            'materialization_row_id' => (int) $row->id, 'order_id' => (int) $row->order_id,
            'historical_evidence_id' => (int) $row->historical_evidence_id, 'historical_evidence_hash' => (string) ($evidence?->evidence_hash ?? ''),
            'evidence_id' => (int) $row->historical_evidence_id, 'evidence_hash' => (string) ($evidence?->evidence_hash ?? ''),
            'source_row_number' => (int) $row->source_row_number, 'source_order_no' => $rawOrderNo, 'source_input_time_raw' => $rawInput,
            'source_checksum' => $checksum, 'current_order_date' => $current, 'proposed_order_date' => null,
            'current_erp_order_id' => $currentErpOrderId, 'proposed_erp_order_id' => null,
            'reason' => null, 'version' => self::VERSION, 'as_of_date' => self::AS_OF,
            'rollover_anchor' => $anchor,
        ];
        $reason = null;
        if ($evidence === null || $source === null || $evidence->source_checksum !== $checksum || (int) $evidence->batch_id !== $importBatchId || (int) $source->source_row_number !== (int) $row->source_row_number) $reason = 'source_evidence_invalid';
        elseif ($anchor === null) $reason = 'rollover_anchor_required';
        elseif ($row->source_row_number < 3 || $row->source_row_number > 77) $reason = 'future_date_manual_review';
        elseif (preg_match('/^2026-12-(0[1-9]|[12][0-9]|3[01])$/', $current) !== 1) $reason = 'future_date_manual_review';
        elseif (! $parsed || $parsed !== $current) $reason = 'future_date_manual_review';
        elseif (! preg_match('/^KOL(?<month>0[1-9]|1[0-2])(?<day>0[1-9]|[12][0-9]|3[01])[0-9]{2}$/', strtoupper(trim((string) $rawOrderNo)), $match) || sprintf('%02d-%02d', (int) $match['month'], (int) $match['day']) !== substr($current, 5)) $reason = 'future_date_manual_review';
        elseif ($currentErpOrderId === '') $reason = 'erp_identity_change_requires_review';

        $proposed = $reason === null ? CarbonImmutable::parse($current)->subYear()->toDateString() : null;
        $proposedErpOrderId = $proposed !== null ? $this->proposedErpOrderId($row, $current, $proposed, $currentErpOrderId) : null;
        if ($reason === null && $proposedErpOrderId !== $currentErpOrderId) $reason = 'erp_identity_change_requires_review';
        if ($reason !== null) $proposed = null;

        $manifest['proposed_order_date'] = $proposed;
        $manifest['proposed_erp_order_id'] = $proposedErpOrderId;
        $manifest['reason'] = $reason ?? 'exact_rollover_date_correction';
        if ($reason !== null) return ['decision' => 'review', 'reason' => $reason, 'proposed_order_date' => null, 'manifest' => $manifest];
        return ['decision' => 'ready', 'reason' => 'exact_rollover_date_correction', 'proposed_order_date' => $proposed, 'manifest' => $manifest];
    }

    private function stillMatches(HistoricalOrderDateCorrectionRow $correction, HistoricalOrderMaterializationRow $source, Order $order, string $checksum, int $importBatchId, ?array $anchor): bool
    {
        if ($source->decision !== 'applied' || (int) $source->order_id !== (int) $correction->order_id || $source->historical_evidence_id !== $correction->historical_evidence_id) return false;
        if ($order->order_date?->toDateString() !== $correction->current_order_date->toDateString()) return false;
        if ((string) data_get($source->materialization_manifest, 'order_date') !== $correction->current_order_date->toDateString()) return false;
        $decision = $this->decide($source->load(['evidence.row', 'order']), $checksum, $importBatchId, $anchor);
        return $decision['decision'] === 'ready' && $this->canonical($decision['manifest']) === $this->canonical($correction->correction_manifest)
            && (string) $order->erp_order_id === (string) data_get($correction->correction_manifest, 'proposed_erp_order_id');
    }


    private function proposedErpOrderId(HistoricalOrderMaterializationRow $row, string $currentDate, string $proposedDate, string $currentErpOrderId): ?string
    {
        $kolId = (int) data_get($row->materialization_manifest, 'kol_id', 0);
        if ($kolId <= 0 || $currentErpOrderId === '') return null;

        try {
            $baseCurrent = $this->orderIdentities->previewCandidate('k', $kolId, $currentDate);
            $suffix = null;
            if ($currentErpOrderId !== $baseCurrent) {
                if (! str_starts_with($currentErpOrderId, $baseCurrent) || strlen($currentErpOrderId) !== strlen($baseCurrent) + 1) return null;
                $suffix = substr($currentErpOrderId, -1);
                if (! preg_match('/^[A-Z]$/', $suffix)) return null;
                if ($this->orderIdentities->previewCandidate('k', $kolId, $currentDate, $suffix) !== $currentErpOrderId) return null;
            }

            return $this->orderIdentities->previewCandidate('k', $kolId, $proposedDate, $suffix);
        } catch (\Throwable) {
            return null;
        }
    }

    private function rolloverAnchor(HistoricalOrderMaterializationBatch $batch): ?array
    {
        $row = HistoricalImportRow::query()->where('batch_id', $batch->historical_import_batch_id)->where('source_row_number', 78)->first();
        if ($row === null || strtoupper(trim((string) $row->source_order_no)) !== 'KOL010101' || $this->parseNumericDate($row->source_input_time_raw) !== '2026-01-01') return null;
        return ['source_row_number' => 78, 'source_order_no' => $row->source_order_no, 'source_input_time_raw' => $row->source_input_time_raw, 'parsed_date' => '2026-01-01'];
    }

    private function parseNumericDate(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^[0-9]+$/', $raw) !== 1) return null;
        return CarbonImmutable::create(1899, 12, 30)->addDays((int) $raw)->toDateString();
    }

    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonical($item);
        if (! array_is_list($value)) ksort($value);
        return $value;
    }

    private function authorize(Authenticatable $actor): void { if (! $actor->can('logistics.operate')) abort(403); }
}
