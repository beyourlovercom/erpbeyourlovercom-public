<?php

namespace App\Modules\Traffic\Application\Contracts;

use Illuminate\Support\Collection;

interface TrafficRepository
{
    public function findSourceAccount(string $provider, string $externalAccountId): ?object;

    public function upsertSourceAccount(
        int $businessProjectId,
        string $provider,
        string $externalAccountId,
        array $attributes,
    ): object;

    public function upsertCampaign(
        int $trafficSourceAccountId,
        string $externalCampaignId,
        array $attributes,
    ): object;

    public function upsertDailyMetric(
        int $trafficCampaignId,
        string $metricDate,
        array $attributes,
    ): object;

    public function upsertInquiryDailyMetric(
        int $businessProjectId,
        string $metricDate,
        string $sourceType,
        array $attributes,
    ): object;

    /** @return Collection<int, object> */
    public function listProjectSourceAccounts(int $businessProjectId): Collection;

    /** @return Collection<int, object> */
    public function listProjectDailyTrafficMetrics(int $businessProjectId, string $dateFrom, string $dateTo): Collection;

    /** @return Collection<int, object> */
    public function listProjectDailyInquiryMetrics(int $businessProjectId, string $dateFrom, string $dateTo): Collection;

    public function registerLandingPage(int $businessProjectId, string $pageKey, array $attributes): object;

    /** @return Collection<int, object> */
    public function listLandingPages(int $businessProjectId, string $status): Collection;
}
