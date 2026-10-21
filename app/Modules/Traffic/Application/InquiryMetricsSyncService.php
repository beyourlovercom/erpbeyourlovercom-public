<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Traffic\Application\Contracts\InquiryMetricSignalReader;
use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use App\Modules\Traffic\Application\Exceptions\GoogleAdsReadException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class InquiryMetricsSyncService
{
    public const QUALIFICATION_STATE = 'unavailable_until_case_truth';

    public function __construct(
        private readonly BusinessProjectService $projects,
        private readonly InquiryMetricSignalReader $reader,
        private readonly TrafficRepository $repository,
        private readonly TrafficService $traffic,
    ) {}

    /** @return array<string, mixed> */
    public function sync(
        string $projectKey,
        string $externalAccountId,
        string $dateFrom,
        string $dateTo,
        string $mode = 'dry_run',
    ): array {
        $summary = $this->summary($mode, $projectKey, $externalAccountId, $dateFrom, $dateTo);
        if (! in_array($mode, ['dry_run', 'apply'], true)) return $this->blocked($summary, 'invalid_mode');
        if (! $this->validDate($dateFrom) || ! $this->validDate($dateTo) || $dateFrom > $dateTo) {
            return $this->blocked($summary, 'invalid_date_range');
        }

        $project = $this->projects->findByKey($projectKey);
        if ($project === null) return $this->blocked($summary, 'project_not_found');

        $sourceAccount = $this->repository->findSourceAccount('google_ads', $externalAccountId);
        if ($sourceAccount === null) return $this->blocked($summary, 'source_account_not_found');
        if ((int) $sourceAccount->business_project_id !== (int) $project->id) {
            return $this->blocked($summary, 'project_account_mismatch');
        }

        try {
            $rows = [];
            $sourceTypes = [];
            $inquiriesSeen = 0;
            foreach ($this->reader->fetchDailySignals($externalAccountId, $dateFrom, $dateTo) as $row) {
                if (! is_array($row) || ! $this->validSignalRow($row, $dateFrom, $dateTo)) {
                    return $this->blocked($summary, 'invalid_inquiry_signal_row');
                }
                $normalized = [
                    'metric_date' => (string) $row['metric_date'],
                    'source_type' => trim((string) $row['source_type']),
                    'inquiries' => (int) $row['inquiries'],
                ];
                $key = $normalized['metric_date'].'|'.$normalized['source_type'];
                if (isset($rows[$key])) return $this->blocked($summary, 'duplicate_inquiry_signal_row');
                $rows[$key] = $normalized;
                $sourceTypes[$normalized['source_type']] = true;
                $inquiriesSeen += $normalized['inquiries'];
            }
        } catch (GoogleAdsReadException $e) {
            return $this->blocked($summary, $e->status);
        }

        ksort($rows);
        $summary['source_types_seen'] = array_keys($sourceTypes);
        sort($summary['source_types_seen']);
        $summary['daily_rows_seen'] = count($rows);
        $summary['inquiries_seen'] = $inquiriesSeen;

        if ($mode === 'dry_run') return $summary;

        return DB::transaction(function () use ($summary, $project, $rows): array {
            foreach ($rows as $row) {
                $this->traffic->upsertTrafficInquiryDailyMetric(
                    (int) $project->id,
                    $row['metric_date'],
                    $row['source_type'],
                    ['inquiries' => $row['inquiries'], 'qualified_inquiries' => 0],
                );
            }
            $summary['daily_rows_applied'] = count($rows);
            return $summary;
        });
    }

    /** @return array<string, mixed> */
    private function summary(string $mode, string $projectKey, string $account, string $from, string $to): array
    {
        return [
            'mode' => $mode,
            'project_key' => $projectKey,
            'external_account_id' => $account,
            'date_from' => $from,
            'date_to' => $to,
            'source_types_seen' => [],
            'daily_rows_seen' => 0,
            'inquiries_seen' => 0,
            'qualified_inquiries_seen' => 0,
            'daily_rows_applied' => 0,
            'qualification_state' => self::QUALIFICATION_STATE,
            'blocked' => [],
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];
        return $summary;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $row */
    private function validSignalRow(array $row, string $from, string $to): bool
    {
        $date = (string) ($row['metric_date'] ?? '');
        $source = trim((string) ($row['source_type'] ?? ''));
        $inquiries = $row['inquiries'] ?? null;
        return $this->validDate($date)
            && $date >= $from && $date <= $to
            && preg_match('/^[a-z0-9_]{1,32}$/D', $source) === 1
            && is_int($inquiries) && $inquiries >= 0;
    }
}
