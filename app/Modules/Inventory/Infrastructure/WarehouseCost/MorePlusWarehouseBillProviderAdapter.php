<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Inventory\Application\WarehouseCost\MorePlusBillFileReader;
use App\Modules\Inventory\Application\WarehouseCost\WarehouseBillProviderAdapter;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use InvalidArgumentException;

final class MorePlusWarehouseBillProviderAdapter implements WarehouseBillProviderAdapter
{
    public function __construct(private readonly MorePlusBillFileReader $reader) {}
    public function key(): string { return 'moreplus'; }
    public function parserVersion(): string { return MorePlusBillFileReader::PARSER_VERSION; }
    public function assertWarehouse(Warehouse $warehouse): void
    {
        if (strtolower((string) $warehouse->slug) !== 'us-la' && strtoupper((string) $warehouse->code) !== 'US-LA') {
            throw new InvalidArgumentException('MorePlus bill can only be imported into canonical US-LA warehouse.');
        }
    }
    public function read(string $path): array { return $this->reader->read($path); }
}
