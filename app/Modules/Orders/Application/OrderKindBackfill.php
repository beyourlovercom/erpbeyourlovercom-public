<?php

namespace App\Modules\Orders\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic legacy source_type -> order_kind classification.
 *
 * `website` and `kol` source rows were always unambiguously a real customer
 * purchase or a KOL fulfillment send respectively. `manual_other` cannot be
 * classified without guessing and is left order_kind=NULL as an explicit
 * migration exception rather than silently defaulted.
 */
final class OrderKindBackfill
{
    /**
     * @return array{customer: int, kol_fulfillment: int, unclassified: array<int, int>}
     */
    public static function apply(): array
    {
        $customer = DB::table('orders')->where('source_type', OrderSourceType::Website->value)->whereNull('order_kind')
            ->update(['order_kind' => OrderKind::Customer->value]);
        $kolFulfillment = DB::table('orders')->where('source_type', OrderSourceType::Kol->value)->whereNull('order_kind')
            ->update(['order_kind' => OrderKind::KolFulfillment->value]);

        $unclassified = DB::table('orders')
            ->where('source_type', OrderSourceType::ManualOther->value)
            ->whereNull('order_kind')
            ->pluck('id')
            ->all();

        if ($unclassified !== []) {
            Log::warning('orders.order_kind.migration_exception', [
                'reason' => 'source_type=manual_other cannot be deterministically classified into order_kind',
                'order_ids' => $unclassified,
            ]);
        }

        return ['customer' => $customer, 'kol_fulfillment' => $kolFulfillment, 'unclassified' => $unclassified];
    }
}
