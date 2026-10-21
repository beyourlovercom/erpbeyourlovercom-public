<?php

namespace App\Modules\Inventory\Application\Contracts;

use Illuminate\Database\Eloquent\Model;

interface WarehouseLotConsumptionCapture
{
    /** @return array<string,mixed> */
    public function previewForShipment(Model $shipment): array;

    /** @return array<string,mixed> */
    public function captureForShipment(Model $shipment): array;

    public function assertShipmentMayReset(Model $shipment): void;
}
