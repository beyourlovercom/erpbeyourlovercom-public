<?php

namespace App\Modules\Audit\Application;

enum AuditSource: string
{
    case Fortify = 'fortify';
    case InternalSystem = 'system';
    case UserProvisioning = 'erp:user:create';
    case SyncOutbox = 'sync:outbox';
    case Web = 'web';
    case WooCommerceInbound = 'woocommerce:inbound';
}
