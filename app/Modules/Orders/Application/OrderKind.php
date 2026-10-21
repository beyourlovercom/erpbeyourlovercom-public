<?php

namespace App\Modules\Orders\Application;

enum OrderKind: string
{
    case Customer = 'customer';
    case KolFulfillment = 'kol_fulfillment';
    case FulfillmentDerived = 'fulfillment_derived';
}
