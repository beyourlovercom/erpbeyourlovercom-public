<?php

namespace App\Modules\Orders\Application;

enum OrderStatus: string
{
    case PendingShipment = 'pending_shipment';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case CompletedDelivered = 'completed_delivered';
    case Cancelled = 'cancelled';
}
