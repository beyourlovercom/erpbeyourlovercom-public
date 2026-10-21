<?php

namespace App\Modules\Traffic\Infrastructure\Repositories;

use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use App\Modules\Traffic\Infrastructure\Models\LandingPage;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use App\Modules\Traffic\Infrastructure\Models\TrafficDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficInquiryDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use Illuminate\Support\Collection;

final class EloquentTrafficRepository implements TrafficRepository
{
    public function findSourceAccount(string $provider, string $externalAccountId): ?object
    {
        return TrafficSourceAccount::where('provider', $provider)
            ->where('external_account_id', $externalAccountId)
            ->first();
    }

    public function upsertSourceAccount(
        int $businessProjectId,
        string $provider,
        string $externalAccountId,
        array $attributes,
    ): object {
        $account = TrafficSourceAccount::firstOrNew([
            'provider' => $provider,
            'external_account_id' => $externalAccountId,
        ]);
        $account->business_project_id = $businessProjectId;
        $account->fill(array_intersect_key($attributes, array_flip(['name', 'currency_code', 'account_timezone', 'status', 'last_synced_at'])));
        $account->save();

        return $account;
    }

    public function upsertCampaign(
        int $trafficSourceAccountId,
        string $externalCampaignId,
        array $attributes,
    ): object {
        $campaign = TrafficCampaign::firstOrNew([
            'traffic_source_account_id' => $trafficSourceAccountId,
            'external_campaign_id' => $externalCampaignId,
        ]);
        $campaign->fill(array_intersect_key($attributes, array_flip(['name', 'status', 'advertising_channel_type', 'first_seen_at', 'last_seen_at'])));
        $campaign->save();

        return $campaign;
    }

    public function upsertDailyMetric(
        int $trafficCampaignId,
        string $metricDate,
        array $attributes,
    ): object {
        $metric = TrafficDailyMetric::firstOrNew([
            'traffic_campaign_id' => $trafficCampaignId,
            'metric_date' => $metricDate,
        ]);
        $metric->fill(array_intersect_key($attributes, array_flip(['impressions', 'clicks', 'cost_micros', 'provider_conversions'])));
        $metric->save();

        return $metric;
    }

    public function upsertInquiryDailyMetric(
        int $businessProjectId,
        string $metricDate,
        string $sourceType,
        array $attributes,
    ): object {
        $metric = TrafficInquiryDailyMetric::firstOrNew([
            'business_project_id' => $businessProjectId,
            'metric_date' => $metricDate,
            'source_type' => $sourceType,
        ]);
        $metric->fill(array_intersect_key($attributes, array_flip(['inquiries', 'qualified_inquiries'])));
        $metric->save();

        return $metric;
    }

    /** @return Collection<int, object> */
    public function listProjectSourceAccounts(int $businessProjectId): Collection
    {
        return TrafficSourceAccount::where('business_project_id', $businessProjectId)
            ->orderBy('provider')
            ->orderBy('external_account_id')
            ->get();
    }

    /** @return Collection<int, object> */
    public function listProjectDailyTrafficMetrics(int $businessProjectId, string $dateFrom, string $dateTo): Collection
    {
        $accountIds = TrafficSourceAccount::where('business_project_id', $businessProjectId)->pluck('id');
        $campaignIds = TrafficCampaign::whereIn('traffic_source_account_id', $accountIds)->pluck('id');

        return TrafficDailyMetric::with(['campaign.sourceAccount'])
            ->whereIn('traffic_campaign_id', $campaignIds)
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->orderBy('metric_date')
            ->get();
    }

    /** @return Collection<int, object> */
    public function listProjectDailyInquiryMetrics(int $businessProjectId, string $dateFrom, string $dateTo): Collection
    {
        return TrafficInquiryDailyMetric::where('business_project_id', $businessProjectId)
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->orderBy('metric_date')
            ->get();
    }

    public function registerLandingPage(int $businessProjectId, string $pageKey, array $attributes): object
    {
        $page = LandingPage::firstOrNew([
            'business_project_id' => $businessProjectId,
            'page_key' => $pageKey,
        ]);
        $page->fill(array_intersect_key($attributes, array_flip(['business_project_site_id', 'name', 'canonical_url', 'path', 'status'])));
        $page->save();

        return $page;
    }

    /** @return Collection<int, object> */
    public function listLandingPages(int $businessProjectId, string $status): Collection
    {
        return LandingPage::where('business_project_id', $businessProjectId)
            ->where('status', $status)
            ->orderBy('page_key')
            ->get();
    }
}
