<?php

namespace App\Modules\Traffic\Application;

use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use Illuminate\Support\Collection;

final class TrafficService
{
    public function __construct(private readonly TrafficRepository $repository) {}

    public function upsertTrafficSourceAccount(
        int $businessProjectId,
        string $provider,
        string $externalAccountId,
        array $attributes = [],
    ): object {
        return $this->repository->upsertSourceAccount($businessProjectId, $provider, $externalAccountId, $attributes);
    }

    public function upsertTrafficCampaign(
        int $trafficSourceAccountId,
        string $externalCampaignId,
        array $attributes = [],
    ): object {
        return $this->repository->upsertCampaign($trafficSourceAccountId, $externalCampaignId, $attributes);
    }

    public function upsertTrafficDailyMetric(
        int $trafficCampaignId,
        string $metricDate,
        array $attributes,
    ): object {
        return $this->repository->upsertDailyMetric($trafficCampaignId, $metricDate, $attributes);
    }

    public function upsertTrafficInquiryDailyMetric(
        int $businessProjectId,
        string $metricDate,
        string $sourceType,
        array $attributes,
    ): object {
        return $this->repository->upsertInquiryDailyMetric($businessProjectId, $metricDate, $sourceType, $attributes);
    }

    /** @return Collection<int, object> */
    public function listProjectSourceAccounts(int $businessProjectId): Collection
    {
        return $this->repository->listProjectSourceAccounts($businessProjectId);
    }

    /** @return Collection<int, object> */
    public function listProjectDailyTrafficMetrics(int $businessProjectId, string $dateFrom, string $dateTo): Collection
    {
        return $this->repository->listProjectDailyTrafficMetrics($businessProjectId, $dateFrom, $dateTo);
    }

    /** @return Collection<int, object> */
    public function listProjectDailyInquiryMetrics(int $businessProjectId, string $dateFrom, string $dateTo): Collection
    {
        return $this->repository->listProjectDailyInquiryMetrics($businessProjectId, $dateFrom, $dateTo);
    }

    public function registerLandingPage(
        int $businessProjectId,
        string $pageKey,
        array $attributes,
    ): object {
        return $this->repository->registerLandingPage($businessProjectId, $pageKey, $attributes);
    }

    /** @return Collection<int, object> */
    public function listLandingPages(int $businessProjectId, string $status = 'active'): Collection
    {
        return $this->repository->listLandingPages($businessProjectId, $status);
    }
}
