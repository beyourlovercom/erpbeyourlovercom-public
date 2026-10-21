<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class HistoricalReviewRescueService
{
    private const VERSION = 'historical-review-rescue-v1';
    private const RESCUE_REASONS = [
        'multiple_tracking_candidates',
        'suspicious_compound_tracking',
        'duplicate_source_order_no',
        'replacement_or_transfer_ambiguity',
        'carrier_requires_review',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<string,mixed> */
    public function plan(HistoricalImportBatch $batch): array
    {
        $rows = $batch->rows()->where('classification', 'review')->orderBy('source_row_number')->get();
        $items = [];
        $groups = [];
        $manual = 0;
        foreach ($rows as $row) {
            $candidates = $this->rescueCandidates($row);
            if (! $row->kol_id || ! in_array($row->classification_reason, self::RESCUE_REASONS, true) || $candidates === []) {
                $manual++;
                continue;
            }
            $hashes = array_map(fn (string $tracking): string => $this->evidenceHash($batch, $row, $tracking), $candidates);
            $items[] = ['row' => $row, 'candidates' => $candidates, 'hashes' => $hashes];
            $reason = $row->classification_reason;
            $groups[$reason] ??= ['reason' => $reason, 'rows' => 0, 'evidence' => 0];
            $groups[$reason]['rows']++;
            $groups[$reason]['evidence'] += count($candidates);
        }
        $allHashes = $items === [] ? [] : array_values(array_merge(...array_map(fn (array $item): array => $item['hashes'], $items)));
        $existing = $allHashes === [] ? [] : HistoricalEvidence::query()
            ->where('source_checksum', $batch->source_checksum)
            ->whereIn('evidence_hash', $allHashes)
            ->pluck('evidence_hash')->all();
        $existingMap = array_fill_keys($existing, true);
        $existingCount = count(array_filter($allHashes, fn (string $hash): bool => isset($existingMap[$hash])));

        return [
            'rescueable_rows' => count($items),
            'candidate_evidence_count' => count($allHashes),
            'existing_evidence_count' => $existingCount,
            'pending_evidence_count' => count($allHashes) - $existingCount,
            'manual_review_rows' => $manual,
            'groups' => array_values($groups),
        ];
    }

    public function commit(HistoricalImportBatch $batch, Authenticatable $actor): HistoricalImportBatch
    {
        $this->authorize($actor, 'logistics.operate');
        if (! config('logistics.historical_review_rescue_commit_enabled', false)) {
            abort(403, 'Historical review rescue commit is disabled in this environment.');
        }

        return DB::transaction(function () use ($batch, $actor): HistoricalImportBatch {
            $locked = HistoricalImportBatch::query()->lockForUpdate()->with('rows')->findOrFail($batch->id);
            $created = 0;
            $rescueableRows = 0;
            foreach ($locked->rows->where('classification', 'review') as $row) {
                $candidates = $this->rescueCandidates($row);
                if (! $row->kol_id || ! in_array($row->classification_reason, self::RESCUE_REASONS, true) || $candidates === []) continue;
                $rescueableRows++;
                $legCount = count($candidates);
                foreach ($candidates as $index => $tracking) {
                    $hash = $this->evidenceHash($locked, $row, $tracking);
                    $evidence = HistoricalEvidence::firstOrCreate(
                        ['source_checksum' => $locked->source_checksum, 'source_row_number' => $row->source_row_number, 'evidence_hash' => $hash],
                        [
                            'batch_id' => $locked->id,
                            'historical_row_id' => $row->id,
                            'kol_id' => $row->kol_id,
                            'normalized_tracking_candidate' => $tracking,
                            'evidence_snapshot' => $this->snapshot($row, $tracking, $index + 1, $legCount, $candidates),
                        ],
                    );
                    if ($evidence->wasRecentlyCreated) $created++;
                }
            }
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'logistics.historical_import.review_rescue_committed',
                'logistics_historical_import_batch',
                $locked->id,
                null,
                ['rescueable_rows' => $rescueableRows, 'created_evidence_count' => $created, 'rescue_version' => self::VERSION],
                'historical_review_rescue_commit',
            );
            return $locked->fresh();
        });
    }

    /** @return list<string> */
    public function rescueCandidates(HistoricalImportRow $row): array
    {
        $raw = trim((string) $row->source_tracking_raw)."\n".trim((string) $row->source_carrier_raw);
        $clean = preg_replace('/https?:\/\/\S+/iu', ' ', $raw) ?? '';
        $clean = preg_replace_callback(
            '/(WSH[A-Z]{2}\d{8,12}YQ)(JMX\d{8,14}|CO\d{8,12}|ND\d{7,12}BR|EA\d{8,12}|PE\d{8,12}|CNMEX\d{8,12}|\d{8,12})(?![A-Z0-9])/iu',
            fn (array $m): string => $m[1].' '.$m[2],
            $clean,
        ) ?? $clean;
        preg_match_all('/(?<![A-Z0-9])[A-Z0-9][A-Z0-9-]{7,63}(?![A-Z0-9])/iu', strtoupper($clean), $matches);
        $orderKey = $this->key((string) $row->source_order_no);
        $candidates = [];
        foreach ($matches[0] ?? [] as $token) {
            $token = strtoupper(trim((string) $token, " \t\n\r\0\x0B-"));
            $key = $this->key($token);
            if ($key === '' || ! preg_match('/\d/', $key)) continue;
            if (str_starts_with($key, 'KOL') || ($orderKey !== '' && $key === $orderKey)) continue;
            if (in_array($key, ['TRACKINGNO', 'ORDERNUMBER'], true)) continue;
            $candidates[$key] ??= $token;
        }
        return array_values($candidates);
    }

    private function evidenceHash(HistoricalImportBatch $batch, HistoricalImportRow $row, string $tracking): string
    {
        return hash('sha256', json_encode([
            'version' => self::VERSION,
            'source_checksum' => $batch->source_checksum,
            'source_row_number' => (int) $row->source_row_number,
            'kol_id' => (int) $row->kol_id,
            'original_reason' => $row->classification_reason,
            'normalized_tracking_candidate' => $tracking,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $candidates */
    private function snapshot(HistoricalImportRow $row, string $tracking, int $legIndex, int $legCount, array $candidates): array
    {
        return [
            'source_row_number' => (int) $row->source_row_number,
            'source_order_no' => $row->source_order_no,
            'kol_id' => (int) $row->kol_id,
            'source_status_raw' => $row->source_status_raw,
            'source_carrier_raw' => $row->source_carrier_raw,
            'source_warehouse_raw' => $row->source_warehouse_raw,
            'source_tracking_raw' => $row->source_tracking_raw,
            'normalized_tracking_candidate' => $tracking,
            'source_input_time_raw' => $row->source_input_time_raw,
            'source_sku_raw' => $row->source_sku_raw,
            'source_quantity_raw' => $row->source_quantity_raw,
            'rescue' => [
                'version' => self::VERSION,
                'original_classification' => 'review',
                'original_reason' => $row->classification_reason,
                'leg_index' => $legIndex,
                'leg_count' => $legCount,
                'all_tracking_candidates' => $candidates,
            ],
        ];
    }

    private function key(string $value): string
    { return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value))) ?? ''; }

    private function authorize(Authenticatable $actor, string $permission): void
    { if (! $actor->can($permission)) abort(403); }
}
