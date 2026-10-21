<?php

namespace App\Modules\Inventory\Application;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class WarehouseStorageAgingPolicy
{
    public const FREE_DAYS = 90;
    public const BILLING_DAYS = 30;
    public const UNIT_RATE_CNY = '1.00';

    public function billableMonths(CarbonInterface|string $putawayDate, CarbonInterface|string $asOf): int
    {
        $putaway = $putawayDate instanceof CarbonInterface
            ? CarbonImmutable::instance($putawayDate)->startOfDay()
            : CarbonImmutable::parse($putawayDate)->startOfDay();

        $date = $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->startOfDay()
            : CarbonImmutable::parse($asOf)->startOfDay();

        if ($putaway->isAfter($date)) {
            throw new InvalidArgumentException('storage_aging_putaway_after_as_of');
        }

        $ageDays = (int) $putaway->diffInDays($date);
        if ($ageDays <= self::FREE_DAYS) {
            return 0;
        }

        return (int) ceil(($ageDays - self::FREE_DAYS) / self::BILLING_DAYS);
    }

    /** @return array{age_days:int,billable_months:int,quantity:int,unit_rate_cny:string,cumulative_amount_cny:string} */
    public function project(
        CarbonInterface|string $putawayDate,
        CarbonInterface|string $asOf,
        int $quantity,
    ): array {
        if ($quantity < 0) {
            throw new InvalidArgumentException('storage_aging_negative_quantity');
        }

        $putaway = $putawayDate instanceof CarbonInterface
            ? CarbonImmutable::instance($putawayDate)->startOfDay()
            : CarbonImmutable::parse($putawayDate)->startOfDay();

        $date = $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->startOfDay()
            : CarbonImmutable::parse($asOf)->startOfDay();

        if ($putaway->isAfter($date)) {
            throw new InvalidArgumentException('storage_aging_putaway_after_as_of');
        }

        $ageDays = (int) $putaway->diffInDays($date);
        $months = $this->billableMonths($putaway, $date);
        $amount = bcmul((string) $quantity, (string) $months, 2);

        return [
            'age_days' => $ageDays,
            'billable_months' => $months,
            'quantity' => $quantity,
            'unit_rate_cny' => self::UNIT_RATE_CNY,
            'cumulative_amount_cny' => $amount,
        ];
    }

    public function amountForOneMonth(int $quantity): string
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('storage_aging_negative_quantity');
        }

        return bcmul((string) $quantity, self::UNIT_RATE_CNY, 2);
    }
}
