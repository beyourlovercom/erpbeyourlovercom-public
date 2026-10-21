<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Inventory\Application\WarehouseCost\WarehouseBillProviderAdapter;
use InvalidArgumentException;

final class WarehouseBillProviderRegistry
{
    /** @var array<string,WarehouseBillProviderAdapter> */
    private array $adapters;
    public function __construct(MorePlusWarehouseBillProviderAdapter $morePlus, SpeedaUsNyWarehouseBillProviderAdapter $speedaUsNy)
    {
        $this->adapters = [$morePlus->key() => $morePlus, $speedaUsNy->key() => $speedaUsNy];
    }
    public function get(string $provider): WarehouseBillProviderAdapter
    {
        $key = strtolower(trim($provider));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Warehouse bill provider key is invalid.');
        }
        return $this->adapters[$key] ?? throw new InvalidArgumentException('Unsupported warehouse bill provider.');
    }
    /** @return list<string> */
    public function keys(): array { return array_keys($this->adapters); }
}
