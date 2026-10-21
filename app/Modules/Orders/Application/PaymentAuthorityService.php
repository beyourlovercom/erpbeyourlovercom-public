<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

final class PaymentAuthorityService
{
    public function state(Order $order): PaymentAuthorityState
    {
        if ($this->isHistorical($order) || ! $this->isWooCustomerOrder($order)) {
            return PaymentAuthorityState::NotRequired;
        }

        // Always query current settlement evidence instead of trusting a possibly
        // stale/null-loaded relationship on a long-lived Order instance.
        $financials = $order->financials()->first();
        if ($financials === null) {
            return PaymentAuthorityState::Unconfirmed;
        }

        $status = strtolower(trim((string) $financials->provider_order_status));

        $blocked = match ($status) {
            'pending' => PaymentAuthorityState::Pending,
            'on-hold' => PaymentAuthorityState::Hold,
            'failed' => PaymentAuthorityState::Failed,
            'cancelled', 'canceled', 'trash' => PaymentAuthorityState::Cancelled,
            'refunded' => PaymentAuthorityState::Refunded,
            default => null,
        };
        if ($blocked !== null) {
            return $blocked;
        }

        if ($status === '' || $financials->paid_at === null) {
            return PaymentAuthorityState::Unconfirmed;
        }

        return PaymentAuthorityState::Paid;
    }

    /** @param iterable<Order> $orders @return array<int,PaymentAuthorityState> */
    public function statesFor(iterable $orders): array
    {
        $ids = collect($orders)->map(fn (Order $order): int => (int) $order->id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $fresh = Order::query()
            ->with(['externalReferences', 'financials'])
            ->whereKey($ids->all())
            ->get()
            ->keyBy('id');

        return $ids->mapWithKeys(function (int $id) use ($fresh): array {
            /** @var Order|null $order */
            $order = $fresh->get($id);
            if (! $order) {
                return [];
            }

            return [$id => $this->stateFromFreshSnapshot($order)];
        })->all();
    }

    private function stateFromFreshSnapshot(Order $order): PaymentAuthorityState
    {
        $references = $order->externalReferences;
        if ($references->contains('provider', 'historical_logistics')) {
            return PaymentAuthorityState::NotRequired;
        }

        $kind = $order->order_kind instanceof \BackedEnum
            ? $order->order_kind->value
            : (string) $order->order_kind;
        if ($kind !== OrderKind::Customer->value) {
            return PaymentAuthorityState::NotRequired;
        }

        $source = $order->source_type instanceof \BackedEnum
            ? $order->source_type->value
            : (string) $order->source_type;
        if ($source !== OrderSourceType::WooCommerce->value
            && ! $references->contains('provider', 'woocommerce')) {
            return PaymentAuthorityState::NotRequired;
        }

        $financials = $order->financials;
        if ($financials === null) {
            return PaymentAuthorityState::Unconfirmed;
        }

        $status = strtolower(trim((string) $financials->provider_order_status));
        $blocked = match ($status) {
            'pending' => PaymentAuthorityState::Pending,
            'on-hold' => PaymentAuthorityState::Hold,
            'failed' => PaymentAuthorityState::Failed,
            'cancelled', 'canceled', 'trash' => PaymentAuthorityState::Cancelled,
            'refunded' => PaymentAuthorityState::Refunded,
            default => null,
        };
        if ($blocked !== null) {
            return $blocked;
        }

        if ($status === '' || $financials->paid_at === null) {
            return PaymentAuthorityState::Unconfirmed;
        }

        return PaymentAuthorityState::Paid;
    }

    private function isHistorical(Order $order): bool
    {
        return $order->externalReferences()
            ->where('provider', 'historical_logistics')
            ->exists();
    }

    private function isWooCustomerOrder(Order $order): bool
    {
        $kind = $order->order_kind instanceof \BackedEnum
            ? $order->order_kind->value
            : (string) $order->order_kind;
        if ($kind !== OrderKind::Customer->value) {
            return false;
        }

        $source = $order->source_type instanceof \BackedEnum
            ? $order->source_type->value
            : (string) $order->source_type;

        return $source === OrderSourceType::WooCommerce->value
            || $order->externalReferences()
                ->where('provider', 'woocommerce')
                ->exists();
    }
}
