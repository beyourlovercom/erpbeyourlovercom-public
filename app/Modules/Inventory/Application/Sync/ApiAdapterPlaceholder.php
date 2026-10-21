<?php

namespace App\Modules\Inventory\Application\Sync;

/**
 * API connector placeholder for M1. No live connector or scheduler in M1.
 */
final class ApiAdapterPlaceholder
{
    public function isAvailable(): bool { return false; }

    public function statusLabel(): string { return '尚未配置'; }
}
