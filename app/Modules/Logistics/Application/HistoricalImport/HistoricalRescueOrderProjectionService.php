<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueOrderProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HistoricalRescueOrderProjectionService
{
    private const VERSION = 'historical-rescue-order-projection-v1';
    private const RESCUE_VERSION = 'historical-review-rescue-v1';
    private const PROVIDER = 'historical_logistics';

    public function __construct(
        private readonly HistoricalOrderMaterializationService $materialization,
        private readonly OrderIdentityService $orderIdentities,
        private readonly OrderService $orders,
        private readonly AuditRecorder $audit,
    ) {}

    public function preview(HistoricalImportBatch $importBatch, Authenticatable $actor): HistoricalRescueOrderProjectionBatch
    {
        $this->authorize($actor);
        if ($importBatch->status !== 'committed') {
            throw ValidationException::withMessages(['batch' => 'Historical rescue order projection requires a committed historical import batch.']);
        }

        return DB::transaction(function () use ($importBatch, $actor): HistoricalRescueOrderProjectionBatch {
            $batch = HistoricalRescueOrderProjectionBatch::firstOrCreate(
                [
                    'historical_import_batch_id' => $importBatch->id,
                    'source_checksum' => $importBatch->source_checksum,
                    'projection_version' => self::VERSION,
                ],
                ['status' => 'preview', 'created_by' => $actor->getAuthIdentifier()],
            );
            if (! $batch->wasRecentlyCreated) {
                return $batch->fresh('rows');
            }

            $plan = $this->buildPlan($importBatch);
            foreach ($plan['rows'] as $row) {
                $batch->rows()->create([
                    'source_row_number' => $row['source_row_number'],
                    'source_order_no' => $row['source_order_no'],
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
                'logistics.historical_rescue_order_projection.previewed',
                'logistics_hist_rescue_order_projection_batch',
                $batch->id,
                null,
                [
                    'source_checksum' => $batch->source_checksum,
                    'ready_count' => $plan['ready_count'],
                    'review_count' => $plan['review_count'],
                    'ready_manifest_sha256' => $plan['ready_manifest_sha256'],
                ],
                'historical_rescue_order_projection_preview',
            );
            return $batch->fresh('rows');
        });
    }

    public function apply(HistoricalRescueOrderProjectionBatch $batch, Authenticatable $actor): HistoricalRescueOrderProjectionBatch
    {
        $this->authorize($actor);
        if (! config('logistics.historical_rescue_order_apply_enabled', false)) {
            abort(403, 'Historical rescue order projection is disabled in this environment.');
        }

        return DB::transaction(function () use ($batch, $actor): HistoricalRescueOrderProjectionBatch {
            $locked = HistoricalRescueOrderProjectionBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->with(['importBatch', 'rows'])
                ->firstOrFail();
            if ($locked->status === 'applied') {
                return $locked->fresh('rows');
            }
            if ($locked->status !== 'preview' || $locked->importBatch->status !== 'committed') {
                throw ValidationException::withMessages(['batch' => 'Historical rescue order projection batch is not applyable.']);
            }

            HistoricalImportRow::query()
                ->where('batch_id', $locked->historical_import_batch_id)
                ->lockForUpdate()
                ->get(['id']);
            HistoricalEvidence::query()
                ->where('batch_id', $locked->historical_import_batch_id)
                ->lockForUpdate()
                ->get(['id']);

            $current = $this->buildPlan($locked->importBatch);
            $storedRows = $locked->rows->keyBy('source_row_number');
            if (count($current['rows']) !== $storedRows->count() || $current['ready_manifest_sha256'] !== $locked->ready_manifest_sha256) {
                throw ValidationException::withMessages(['batch' => 'Frozen historical rescue order evidence drifted; re-preview is required.']);
            }
            foreach ($current['rows'] as $row) {
                $stored = $storedRows->get($row['source_row_number']);
                if ($stored === null || $stored->decision !== $row['decision'] || $stored->decision_reason !== $row['reason'] || ! $this->manifestsEqual($stored->projection_manifest, $row['manifest'])) {
                    throw ValidationException::withMessages(['batch' => 'Frozen historical rescue order evidence drifted; re-preview is required.']);
                }
            }

            $readyRows = $locked->rows->where('decision', 'ready')->sortBy('source_row_number');
            foreach ($readyRows as $row) {
                $manifest = $row->projection_manifest;
                $erpOrderId = trim((string) ($manifest['erp_order_id'] ?? ''));
                $reference = trim((string) ($manifest['source_order_no'] ?? ''));
                if ($erpOrderId === '' || Order::query()->where('erp_order_id', $erpOrderId)->exists()) {
                    throw ValidationException::withMessages(['batch' => 'Frozen rescue ERP order identity is missing or no longer available; re-preview is required.']);
                }
                if (OrderExternalReference::query()->where('provider', self::PROVIDER)->where('connection_key', $this->connectionKey($locked->source_checksum, (int) $locked->historical_import_batch_id))->where('reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['batch' => 'An exact historical external reference now exists; re-preview is required.']);
                }
            }

            $applied = 0;
            foreach ($readyRows as $row) {
                $manifest = $row->projection_manifest;
                $kol = Kol::query()->findOrFail((int) $manifest['kol_id']);
                $order = $this->orders->create([
                    'source_type' => OrderSourceType::Kol->value,
                    'order_kind' => OrderKind::KolFulfillment->value,
                    'erp_order_id' => $manifest['erp_order_id'],
                    'external_order_number' => $manifest['source_order_no'],
                    'order_date' => $manifest['order_date'],
                    'order_note' => 'Historical rescue logistics source row #'.$row->source_row_number,
                    'currency' => 'USD',
                    ...$this->address($kol),
                ], $manifest['items'], $actor, allowMissingAddress: true);
                $order->externalReferences()->create([
                    'provider' => self::PROVIDER,
                    'connection_key' => $this->connectionKey($locked->source_checksum, (int) $locked->historical_import_batch_id),
                    'reference' => $manifest['source_order_no'],
                ]);
                $row->update(['order_id' => $order->id, 'decision' => 'applied', 'decision_reason' => 'materialized']);
                $applied++;
            }

            $locked->update(['status' => 'applied', 'applied_count' => $applied]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'logistics.historical_rescue_order_projection.applied',
                'logistics_hist_rescue_order_projection_batch',
                $locked->id,
                null,
                ['source_checksum' => $locked->source_checksum, 'applied_count' => $applied, 'ready_manifest_sha256' => $locked->ready_manifest_sha256],
                'historical_rescue_order_projection_apply',
            );
            return $locked->fresh('rows');
        });
    }

    private function buildPlan(HistoricalImportBatch $importBatch): array
    {
        $allEvidence = HistoricalEvidence::query()->where('batch_id', $importBatch->id)->with('row')->orderBy('source_row_number')->orderBy('id')->get();
        $rescueGroups = $allEvidence
            ->filter(fn (HistoricalEvidence $evidence): bool => data_get($evidence->evidence_snapshot, 'rescue.version') === self::RESCUE_VERSION)
            ->groupBy('source_row_number')->sortKeys();
        $sourceRows = HistoricalImportRow::query()->where('batch_id', $importBatch->id)->get()->keyBy('source_row_number');
        $orderNumberCounts = HistoricalImportRow::query()->where('batch_id', $importBatch->id)->get(['source_order_no'])
            ->map(fn (HistoricalImportRow $row): string => trim((string) $row->source_order_no))->filter()->countBy();

        $trackingSourceRows = [];
        foreach ($allEvidence as $evidence) {
            $key = $this->trackingKey((string) $evidence->normalized_tracking_candidate);
            if ($key !== '') {
                $trackingSourceRows[$key][(int) $evidence->source_row_number] = true;
            }
        }
        $rescueTracking = $rescueGroups->flatten(1)->map(fn (HistoricalEvidence $evidence): string => trim((string) $evidence->normalized_tracking_candidate))->filter()->unique()->values();
        $attemptTracking = ShipmentAttempt::query()->whereIn('tracking_number', $rescueTracking)->pluck('tracking_number')
            ->mapWithKeys(fn (string $tracking): array => [$this->trackingKey($tracking) => true]);
        $activeShipmentTracking = Shipment::query()->whereIn('tracking_number', $rescueTracking)->whereNotIn('shipment_status', ['cancelled', 'completed'])->pluck('tracking_number')
            ->mapWithKeys(fn (string $tracking): array => [$this->trackingKey($tracking) => true]);
        $anchor = $this->rolloverAnchor($importBatch);
        $connectionKey = $this->connectionKey($importBatch->source_checksum, (int) $importBatch->id);
        $rows = [];

        foreach ($rescueGroups as $sourceRowNumber => $evidenceGroup) {
            $rows[] = $this->decideGroup(
                $importBatch,
                $sourceRows->get((int) $sourceRowNumber),
                $evidenceGroup->values(),
                $orderNumberCounts,
                $trackingSourceRows,
                $attemptTracking,
                $activeShipmentTracking,
                $anchor,
                $connectionKey,
            );
        }

        $reserved = [];
        foreach ($rows as &$row) {
            if ($row['decision'] !== 'ready') {
                continue;
            }
            $erpOrderId = $this->reserveErpOrderId((int) $row['manifest']['kol_id'], (string) $row['manifest']['order_date'], $reserved);
            if ($erpOrderId === null) {
                $row['decision'] = 'review';
                $row['reason'] = 'erp_order_identity_exhausted';
                $row['manifest']['erp_order_id'] = null;
                $row['manifest']['decision_reason'] = 'erp_order_identity_exhausted';
                continue;
            }
            $row['manifest']['erp_order_id'] = $erpOrderId;
        }
        unset($row);

        $ready = array_values(array_filter($rows, fn (array $row): bool => $row['decision'] === 'ready'));
        $review = array_values(array_filter($rows, fn (array $row): bool => $row['decision'] === 'review'));
        $readyManifests = array_map(fn (array $row): array => $row['manifest'], $ready);

        return [
            'rows' => $rows,
            'ready_count' => count($ready),
            'review_count' => count($review),
            'ready_manifest_sha256' => hash('sha256', json_encode($this->canonicalize($readyManifests), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    private function decideGroup(
        HistoricalImportBatch $importBatch,
        ?HistoricalImportRow $sourceRow,
        Collection $evidenceGroup,
        Collection $orderNumberCounts,
        array $trackingSourceRows,
        Collection $attemptTracking,
        Collection $activeShipmentTracking,
        ?array $anchor,
        string $connectionKey,
    ): array {
        $first = $evidenceGroup->first();
        $sourceRowNumber = (int) ($sourceRow?->source_row_number ?? $first?->source_row_number ?? 0);
        $sourceOrderNo = trim((string) ($sourceRow?->source_order_no ?? data_get($first?->evidence_snapshot, 'source_order_no')));
        $evidenceManifest = $this->evidenceManifest($evidenceGroup);
        $trackingCandidates = array_values(array_map(fn (array $evidence): string => $evidence['tracking_number'], $evidenceManifest));
        $manifest = [
            'version' => self::VERSION,
            'rescue_version' => self::RESCUE_VERSION,
            'historical_import_batch_id' => (int) $importBatch->id,
            'source_checksum' => (string) $importBatch->source_checksum,
            'source_row_number' => $sourceRowNumber,
            'source_order_no' => $sourceOrderNo !== '' ? $sourceOrderNo : null,
            'kol_id' => (int) ($first?->kol_id ?? 0),
            'original_reason' => (string) data_get($first?->evidence_snapshot, 'rescue.original_reason', ''),
            'materialization_mode' => null,
            'materialization_reason' => null,
            'order_date' => null,
            'items' => [],
            'tokens' => [],
            'erp_order_id' => null,
            'connection_key' => $connectionKey,
            'evidence_count' => count($evidenceManifest),
            'evidence' => $evidenceManifest,
            'tracking_candidates' => $trackingCandidates,
            'tracking_leg_count' => count($trackingCandidates),
            'rollover_anchor' => null,
            'decision_reason' => null,
        ];
        $review = function (string $reason) use ($sourceRowNumber, $sourceOrderNo, &$manifest): array {
            $manifest['decision_reason'] = $reason;
            return [
                'source_row_number' => $sourceRowNumber,
                'source_order_no' => $sourceOrderNo !== '' ? $sourceOrderNo : null,
                'decision' => 'review',
                'reason' => $reason,
                'manifest' => $manifest,
            ];
        };

        if ($first === null || $sourceRow === null || ! $this->groupIsValid($importBatch, $sourceRow, $evidenceGroup)) {
            return $review('source_evidence_invalid');
        }
        if ($sourceOrderNo === '') {
            return $review('missing_source_order_no');
        }
        if ((int) $orderNumberCounts->get($sourceOrderNo, 0) !== 1) {
            return $review('duplicate_source_order_no');
        }
        if (OrderExternalReference::query()->where('provider', self::PROVIDER)->where('connection_key', $connectionKey)->where('reference', $sourceOrderNo)->exists()) {
            return $review('exact_external_reference_exists');
        }
        foreach ($trackingCandidates as $tracking) {
            $key = $this->trackingKey($tracking);
            if ($key === '') {
                return $review('tracking_missing');
            }
            if (count($trackingSourceRows[$key] ?? []) > 1) {
                return $review('tracking_cross_source_conflict');
            }
            if ($attemptTracking->has($key)) {
                return $review('tracking_conflict_shipment_attempt');
            }
            if ($activeShipmentTracking->has($key)) {
                return $review('tracking_conflict_active_shipment');
            }
        }

        $materialization = $this->materialization->evaluateEvidence($first);
        $mode = 'direct';
        if ($materialization['decision'] !== 'ready') {
            $rollover = $this->rolloverDecision($first, $sourceRow, $anchor);
            if ($rollover !== null) {
                $materialization = $rollover;
                $mode = 'rollover_2025';
            }
        }
        if ($materialization['decision'] !== 'ready') {
            $manifest['materialization_reason'] = $materialization['reason'];
            return $review($materialization['reason']);
        }

        $manifest['materialization_mode'] = $mode;
        $manifest['materialization_reason'] = $materialization['reason'];
        $manifest['order_date'] = $materialization['manifest']['order_date'];
        $manifest['items'] = $materialization['manifest']['items'];
        $manifest['tokens'] = $materialization['manifest']['tokens'];
        if ($mode === 'rollover_2025') {
            $manifest['rollover_anchor'] = $anchor;
        }
        $manifest['decision_reason'] = 'deterministic_rescue_order';

        return [
            'source_row_number' => $sourceRowNumber,
            'source_order_no' => $sourceOrderNo,
            'decision' => 'ready',
            'reason' => 'deterministic_rescue_order',
            'manifest' => $manifest,
        ];
    }

    private function groupIsValid(HistoricalImportBatch $importBatch, HistoricalImportRow $sourceRow, Collection $evidenceGroup): bool
    {
        if ((int) $sourceRow->batch_id !== (int) $importBatch->id || $sourceRow->classification !== 'review') {
            return false;
        }
        $expectedLegCount = $evidenceGroup->count();
        $evidenceIds = [];
        $evidenceHashes = [];
        $trackingKeys = [];
        $legIndexes = [];

        foreach ($evidenceGroup as $evidence) {
            $snapshot = $evidence->evidence_snapshot ?? [];
            $tracking = trim((string) $evidence->normalized_tracking_candidate);
            $trackingKey = $this->trackingKey($tracking);
            if (
                (int) $evidence->batch_id !== (int) $importBatch->id
                || (string) $evidence->source_checksum !== (string) $importBatch->source_checksum
                || (int) $evidence->historical_row_id !== (int) $sourceRow->id
                || (int) $evidence->source_row_number !== (int) $sourceRow->source_row_number
                || (int) $evidence->kol_id !== (int) $sourceRow->kol_id
                || data_get($snapshot, 'rescue.version') !== self::RESCUE_VERSION
                || data_get($snapshot, 'rescue.original_classification') !== 'review'
                || (string) data_get($snapshot, 'rescue.original_reason') !== (string) $sourceRow->classification_reason
                || (int) data_get($snapshot, 'source_row_number') !== (int) $sourceRow->source_row_number
                || trim((string) data_get($snapshot, 'source_order_no')) !== trim((string) $sourceRow->source_order_no)
                || trim((string) data_get($snapshot, 'source_sku_raw')) !== trim((string) $sourceRow->source_sku_raw)
                || trim((string) data_get($snapshot, 'source_quantity_raw')) !== trim((string) $sourceRow->source_quantity_raw)
                || trim((string) data_get($snapshot, 'source_input_time_raw')) !== trim((string) $sourceRow->source_input_time_raw)
                || trim((string) data_get($snapshot, 'normalized_tracking_candidate')) !== $tracking
                || (int) data_get($snapshot, 'rescue.leg_count') !== $expectedLegCount
                || $trackingKey === ''
            ) {
                return false;
            }
            $evidenceIds[(int) $evidence->id] = true;
            $evidenceHashes[(string) $evidence->evidence_hash] = true;
            $trackingKeys[$trackingKey] = true;
            $legIndexes[] = (int) data_get($snapshot, 'rescue.leg_index');
        }
        sort($legIndexes);
        return count($evidenceIds) === $expectedLegCount
            && count($evidenceHashes) === $expectedLegCount
            && count($trackingKeys) === $expectedLegCount
            && $legIndexes === range(1, $expectedLegCount);
    }

    private function evidenceManifest(Collection $evidenceGroup): array
    {
        return $evidenceGroup
            ->sortBy(fn (HistoricalEvidence $evidence): int => (int) data_get($evidence->evidence_snapshot, 'rescue.leg_index', $evidence->id))
            ->map(fn (HistoricalEvidence $evidence): array => [
                'historical_evidence_id' => (int) $evidence->id,
                'evidence_hash' => (string) $evidence->evidence_hash,
                'tracking_number' => trim((string) $evidence->normalized_tracking_candidate),
                'leg_index' => (int) data_get($evidence->evidence_snapshot, 'rescue.leg_index'),
                'leg_count' => (int) data_get($evidence->evidence_snapshot, 'rescue.leg_count'),
            ])->values()->all();
    }

    private function rolloverDecision(HistoricalEvidence $evidence, HistoricalImportRow $sourceRow, ?array $anchor): ?array
    {
        if ($anchor === null || $sourceRow->source_row_number < 3 || $sourceRow->source_row_number > 77) {
            return null;
        }
        $snapshot = $evidence->evidence_snapshot ?? [];
        $current = $this->parseDate(data_get($snapshot, 'source_input_time_raw'));
        $sourceOrderNo = strtoupper(trim((string) $sourceRow->source_order_no));
        if ($current === null || preg_match('/^2026-12-(0[1-9]|[12][0-9]|3[01])$/', $current) !== 1) {
            return null;
        }
        if (preg_match('/^KOL(?<month>0[1-9]|1[0-2])(?<day>0[1-9]|[12][0-9]|3[01])[0-9]{2}$/', $sourceOrderNo, $match) !== 1
            || sprintf('%02d-%02d', (int) $match['month'], (int) $match['day']) !== substr($current, 5)) {
            return null;
        }

        $clone = $evidence->replicate();
        $cloneSnapshot = $clone->evidence_snapshot ?? [];
        $cloneSnapshot['source_input_time_raw'] = CarbonImmutable::parse($current)->subYear()->toDateString();
        $clone->evidence_snapshot = $cloneSnapshot;
        return $this->materialization->evaluateEvidence($clone);
    }

    private function rolloverAnchor(HistoricalImportBatch $importBatch): ?array
    {
        $row = HistoricalImportRow::query()->where('batch_id', $importBatch->id)->where('source_row_number', 78)->first();
        if ($row === null || strtoupper(trim((string) $row->source_order_no)) !== 'KOL010101' || $this->parseDate($row->source_input_time_raw) !== '2026-01-01') {
            return null;
        }
        return [
            'source_row_number' => 78,
            'source_order_no' => $row->source_order_no,
            'source_input_time_raw' => $row->source_input_time_raw,
            'parsed_date' => '2026-01-01',
        ];
    }

    private function parseDate(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $raw)->toDateString();
        }
        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function reserveErpOrderId(int $kolId, string $orderDate, array &$reserved): ?string
    {
        foreach (array_merge([null], range('A', 'Z')) as $suffix) {
            $candidate = $this->orderIdentities->previewCandidate('k', $kolId, $orderDate, $suffix);
            if (isset($reserved[$candidate]) || Order::query()->where('erp_order_id', $candidate)->exists()) {
                continue;
            }
            $reserved[$candidate] = true;
            return $candidate;
        }
        return null;
    }

    private function connectionKey(string $checksum, int $batchId): string
    {
        return 'historical:'.$batchId.':'.substr($checksum, 0, 32);
    }

    private function trackingKey(string $tracking): string
    {
        return mb_strtoupper(trim($tracking));
    }

    private function address(Kol $kol): array
    {
        $address = [
            'recipient_name' => $kol->shipping_recipient_name,
            'phone' => $kol->contact_phone,
            'country' => $kol->shipping_country,
            'province_state' => $kol->shipping_province_state,
            'city' => $kol->shipping_city,
            'address_line_1' => $kol->shipping_address_line_1,
            'address_line_2' => $kol->shipping_address_line_2,
            'postal_code' => $kol->shipping_postal_code,
        ];
        return count(array_filter(array_intersect_key($address, array_flip(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1'])))) === 6 ? $address : [];
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
