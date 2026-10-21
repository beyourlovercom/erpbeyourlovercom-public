<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use InvalidArgumentException;

final class BossDashboardService
{
    public function __construct(
        private readonly BusinessProjectRepository $projects,
        private readonly TrafficService $traffic,
    ) {}

    public function build(string $projectKey, string $dateFrom, string $dateTo): array
    {
        $project = $this->projects->findByKey($projectKey);
        if ($project === null) {
            throw new InvalidArgumentException("Unknown business project [{$projectKey}].");
        }

        $projectId = (int) $project->id;
        $accounts = $this->traffic->listProjectSourceAccounts($projectId);
        $trafficRows = $this->traffic->listProjectDailyTrafficMetrics($projectId, $dateFrom, $dateTo);
        $inquiryRows = $this->traffic->listProjectDailyInquiryMetrics($projectId, $dateFrom, $dateTo);

        $currencyCodes = $accounts->pluck('currency_code')->filter()->unique()->values();
        $currencyCode = $currencyCodes->count() > 1 ? 'MIXED' : $currencyCodes->first();

        $impressions = (int) $trafficRows->sum('impressions');
        $clicks = (int) $trafficRows->sum('clicks');
        $costMicros = (int) $trafficRows->sum('cost_micros');
        $providerConversions = round($trafficRows->sum(fn ($row): float => (float) $row->provider_conversions), 4);
        $inquiries = (int) $inquiryRows->sum('inquiries');

        $trafficByDate = $trafficRows->groupBy(fn ($row): string => (string) $row->metric_date);
        $inquiryByDate = $inquiryRows->groupBy(fn ($row): string => (string) $row->metric_date);
        $dates = $trafficByDate->keys()->merge($inquiryByDate->keys())->unique()->sort()->values();

        $trend = $dates->map(function (string $date) use ($trafficByDate, $inquiryByDate): array {
            $traffic = $trafficByDate->get($date, collect());
            $inquiries = $inquiryByDate->get($date, collect());
            $costMicros = (int) $traffic->sum('cost_micros');

            return [
                'date' => $date,
                'impressions' => (int) $traffic->sum('impressions'),
                'clicks' => (int) $traffic->sum('clicks'),
                'cost_micros' => $costMicros,
                'spend' => $this->money($costMicros),
                'provider_conversions' => round($traffic->sum(fn ($row): float => (float) $row->provider_conversions), 4),
                'inquiries' => (int) $inquiries->sum('inquiries'),
                'qualified_inquiries' => null,
            ];
        })->all();

        $campaigns = $trafficRows
            ->groupBy('traffic_campaign_id')
            ->map(function ($rows): array {
                $first = $rows->first();
                $campaign = $first->campaign;
                $costMicros = (int) $rows->sum('cost_micros');

                return [
                    'external_campaign_id' => (string) $campaign->external_campaign_id,
                    'name' => (string) $campaign->name,
                    'status' => $campaign->status,
                    'advertising_channel_type' => $campaign->advertising_channel_type,
                    'impressions' => (int) $rows->sum('impressions'),
                    'clicks' => (int) $rows->sum('clicks'),
                    'cost_micros' => $costMicros,
                    'spend' => $this->money($costMicros),
                    'provider_conversions' => round($rows->sum(fn ($row): float => (float) $row->provider_conversions), 4),
                ];
            })
            ->sortByDesc('cost_micros')
            ->values()
            ->all();

        $sources = $inquiryRows
            ->groupBy('source_type')
            ->map(fn ($rows, string $sourceType): array => [
                'source_type' => $sourceType,
                'inquiries' => (int) $rows->sum('inquiries'),
                'qualified_inquiries' => null,
            ])
            ->sortByDesc('inquiries')
            ->values()
            ->all();

        $dataDates = $trafficRows->pluck('metric_date')->merge($inquiryRows->pluck('metric_date'))->map(fn ($date): string => (string) $date)->sort()->values();
        $lastSyncedAt = $accounts->map(fn ($account): ?string => $account->last_synced_at?->toISOString())->filter()->sort()->last();
        $singleCurrency = $currencyCode !== 'MIXED';

        return [
            'project' => [
                'key' => (string) $project->project_key,
                'name' => (string) $project->name,
            ],
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'summary' => [
                'currency_code' => $currencyCode,
                'mixed_currency' => $currencyCode === 'MIXED',
                'impressions' => $impressions,
                'clicks' => $clicks,
                'cost_micros' => $costMicros,
                'spend' => $singleCurrency ? $this->money($costMicros) : null,
                'provider_conversions' => $providerConversions,
                'inquiries' => $inquiries,
                'qualified_inquiries' => null,
                'qualification_state' => 'unavailable_until_case_truth',
                'cost_per_inquiry' => $singleCurrency && $inquiries > 0 ? number_format(($costMicros / 1_000_000) / $inquiries, 2, '.', '') : null,
                'cost_per_qualified_inquiry' => null,
                'ctr_percent' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc' => $singleCurrency && $clicks > 0 ? number_format(($costMicros / 1_000_000) / $clicks, 2, '.', '') : null,
            ],
            'trend' => $trend,
            'campaigns' => $campaigns,
            'sources' => $sources,
            'data_health' => [
                'traffic_rows' => $trafficRows->count(),
                'inquiry_rows' => $inquiryRows->count(),
                'data_from' => $dataDates->first(),
                'data_to' => $dataDates->last(),
                'account_last_synced_at' => $lastSyncedAt,
                'sync_mode' => 'manual',
                'automatic_sync_enabled' => false,
                'qualification_state' => 'unavailable_until_case_truth',
                'attribution_scope' => 'project_source_only',
            ],
        ];
    }

    private function money(int $costMicros): string
    {
        return number_format($costMicros / 1_000_000, 2, '.', '');
    }
}
