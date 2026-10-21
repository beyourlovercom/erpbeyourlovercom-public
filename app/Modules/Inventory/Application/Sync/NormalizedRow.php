<?php

namespace App\Modules\Inventory\Application\Sync;

final class NormalizedRow
{
    public function __construct(
        public readonly int     $sourceRowNo,
        public readonly ?string $sku,
        public readonly ?string $stockStatus,      // sellable | defective | null
        public readonly ?string $stockCountAt,     // Y-m-d or null
        public readonly ?string $putawayDate,      // Y-m-d or null
        public readonly ?int    $quantity,
        public readonly ?string $rawProductName    = null,
        public readonly ?string $rawWarehouseName  = null,
        public readonly ?string $rawDimension      = null,
        public readonly ?string $rawTotalVolume    = null,
        public readonly ?string $rawStockStatus    = null,
        public readonly ?string $rawStockCountDate = null,
        public readonly ?string $rawPutawayDate    = null,
        public readonly ?string $rawQuantity       = null,
    ) {}
}
