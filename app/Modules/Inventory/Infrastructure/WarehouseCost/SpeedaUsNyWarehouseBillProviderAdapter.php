<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Inventory\Application\WarehouseCost\SpeedaUsNyBillFileReader;
use App\Modules\Inventory\Application\WarehouseCost\WarehouseBillProviderAdapter;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use InvalidArgumentException;

final class SpeedaUsNyWarehouseBillProviderAdapter implements WarehouseBillProviderAdapter
{
    public function __construct(private readonly SpeedaUsNyBillFileReader $reader) {}
    public function key(): string { return 'speeda-us-ny'; }
    public function parserVersion(): string { return SpeedaUsNyBillFileReader::PARSER_VERSION; }
    public function assertWarehouse(Warehouse $warehouse): void
    {
        if (strtolower((string)$warehouse->slug) !== 'us-ny' && strtoupper((string)$warehouse->code) !== 'US-NY') {
            throw new InvalidArgumentException('Speeda US-NY bill can only be imported into canonical US-NY warehouse.');
        }
    }
    public function read(string $path): array { return $this->reader->read($path); }
}
