<?php

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\Contracts\WooFinancialBootstrapOrderStore;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class EloquentWooFinancialBootstrapOrderStore implements WooFinancialBootstrapOrderStore
{
    public function findExactOperational(
        int $orderId,
        string $provider,
        string $connectionKey,
        string $reference,
        bool $lock = false,
    ): ?Model {
        $query = Order::query()
            ->whereKey($orderId)
            ->where('order_kind', OrderKind::Customer->value)
            ->whereHas('externalReferences', fn (Builder $refs) => $refs
                ->where('provider', $provider)
                ->where('connection_key', $connectionKey)
                ->where('reference', $reference))
            ->whereDoesntHave('externalReferences', fn (Builder $refs) => $refs
                ->where('provider', 'historical_logistics'));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
