<?php

namespace Tests\Unit;

use App\Modules\Inventory\Application\WarehouseStorageAgingPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WarehouseStorageAgingPolicyTest extends TestCase
{
    public function test_owner_frozen_age_boundaries_round_partial_month_up(): void
    {
        $policy = new WarehouseStorageAgingPolicy();
        $putaway = '2026-01-01';

        self::assertSame(0, $policy->billableMonths($putaway, '2026-04-01')); // day 90
        self::assertSame(1, $policy->billableMonths($putaway, '2026-04-02')); // day 91
        self::assertSame(1, $policy->billableMonths($putaway, '2026-05-01')); // day 120
        self::assertSame(2, $policy->billableMonths($putaway, '2026-05-02')); // day 121
        self::assertSame(2, $policy->billableMonths($putaway, '2026-05-31')); // day 150
        self::assertSame(3, $policy->billableMonths($putaway, '2026-06-01')); // day 151
    }

    public function test_cost_is_per_physical_unit_not_per_sku(): void
    {
        $policy = new WarehouseStorageAgingPolicy();

        $projection = $policy->project('2026-01-01', '2026-05-02', 17);

        self::assertSame(121, $projection['age_days']);
        self::assertSame(2, $projection['billable_months']);
        self::assertSame(17, $projection['quantity']);
        self::assertSame('1.00', $projection['unit_rate_cny']);
        self::assertSame('34.00', $projection['cumulative_amount_cny']);
        self::assertSame('17.00', $policy->amountForOneMonth(17));
    }

    public function test_zero_quantity_has_zero_cost_even_when_old(): void
    {
        $policy = new WarehouseStorageAgingPolicy();

        $projection = $policy->project('2025-01-01', '2026-09-18', 0);

        self::assertGreaterThan(0, $projection['billable_months']);
        self::assertSame('0.00', $projection['cumulative_amount_cny']);
    }

    public function test_future_putaway_date_fails_closed(): void
    {
        $policy = new WarehouseStorageAgingPolicy();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storage_aging_putaway_after_as_of');

        $policy->project('2026-09-19', '2026-09-18', 1);
    }

    public function test_negative_quantity_fails_closed(): void
    {
        $policy = new WarehouseStorageAgingPolicy();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storage_aging_negative_quantity');

        $policy->project('2026-01-01', '2026-09-18', -1);
    }
}
