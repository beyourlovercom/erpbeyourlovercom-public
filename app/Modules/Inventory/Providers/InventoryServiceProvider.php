<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Application\Contracts\InventoryBatchSyncRepository;
use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Inventory\Application\Contracts\WarehouseCostAllocationEvidenceReader;
use App\Modules\Inventory\Application\Contracts\WarehouseLotConsumptionCapture;
use App\Modules\Inventory\Application\Contracts\P9WarehouseStorageCostEvidenceReader;
use App\Modules\Inventory\Infrastructure\EloquentInventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\EloquentInventoryBatchSyncRepository;
use App\Modules\Inventory\Infrastructure\EloquentInventoryRepository;
use App\Modules\Inventory\Infrastructure\EloquentInventoryReservationRepository;
use App\Modules\Inventory\Infrastructure\EloquentWarehouseCostAllocationEvidenceReader;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseLotConsumptionService;
use App\Modules\Inventory\Infrastructure\EloquentP9WarehouseStorageCostEvidenceReader;
use Illuminate\Support\ServiceProvider;
use App\Modules\Inventory\Experience\Console\PackagingScanPreviewCommand;
use App\Modules\Inventory\Experience\Console\ImportWarehouseStorageCostCommand;
use App\Modules\Inventory\Experience\Console\BridgeMorePlusCostsCommand;
use App\Modules\Inventory\Experience\Console\AccrueWarehouseStorageAgingCostCommand;
use App\Modules\Inventory\Experience\Console\RunWarehouseStorageAgingMonthlyCommand;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InventoryRepository::class, EloquentInventoryRepository::class);
        $this->app->bind(InventoryAvailabilityReader::class, EloquentInventoryAvailabilityReader::class);
        $this->app->bind(InventoryBatchSyncRepository::class, EloquentInventoryBatchSyncRepository::class);
        $this->app->bind(InventoryReservationRepository::class, EloquentInventoryReservationRepository::class);
        $this->app->bind(WarehouseCostAllocationEvidenceReader::class, EloquentWarehouseCostAllocationEvidenceReader::class);
        $this->app->bind(P9WarehouseStorageCostEvidenceReader::class, EloquentP9WarehouseStorageCostEvidenceReader::class);
        $this->app->bind(WarehouseLotConsumptionCapture::class, WarehouseLotConsumptionService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
        if ($this->app->runningInConsole()) $this->commands([PackagingScanPreviewCommand::class, ImportWarehouseStorageCostCommand::class, BridgeMorePlusCostsCommand::class, AccrueWarehouseStorageAgingCostCommand::class, RunWarehouseStorageAgingMonthlyCommand::class]);
    }
}
