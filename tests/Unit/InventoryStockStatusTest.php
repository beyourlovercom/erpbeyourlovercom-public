<?php

namespace Tests\Unit;

use App\Modules\Inventory\Application\InventoryStockStatus;
use PHPUnit\Framework\TestCase;

final class InventoryStockStatusTest extends TestCase
{
    public function test_boundary_rules_are_exact(): void
    {
        self::assertSame(InventoryStockStatus::OUT_OF_STOCK, InventoryStockStatus::classify(0));
        self::assertSame(InventoryStockStatus::OUT_OF_STOCK, InventoryStockStatus::classify(1));
        self::assertSame(InventoryStockStatus::LOW_STOCK, InventoryStockStatus::classify(2));
        self::assertSame(InventoryStockStatus::LOW_STOCK, InventoryStockStatus::classify(9));
        self::assertSame(InventoryStockStatus::IN_STOCK, InventoryStockStatus::classify(10));
    }
}
