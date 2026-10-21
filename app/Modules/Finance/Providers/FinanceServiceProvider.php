<?php

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Application\ConfirmedLossProjection;
use App\Modules\Finance\Application\Contracts\AccountingContinuousDeltaCandidateStore;
use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use App\Modules\Finance\Application\Contracts\FxManualRateRepository;
use App\Modules\Finance\Application\Contracts\WooFinancialBootstrapOrderStore;
use App\Modules\Finance\Infrastructure\EloquentAccountingContinuousDeltaCandidateStore;
use App\Modules\Finance\Infrastructure\EloquentFxManualRateRepository;
use App\Modules\Finance\Infrastructure\EloquentWooFinancialBootstrapOrderStore;
use App\Modules\Finance\Experience\Console\Commands\BootstrapWooFinancialEvidenceCommand;
use App\Modules\Finance\Experience\Console\Commands\GatewayEconomicsPreviewCommand;
use App\Modules\Finance\Experience\Console\Commands\PaymentReliabilityClosureCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeActualLastMileCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeContinuousDeltaCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeFixedCostsCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeLastMileEstimatesCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeKolCommissionsCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeMultiShipmentCostsCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeOrderCostsCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializePaymentFeesCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeProductCostsCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeWarehouseCostsCommand;
use App\Modules\Finance\Experience\Console\Commands\P82EstimatedReconciliationCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeP82EstimatedLastMileCommand;
use App\Modules\Finance\Experience\Console\Commands\RepairPaymentFeeLegacyFxCommand;
use Illuminate\Support\ServiceProvider;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountingContinuousDeltaCandidateStore::class, EloquentAccountingContinuousDeltaCandidateStore::class);
        $this->app->bind(ConfirmedLossReader::class, ConfirmedLossProjection::class);
        $this->app->bind(FxManualRateRepository::class, EloquentFxManualRateRepository::class);
        $this->app->bind(WooFinancialBootstrapOrderStore::class, EloquentWooFinancialBootstrapOrderStore::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([MaterializeOrderCostsCommand::class, MaterializeActualLastMileCommand::class, MaterializeContinuousDeltaCommand::class, MaterializeFixedCostsCommand::class, MaterializeLastMileEstimatesCommand::class, MaterializeKolCommissionsCommand::class, MaterializeMultiShipmentCostsCommand::class, MaterializeProductCostsCommand::class, MaterializeWarehouseCostsCommand::class, MaterializePaymentFeesCommand::class, RepairPaymentFeeLegacyFxCommand::class, BootstrapWooFinancialEvidenceCommand::class, GatewayEconomicsPreviewCommand::class, PaymentReliabilityClosureCommand::class, P82EstimatedReconciliationCommand::class, MaterializeP82EstimatedLastMileCommand::class]);
        }
    }
}
