<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Logistics\Application\AutomaticAllocationService;
use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;

final class AutomaticAllocationCommand extends Command
{
    protected $signature = 'inventory:auto-allocate
        {--limit= : Maximum pending Orders to inspect}
        {--order= : Inspect one exact Order id}
        {--dry-run : Explicit preview mode with no Reservation or Shipment mutation}
        {--apply : Apply Reservation + Shipment Draft when runtime gates allow}';

    protected $description = 'Preview or apply bounded automatic warehouse allocation.';

    public function __construct(
        private readonly AutomaticAllocationService $allocation,
        private readonly WarehouseRoutingPolicy $routingPolicy,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && (bool) $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');
            return self::FAILURE;
        }
        if ($apply && (! (bool) config('inventory_auto_allocation.enabled', false)
            || (bool) config('inventory_auto_allocation.dry_run', true))) {
            $this->error('Apply blocked: automatic allocation is disabled or dry-run is enabled.');
            return self::FAILURE;
        }
        $limit = max(1, (int) ($this->option('limit') ?: config('inventory_auto_allocation.catch_up_limit', 100)));
        $query = Order::query()
            ->with(['address', 'items', 'shipment.warehouse', 'externalReferences'])
            ->orderBy('id');

        if ($orderId = $this->option('order')) {
            $query->whereKey((int) $orderId);
        } else {
            $allowlist = $this->routingPolicy->automaticCountries();

            $query->where('order_status', 'pending_shipment')
                ->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))
                ->whereHas('address', function ($q) use ($allowlist): void {
                    $q->whereIn('country', $allowlist);
                })
                ->limit($limit);
        }

        $orders = $query->get();
        $states = [];
        $warehouses = [];
        $mutated = 0;
        $rows = [];

        foreach ($orders as $order) {
            $result = $apply ? $this->allocation->allocate($order) : $this->allocation->preview($order);
            $state = (string) ($result['state'] ?? 'BLOCKED_OTHER');
            $warehouse = (string) ($result['warehouse']['code'] ?? '—');
            $states[$state] = ($states[$state] ?? 0) + 1;
            if ($warehouse !== '—') {
                $warehouses[$warehouse] = ($warehouses[$warehouse] ?? 0) + 1;
            }
            $mutated += (bool) ($result['mutated'] ?? false) ? 1 : 0;
            if (count($rows) < 25) {
                $rows[] = [
                    (int) $order->id,
                    (string) ($order->erp_order_id ?? $order->external_order_number),
                    (string) ($order->address?->country ?? '—'),
                    $state,
                    $warehouse,
                    (string) ($result['reason_label'] ?? ''),
                ];
            }
        }

        ksort($states);
        ksort($warehouses);
        $this->table(['Order', 'ERP Order', 'Country', 'State', 'Warehouse', 'Reason'], $rows);
        $this->line(json_encode([
            'mode' => $apply ? 'apply' : 'preview',
            'total' => $orders->count(),
            'mutated' => $mutated,
            'states' => $states,
            'warehouses' => $warehouses,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
