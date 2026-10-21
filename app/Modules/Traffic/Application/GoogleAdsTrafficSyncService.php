<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Traffic\Application\Contracts\GoogleAdsReadClient;
use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use App\Modules\Traffic\Application\Exceptions\GoogleAdsReadException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class GoogleAdsTrafficSyncService
{
    public function __construct(
        private readonly BusinessProjectService $projects,
        private readonly GoogleAdsReadClient $client,
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

        if (! in_array($mode, ['dry_run', 'apply'], true)) {
            return $this->blocked($summary, 'invalid_mode');
        }
        if (! $this->validDate($dateFrom) || ! $this->validDate($dateTo) || $dateFrom > $dateTo) {
            return $this->blocked($summary, 'invalid_date_range');
        }

        $project = $this->projects->findByKey($projectKey);
        if ($project === null) {
            return $this->blocked($summary, 'project_not_found');
        }

        $existing = $this->repository->findSourceAccount('google_ads', $externalAccountId);
        if ($existing !== null && (int) $existing->business_project_id !== (int) $project->id) {
            return $this->blocked($summary, 'project_account_mismatch');
        }

        try {
            $account = $this->client->fetchAccount($externalAccountId);
            if (! $this->sameAccount($externalAccountId, (string) ($account['external_account_id'] ?? ''))) {
                return $this->blocked($summary, 'source_account_mismatch');
            }

            $rows = [];
            $campaigns = [];
            foreach ($this->client->fetchCampaignDailyMetrics($externalAccountId, $dateFrom, $dateTo) as $row) {
                if (! is_array($row) || ! $this->validMetricRow($row, $dateFrom, $dateTo)) {
                    return $this->blocked($summary, 'invalid_metric_row');
                }

                $normalized = $this->normalizeMetricRow($row);
                $key = $normalized['external_campaign_id'].'|'.$normalized['metric_date'];
                $rows[$key] = $normalized;
                $campaigns[$normalized['external_campaign_id']] = $normalized;
            }
        } catch (GoogleAdsReadException $e) {
            return $this->blocked($summary, $e->status);
        }

        ksort($rows);
        ksort($campaigns);
        $summary['campaigns_seen'] = count($campaigns);
        $summary['daily_rows_seen'] = count($rows);

        if ($mode === 'dry_run') {
            return $summary;
        }

        return DB::transaction(function () use ($summary, $project, $account, $externalAccountId, $campaigns, $rows): array {
            $sourceAccount = $this->traffic->upsertTrafficSourceAccount((int) $project->id, 'google_ads', $externalAccountId, [
                'name' => $account['name'] ?? null,
                'currency_code' => $account['currency_code'] ?? null,
                'account_timezone' => $account['account_timezone'] ?? null,
                'status' => 'active',
                'last_synced_at' => now(),
            ]);

            $campaignModels = [];
            foreach ($campaigns as $campaignId => $row) {
                $campaignModels[$campaignId] = $this->traffic->upsertTrafficCampaign((int) $sourceAccount->id, $campaignId, [
                    'name' => $row['campaign_name'],
                    'status' => $row['campaign_status'],
                    'advertising_channel_type' => $row['advertising_channel_type'],
                    'last_seen_at' => $row['metric_date'],
                ]);
            }

            foreach ($rows as $row) {
                $campaign = $campaignModels[$row['external_campaign_id']];
                $this->traffic->upsertTrafficDailyMetric((int) $campaign->id, $row['metric_date'], [
                    'impressions' => $row['impressions'],
                    'clicks' => $row['clicks'],
                    'cost_micros' => $row['cost_micros'],
                    'provider_conversions' => $row['provider_conversions'],
                ]);
            }

            $summary['accounts_applied'] = 1;
            $summary['campaigns_applied'] = count($campaigns);
            $summary['daily_rows_applied'] = count($rows);

            return $summary;
        });
    }

    /** @return array<string, mixed> */
    private function summary(string $mode, string $projectKey, string $externalAccountId, string $dateFrom, string $dateTo): array
    {
        return [
            'mode' => $mode,
            'project_key' => $projectKey,
            'external_account_id' => $externalAccountId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'campaigns_seen' => 0,
            'daily_rows_seen' => 0,
            'accounts_applied' => 0,
            'campaigns_applied' => 0,
            'daily_rows_applied' => 0,
            'blocked' => [],
        ];
    }

    /** @param array<string, mixed> $summary */
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
    private function validMetricRow(array $row, string $dateFrom, string $dateTo): bool
    {
        $campaignId = trim((string) ($row['external_campaign_id'] ?? ''));
        $campaignName = trim((string) ($row['campaign_name'] ?? ''));
        $metricDate = (string) ($row['metric_date'] ?? '');

        if ($campaignId === '' || $campaignName === '' || ! $this->validDate($metricDate) || $metricDate < $dateFrom || $metricDate > $dateTo) {
            return false;
        }

        foreach (['impressions', 'clicks', 'cost_micros'] as $field) {
            if ($this->nonNegativeInteger($row[$field] ?? null) === null) {
                return false;
            }
        }

        $conversions = $row['provider_conversions'] ?? null;

        return is_numeric($conversions) && (float) $conversions >= 0;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeMetricRow(array $row): array
    {
        return [
            'external_campaign_id' => trim((string) $row['external_campaign_id']),
            'campaign_name' => trim((string) $row['campaign_name']),
            'campaign_status' => $this->nullableString($row['campaign_status'] ?? null),
            'advertising_channel_type' => $this->nullableString($row['advertising_channel_type'] ?? null),
            'metric_date' => (string) $row['metric_date'],
            'impressions' => $this->nonNegativeInteger($row['impressions']) ?? 0,
            'clicks' => $this->nonNegativeInteger($row['clicks']) ?? 0,
            'cost_micros' => $this->nonNegativeInteger($row['cost_micros']) ?? 0,
            'provider_conversions' => (float) $row['provider_conversions'],
        ];
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $normalized = ltrim($value, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $max = (string) PHP_INT_MAX;
            if (strlen($normalized) > strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
                return null;
            }

            return (int) $normalized;
        }
        if (is_float($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX) {
            return (int) $value;
        }

        return null;
    }

    private function sameAccount(string $left, string $right): bool
    {
        $normalize = static fn (string $value): string => preg_replace('/\D+/', '', $value) ?? '';

        return $normalize($left) !== '' && $normalize($left) === $normalize($right);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
