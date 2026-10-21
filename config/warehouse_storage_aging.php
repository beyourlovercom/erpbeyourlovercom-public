<?php

return [
    'monthly_enabled' => filter_var(env('ERP_WAREHOUSE_STORAGE_AGING_MONTHLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'fifo_consumption_enabled' => filter_var(env('ERP_WAREHOUSE_STORAGE_FIFO_CONSUMPTION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'order_cost_enabled' => filter_var(env('ERP_ACCOUNTING_P9_WAREHOUSE_COST_ENABLED', false), FILTER_VALIDATE_BOOL),
    'canonical_from' => env('ERP_WAREHOUSE_STORAGE_AGING_CANONICAL_FROM'),
    'scheduler' => [
        'time' => env('ERP_WAREHOUSE_STORAGE_AGING_SCHEDULE_TIME', '02:20'),
        'timezone' => env('ERP_WAREHOUSE_STORAGE_AGING_TIMEZONE', config('app.timezone', 'UTC')),
    ],
];
