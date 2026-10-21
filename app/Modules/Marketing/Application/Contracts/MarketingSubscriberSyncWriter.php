<?php

namespace App\Modules\Marketing\Application\Contracts;

interface MarketingSubscriberSyncWriter
{
    /** @param array<string,mixed> $item */
    public function import(array $item, ?string $sourceSite): void;
}
