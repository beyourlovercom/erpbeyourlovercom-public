<?php

return [
    'enabled' => filter_var(env('INVENTORY_AUTO_ALLOCATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'dry_run' => filter_var(env('INVENTORY_AUTO_ALLOCATION_DRY_RUN', true), FILTER_VALIDATE_BOOL),
    'scheduler_enabled' => filter_var(env('INVENTORY_AUTO_ALLOCATION_SCHEDULER_ENABLED', false), FILTER_VALIDATE_BOOL),
    'catch_up_limit' => max(1, (int) env('INVENTORY_AUTO_ALLOCATION_CATCH_UP_LIMIT', 100)),
];
