<?php

namespace App\Modules\Orders\Application;

enum OrderSourceType: string
{
    case Website = 'website';
    case Kol = 'kol';
    case ManualOther = 'manual_other';
    case Manual = 'manual';
    case WooCommerce = 'woocommerce';
}
