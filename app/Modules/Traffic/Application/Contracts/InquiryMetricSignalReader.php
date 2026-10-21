<?php

namespace App\Modules\Traffic\Application\Contracts;

interface InquiryMetricSignalReader
{
    /** @return iterable<int, array{metric_date:string,source_type:string,inquiries:int}> */
    public function fetchDailySignals(
        string $externalAccountId,
        string $dateFrom,
        string $dateTo,
    ): iterable;
}
