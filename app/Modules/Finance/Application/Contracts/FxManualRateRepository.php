<?php

namespace App\Modules\Finance\Application\Contracts;

interface FxManualRateRepository
{
    /** @return array<string, array{rate: float, automatic: bool}> */
    public function all(): array;

    /**
     * @param array<string, float|null> $rates
     * @param array<string, bool> $automatic
     */
    public function replace(array $rates, array $automatic, ?int $updatedBy): void;
}
