<?php

namespace App\Modules\Procurement\Providers;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Infrastructure\SourceOrderShipmentObserver;
use Illuminate\Support\ServiceProvider;

final class ProcurementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Order::observe(SourceOrderShipmentObserver::class);
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
