<?php

namespace App\Modules\Orders\Application;

enum PaymentAuthorityState: string
{
    case NotRequired = 'not_required';
    case Unconfirmed = 'unconfirmed';
    case Pending = 'pending';
    case Hold = 'hold';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Paid = 'paid';

    public function allowsFulfillment(): bool
    {
        return in_array($this, [self::NotRequired, self::Paid], true);
    }
}
