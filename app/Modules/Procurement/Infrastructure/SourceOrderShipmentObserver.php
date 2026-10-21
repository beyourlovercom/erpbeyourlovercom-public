<?php

namespace App\Modules\Procurement\Infrastructure;

use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;

final class SourceOrderShipmentObserver
{
    public function __construct(private readonly ProcurementOrderCompletionWriter $completion) {}

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('order_status')) return;

        $status = $order->order_status instanceof OrderStatus
            ? $order->order_status->value
            : (string) $order->order_status;

        if ($status === OrderStatus::Shipped->value) {
            $this->completion->completeForSourceOrder((int) $order->getKey());
        }
    }
}
