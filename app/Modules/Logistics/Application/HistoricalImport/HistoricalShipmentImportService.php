<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewCandidate;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class HistoricalShipmentImportService
{
    public function __construct(private readonly HistoricalShipmentXlsxReader $reader, private readonly AuditRecorder $audit) {}

    public function preview(UploadedFile $file, Authenticatable $actor): HistoricalImportBatch
    {
        $this->authorize($actor, 'logistics.operate');
        $rows = $this->reader->parse($file);
        $path = $file->getRealPath();
        $checksum = is_string($path) ? hash_file('sha256', $path) : false;
        if (! is_string($checksum)) abort(422, '无法计算 XLSX 校验值。');

        $reconciliation = $this->resolveReconciliationBatch($file->getClientOriginalName(), $rows);
        $mapping = $reconciliation ? $this->exactKolMapping($reconciliation) : [];
        $duplicates = $this->duplicateOrderNumbers($rows);

        return DB::transaction(function () use ($file, $rows, $checksum, $reconciliation, $mapping, $duplicates, $actor): HistoricalImportBatch {
            $batch = HistoricalImportBatch::create([
                'original_filename' => $file->getClientOriginalName(),
                'source_checksum' => $checksum,
                'source_sheet' => '工作表1',
                'reconciliation_batch_id' => $reconciliation?->id,
                'status' => 'preview',
                'created_by' => $actor->getAuthIdentifier(),
            ]);
            $counts = ['ready' => 0, 'review' => 0, 'skip' => 0];
            foreach ($rows as $row) {
                $orderKey = $this->normalizeOrder((string) ($row['order_no'] ?? ''));
                $classified = $this->classify(
                    $row,
                    $mapping[(int) $row['source_row_number']] ?? null,
                    $orderKey !== '' && isset($duplicates[$orderKey]),
                );
                $counts[$classified['classification']]++;
                HistoricalImportRow::create([
                    'batch_id' => $batch->id,
                    'source_row_number' => $row['source_row_number'],
                    'source_order_no' => $row['order_no'],
                    'kol_id' => $classified['kol_id'],
                    'source_status_raw' => $row['status'],
                    'source_carrier_raw' => $row['carrier'],
                    'source_warehouse_raw' => $row['warehouse'],
                    'source_tracking_raw' => $row['tracking'],
                    'source_input_time_raw' => $row['input_time'],
                    'source_sku_raw' => $row['sku'],
                    'source_quantity_raw' => $row['quantity'],
                    'normalized_tracking_candidate' => $classified['tracking'],
                    'classification' => $classified['classification'],
                    'classification_reason' => $classified['reason'],
                    'evidence_hash' => $this->evidenceHash($checksum, $row, $classified),
                ]);
            }
            $batch->update([
                'total_rows' => count($rows),
                'ready_count' => $counts['ready'],
                'review_count' => $counts['review'],
                'skip_count' => $counts['skip'],
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'logistics.historical_import.previewed',
                'logistics_historical_import_batch',
                $batch->id,
                null,
                ['source_checksum' => $checksum, 'reconciliation_batch_id' => $reconciliation?->id, ...$counts],
                'historical_import_preview',
            );
            return $batch->fresh();
        });
    }

    public function commit(HistoricalImportBatch $batch, Authenticatable $actor): HistoricalImportBatch
    {
        $this->authorize($actor, 'logistics.operate');
        if (! config('logistics.historical_import_commit_enabled', false)) {
            abort(403, 'Historical evidence commit is disabled in this environment.');
        }

        return DB::transaction(function () use ($batch, $actor): HistoricalImportBatch {
            $locked = HistoricalImportBatch::query()->lockForUpdate()->with('rows')->findOrFail($batch->id);
            if ($locked->status === 'committed') return $locked;
            if ($locked->status !== 'preview') abort(422, '该历史物流批次不可提交。');

            $created = 0;
            foreach ($locked->rows->where('classification', 'ready') as $row) {
                if (! $row->kol_id || ! $row->normalized_tracking_candidate) abort(422, 'Ready 历史物流证据缺少精确 KOL 或 Tracking。');
                $evidence = HistoricalEvidence::firstOrCreate(
                    [
                        'source_checksum' => $locked->source_checksum,
                        'source_row_number' => $row->source_row_number,
                        'evidence_hash' => $row->evidence_hash,
                    ],
                    [
                        'batch_id' => $locked->id,
                        'historical_row_id' => $row->id,
                        'kol_id' => $row->kol_id,
                        'normalized_tracking_candidate' => $row->normalized_tracking_candidate,
                        'evidence_snapshot' => $this->snapshot($row),
                    ],
                );
                if ($evidence->wasRecentlyCreated) $created++;
            }
            $locked->update(['status' => 'committed', 'committed_by' => $actor->getAuthIdentifier(), 'committed_at' => now()]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'logistics.historical_import.committed',
                'logistics_historical_import_batch',
                $locked->id,
                ['status' => 'preview'],
                ['status' => 'committed', 'ready_count' => $locked->ready_count, 'created_evidence_count' => $created],
                'historical_evidence_commit',
            );
            return $locked->fresh();
        });
    }

    public function view(HistoricalImportBatch $batch, int $page = 1, int $perPage = 50): array
    {
        $rows = $batch->rows()->with('kol')->paginate($perPage, ['*'], 'page', max(1, $page));
        return [
            'id' => (int) $batch->id,
            'filename' => $batch->original_filename,
            'checksum' => $batch->source_checksum,
            'sheet' => $batch->source_sheet,
            'reconciliation_batch_id' => $batch->reconciliation_batch_id ? (int) $batch->reconciliation_batch_id : null,
            'status' => $batch->status,
            'total_rows' => (int) $batch->total_rows,
            'ready_count' => (int) $batch->ready_count,
            'review_count' => (int) $batch->review_count,
            'skip_count' => (int) $batch->skip_count,
            'rows' => $rows->getCollection()->map(fn (HistoricalImportRow $row): array => [
                'id' => (int) $row->id,
                'source_row_number' => (int) $row->source_row_number,
                'source_order_no' => $row->source_order_no,
                'kol' => $row->kol ? [
                    'id' => (int) $row->kol->id,
                    'display_name' => $row->kol->display_name,
                    'url' => '/kol/library/'.$row->kol->id,
                ] : null,
                'carrier' => $row->source_carrier_raw,
                'tracking' => $row->normalized_tracking_candidate,
                'tracking_raw' => $row->source_tracking_raw,
                'warehouse' => $row->source_warehouse_raw,
                'classification' => $row->classification,
                'reason' => $row->classification_reason,
            ])->values()->all(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ];
    }

    private function resolveReconciliationBatch(string $uploadedFilename, array $rows): ?KolReconciliationBatch
    {
        $family = $this->sourceFamilyKey($uploadedFilename);
        if ($family === '') return null;
        $appliedIds = KolImportActivationBatch::query()->where('status', 'applied')->pluck('reconciliation_batch_id')->unique()->all();
        if ($appliedIds === []) return null;
        $sourceRows = array_values(array_unique(array_map(fn (array $row): int => (int) $row['source_row_number'], $rows)));
        $matches = KolReconciliationBatch::query()->whereIn('id', $appliedIds)->get()->filter(function (KolReconciliationBatch $batch) use ($family, $sourceRows): bool {
            if ($this->sourceFamilyKey((string) $batch->original_filename) !== $family) return false;
            if ($sourceRows === []) return false;
            return $batch->rows()->whereIn('source_row_number', $sourceRows)->distinct()->count('source_row_number') === count($sourceRows);
        })->values();
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return array<int,int> source worksheet row number => canonical KOL id */
    private function exactKolMapping(KolReconciliationBatch $reconciliation): array
    {
        $rowNumberById = $reconciliation->rows()->pluck('source_row_number', 'id')->map(fn ($value): int => (int) $value)->all();
        $previewBatchIds = DB::table('kol_import_preview_batches')->where('reconciliation_batch_id', $reconciliation->id)->pluck('id')->all();
        if ($previewBatchIds === []) return [];
        $candidates = KolImportPreviewCandidate::query()->whereIn('import_preview_batch_id', $previewBatchIds)->get();
        $applied = KolImportActivationBatch::query()
            ->where('reconciliation_batch_id', $reconciliation->id)
            ->where('status', 'applied')
            ->with('items')
            ->orderByDesc('id')
            ->get();
        $mapping = [];
        foreach ($candidates as $candidate) {
            $createdKolId = null; $guardIds = [];
            foreach ($applied as $activation) {
                $item = $activation->items->firstWhere('preview_candidate_id', $candidate->id);
                if (! $item) continue;
                if ($item->created_kol_id) { $createdKolId = (int) $item->created_kol_id; break; }
                $guard = is_array($item->existing_guard) ? $item->existing_guard : [];
                $ids = array_values(array_unique(array_map('intval', (array) ($guard['kol_ids'] ?? []))));
                if (($guard['status'] ?? null) === 'matched' && count($ids) === 1) $guardIds[] = $ids[0];
            }
            $kolId = $createdKolId;
            if (! $kolId) {
                $guardIds = array_values(array_unique($guardIds));
                if (count($guardIds) === 1) $kolId = $guardIds[0];
            }
            if (! $kolId) continue;
            foreach ((array) $candidate->source_row_ids as $rowId) {
                $rowId = (int) $rowId;
                if (isset($rowNumberById[$rowId])) $mapping[$rowNumberById[$rowId]] = $kolId;
            }
        }
        return $mapping;
    }

    private function classify(array $row, ?int $kolId, bool $duplicateOrder): array
    {
        $order = trim((string) ($row['order_no'] ?? ''));
        $status = (string) ($row['status'] ?? '');
        $trackingRaw = (string) ($row['tracking'] ?? '');
        $carrier = trim((string) ($row['carrier'] ?? ''));
        $context = $status.' '.$trackingRaw.' '.$carrier;
        if ($order === '') return $this->classification('skip', 'missing_order_no', null, $kolId);
        if ($this->isCancelledOrNoShipment($context)) return $this->classification('skip', 'cancelled_or_no_shipment', null, $kolId);

        $tokens = $this->tokens($trackingRaw, $order);
        if ($tokens === []) return $this->classification('skip', 'no_tracking_candidate', null, $kolId);
        if (count($tokens) > 1) return $this->classification('review', 'multiple_tracking_candidates', null, $kolId);
        $tracking = $tokens[0];
        if ($duplicateOrder) return $this->classification('review', 'duplicate_source_order_no', $tracking, $kolId);
        if ($this->hasReplacementOrTransferAmbiguity($context)) return $this->classification('review', 'replacement_or_transfer_ambiguity', $tracking, $kolId);
        if ($this->isSuspiciousCompoundTracking($tracking)) return $this->classification('review', 'suspicious_compound_tracking', $tracking, $kolId);
        if ($this->isComplexCarrier($carrier)) return $this->classification('review', 'carrier_requires_review', $tracking, $kolId);
        if (! $kolId) return $this->classification('review', 'kol_unresolved_exact_graph', $tracking, null);
        return $this->classification('ready', 'exact_tracking_and_kol_match', $tracking, $kolId);
    }

    private function classification(string $classification, string $reason, ?string $tracking, ?int $kolId): array
    { return ['classification' => $classification, 'reason' => $reason, 'tracking' => $tracking, 'kol_id' => $kolId]; }

    /** @return string[] */
    private function tokens(string $raw, string $sourceOrder): array
    {
        $clean = preg_replace('/https?:\/\/\S+/iu', ' ', $raw) ?? '';
        preg_match_all('/(?<![A-Z0-9])[A-Z0-9][A-Z0-9-]{7,63}(?![A-Z0-9])/iu', strtoupper($clean), $matches);
        $orderKey = $this->normalizeOrder($sourceOrder); $tokens = [];
        foreach ($matches[0] ?? [] as $token) {
            $token = strtoupper(trim((string) $token, " \t\n\r\0\x0B-"));
            $key = preg_replace('/[^A-Z0-9]/', '', $token) ?? '';
            if ($key === '' || ! preg_match('/\d/', $key)) continue;
            if (str_starts_with($key, 'KOL') || ($orderKey !== '' && $key === $orderKey)) continue;
            if (in_array($key, ['TRACKINGNO', 'ORDERNUMBER'], true)) continue;
            $tokens[$token] = true;
        }
        return array_keys($tokens);
    }

    private function isCancelledOrNoShipment(string $value): bool
    { return preg_match('/取消|cancel(?:led)?|无需发货|无需寄|不需要发|不发货|no\s*shipment/iu', $value) === 1; }
    private function hasReplacementOrTransferAmbiguity(string $value): bool
    { return preg_match('/替换|replacement|转运|transfer|中转|转单|新单号|改.{0,8}单号|换.{0,8}单号|更改地址|改地址|原单|new\s+(?:tracking|number)|changed?\s+address/iu', $value) === 1; }
    private function isSuspiciousCompoundTracking(string $tracking): bool
    {
        $key = preg_replace('/[^A-Z0-9]/', '', strtoupper($tracking)) ?? '';
        if (strlen($key) > 32) return true;
        return preg_match('/YQ[A-Z0-9]{4,}$/', $key) === 1;
    }
    private function isComplexCarrier(string $carrier): bool
    {
        if ($carrier === '') return false;
        if (mb_strlen($carrier) > 64 || preg_match('/[\r\n=]|https?:\/\//iu', $carrier)) return true;
        return preg_match('/未对账|对账|更改地址|改地址|新单号|改.{0,8}单号|换.{0,8}单号|转单|中转|原单|tracking/iu', $carrier) === 1;
    }

    /** @return array<string,true> */
    private function duplicateOrderNumbers(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = $this->normalizeOrder((string) ($row['order_no'] ?? ''));
            if ($key !== '') $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return array_fill_keys(array_keys(array_filter($counts, fn (int $count): bool => $count > 1)), true);
    }

    private function normalizeOrder(string $value): string
    { return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value))) ?? ''; }
    private function sourceFamilyKey(string $filename): string
    {
        $name = pathinfo(trim($filename), PATHINFO_FILENAME);
        if (str_ends_with(strtolower($name), '-replay')) $name = mb_substr($name, 0, mb_strlen($name) - 7);
        return mb_strtolower(trim($name));
    }
    private function evidenceHash(string $checksum, array $row, array $classified): string
    {
        return hash('sha256', json_encode([
            'source_checksum' => $checksum,
            'source_row_number' => (int) $row['source_row_number'],
            'source_order_no' => $row['order_no'],
            'kol_id' => $classified['kol_id'],
            'source_status_raw' => $row['status'],
            'source_carrier_raw' => $row['carrier'],
            'source_warehouse_raw' => $row['warehouse'],
            'source_tracking_raw' => $row['tracking'],
            'normalized_tracking_candidate' => $classified['tracking'],
            'source_input_time_raw' => $row['input_time'],
            'source_sku_raw' => $row['sku'],
            'source_quantity_raw' => $row['quantity'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    private function snapshot(HistoricalImportRow $row): array
    {
        return [
            'source_row_number' => (int) $row->source_row_number,
            'source_order_no' => $row->source_order_no,
            'kol_id' => (int) $row->kol_id,
            'source_status_raw' => $row->source_status_raw,
            'source_carrier_raw' => $row->source_carrier_raw,
            'source_warehouse_raw' => $row->source_warehouse_raw,
            'source_tracking_raw' => $row->source_tracking_raw,
            'normalized_tracking_candidate' => $row->normalized_tracking_candidate,
            'source_input_time_raw' => $row->source_input_time_raw,
            'source_sku_raw' => $row->source_sku_raw,
            'source_quantity_raw' => $row->source_quantity_raw,
        ];
    }
    private function authorize(Authenticatable $actor, string $permission): void
    { if (! $actor->can($permission)) abort(403); }
}
