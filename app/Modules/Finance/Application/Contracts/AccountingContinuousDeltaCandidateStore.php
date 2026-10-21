<?php

namespace App\Modules\Finance\Application\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

interface AccountingContinuousDeltaCandidateStore
{
    /** @return Collection<int,\Illuminate\Database\Eloquent\Model> */
    public function candidates(CarbonImmutable $windowStart, CarbonImmutable $windowEnd, int $limit): Collection;
}
