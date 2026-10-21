<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use Illuminate\Console\Command;

final class MaterializeOrderCostsCommand extends Command
{
    protected $signature = 'finance:materialize-costs
        {--order= : Materialize one order ID}
        {--limit=25 : Maximum number of orders to inspect}
        {--apply : Persist eligible materializations; dry-run is the default}';

    protected $description = 'Preview or locally apply evidence-backed order cost materialization.';

    public function handle(CostMaterializationService $service): int
    {
        if ($this->option('apply') && ! app()->environment(['local', 'testing'])) {
            $this->error('The --apply option is restricted to local and testing environments.');
            return self::FAILURE;
        }

        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $query = $orderClass::query()->orderBy('id');
        if ($this->option('order') !== null) {
            $query->whereKey($this->option('order'));
        } else {
            $query->where('order_kind', 'customer')
                ->whereDoesntHave('externalReferences', fn ($refs) => $refs->where('provider', 'historical_logistics'))
                ->limit(max(1, min(100, (int) $this->option('limit'))));
        }

        foreach ($query->get() as $order) {
            $result = $this->option('apply') ? $service->apply($order) : $service->preview($order);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
