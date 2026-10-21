<?php

namespace App\Modules\Logistics\Providers;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\Contracts\CarrierBillingEvidenceReader;
use App\Modules\Logistics\Application\Contracts\DeliveryEvidenceGateway;
use App\Modules\Logistics\Application\Contracts\FulfillmentOriginEvidenceReader;
use App\Modules\Logistics\Application\Contracts\LastMileEstimateEvidenceReader;
use App\Modules\Logistics\Application\Contracts\OverseasHandlingEvidenceReader;
use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Logistics\Application\UsFulfillmentZoneResolver;
use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
use App\Modules\Logistics\Experience\Console\Commands\BackfillCustomerHistoricalShipmentsCommand;
use App\Modules\Logistics\Experience\Console\Commands\BackfillHistoricalFulfillmentShipmentsCommand;
use App\Modules\Logistics\Experience\Console\Commands\ImportCarrierBillCommand;
use App\Modules\Logistics\Experience\Console\Commands\RepairProductionUatFulfillmentCommand;
use App\Modules\Logistics\Experience\Console\Commands\SyncDeliveryEvidenceCommand;
use App\Modules\Logistics\Infrastructure\AfterShipBrandedPageDeliveryEvidenceGateway;
use App\Modules\Logistics\Infrastructure\EloquentCarrierBillingEvidenceReader;
use App\Modules\Logistics\Infrastructure\EloquentFulfillmentOriginEvidenceReader;
use App\Modules\Logistics\Infrastructure\EloquentLastMileEstimateEvidenceReader;
use App\Modules\Logistics\Infrastructure\EloquentOverseasHandlingEvidenceReader;
use Illuminate\Support\ServiceProvider;

final class LogisticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/oversea_manual.php');
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillCustomerHistoricalShipmentsCommand::class,
                BackfillHistoricalFulfillmentShipmentsCommand::class,
                ImportCarrierBillCommand::class,
                RepairProductionUatFulfillmentCommand::class,
                SyncDeliveryEvidenceCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->app->bind(CarrierBillingEvidenceReader::class, EloquentCarrierBillingEvidenceReader::class);
        $this->app->bind(DeliveryEvidenceGateway::class, AfterShipBrandedPageDeliveryEvidenceGateway::class);
        $this->app->bind(FulfillmentOriginEvidenceReader::class, EloquentFulfillmentOriginEvidenceReader::class);
        $this->app->bind(LastMileEstimateEvidenceReader::class, EloquentLastMileEstimateEvidenceReader::class);
        $this->app->bind(OverseasHandlingEvidenceReader::class, EloquentOverseasHandlingEvidenceReader::class);
        $this->app->bind(FulfillmentPlanPreviewService::class, fn ($app): FulfillmentPlanPreviewService => new FulfillmentPlanPreviewService(
            $app->make(InventoryAvailabilityReader::class),
            $app->make(WarehouseRoutingPolicy::class),
            $app->make(CarrierRoutingPolicy::class),
            $app->make(UsFulfillmentZoneResolver::class),
            $app->make(FulfillmentPlanCostEstimator::class),
        ));
    }
}
