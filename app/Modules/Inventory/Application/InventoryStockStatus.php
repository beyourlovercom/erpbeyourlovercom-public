<?php

namespace App\Modules\Inventory\Application;

final class InventoryStockStatus
{
    public const OUT_OF_STOCK = 'out_of_stock';
    public const LOW_STOCK = 'low_stock';
    public const IN_STOCK = 'in_stock';

    public static function classify(int $quantityAvailable): string
    {
        $available = max(0, $quantityAvailable);
        if ($available <= 1) return self::OUT_OF_STOCK;
        if ($available <= 9) return self::LOW_STOCK;
        return self::IN_STOCK;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::OUT_OF_STOCK => '没货',
            self::LOW_STOCK => '库存紧张',
            self::IN_STOCK => '有货',
            default => '未知',
        };
    }
}
