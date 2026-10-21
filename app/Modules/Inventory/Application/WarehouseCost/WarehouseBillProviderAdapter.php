<?php

namespace App\Modules\Inventory\Application\WarehouseCost;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;

interface WarehouseBillProviderAdapter
{
    public function key(): string;
    public function parserVersion(): string;
    public function assertWarehouse(Warehouse $warehouse): void;
    /** @return array<string,mixed> */
    public function read(string $path): array;
}
