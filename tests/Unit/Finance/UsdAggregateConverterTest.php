<?php

namespace Tests\Unit\Finance;

use App\Modules\Finance\Application\UsdAggregateConverter;
use App\Modules\Finance\Infrastructure\Models\OrderFinancial;
use App\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class UsdAggregateConverterTest extends TestCase
{
    #[Test]
    public function usd_amounts_are_converted_directly_to_cents(): void
    {
        $order = new Order(['currency' => 'USD']);
        $order->setRelation('financials', null);
        $converter = new UsdAggregateConverter();

        self::assertSame(12345, $converter->toUsdCents('123.45', $order));
        self::assertSame(-250, $converter->toUsdCents('-2.50', $order));
    }

    #[Test]
    public function frozen_non_usd_fx_is_applied_with_cent_rounding(): void
    {
        $order = new Order(['currency' => 'CNY']);
        $financials = new OrderFinancial(['currency' => 'CNY', 'fx_rate' => '0.14000000']);
        $order->setRelation('financials', $financials);
        $converter = new UsdAggregateConverter();

        self::assertSame(140, $converter->toUsdCents('10.00', $order));
        self::assertSame(2, $converter->toUsdCents('0.11', $order));
    }

    #[Test]
    public function missing_or_non_positive_fx_fails_closed(): void
    {
        $order = new Order(['currency' => 'CNY']);
        $order->setRelation('financials', new OrderFinancial(['currency' => 'CNY']));

        $this->expectException(UnexpectedValueException::class);
        (new UsdAggregateConverter())->toUsdCents('10.00', $order);
    }
}
