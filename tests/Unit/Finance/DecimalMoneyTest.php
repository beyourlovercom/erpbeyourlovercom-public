<?php

namespace Tests\Unit\Finance;

use App\Modules\Finance\Application\DecimalMoney;
use PHPUnit\Framework\TestCase;

final class DecimalMoneyTest extends TestCase
{
    public function test_money_operations_use_decimal_strings_and_half_up_rounding(): void
    {
        self::assertSame('0.30000000', DecimalMoney::add('0.10', '0.20'));
        self::assertSame('0.33330000', DecimalMoney::multiply('0.1111', '3'));
        self::assertSame('1.01', DecimalMoney::round('1.005'));
        self::assertSame('0.00', DecimalMoney::round('0.004'));
    }
}
