<?php

namespace App\Modules\Traffic\Application\Contracts;

interface GoogleAdsReadClient
{
    /** @return array{external_account_id:string,name:?string,currency_code:?string,account_timezone:?string} */
    public function fetchAccount(string $externalAccountId): array;

    /** @return iterable<int, array<string, mixed>> */
    public function fetchCampaignDailyMetrics(
        string $externalAccountId,
        string $dateFrom,
        string $dateTo,
    ): iterable;
}
