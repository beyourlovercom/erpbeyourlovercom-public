<?php

return [
    'continuous_delta' => [
        'enabled' => filter_var(env('ERP_ACCOUNTING_CONTINUOUS_DELTA_ENABLED', false), FILTER_VALIDATE_BOOL),
        'kol_commission_enabled' => filter_var(env('ERP_ACCOUNTING_CONTINUOUS_DELTA_KOL_COMMISSION_ENABLED', false), FILTER_VALIDATE_BOOL),
        'p82_estimated_fallback_enabled' => filter_var(env('ERP_ACCOUNTING_P82_ESTIMATED_FALLBACK_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    'multi_shipment' => [
        'enabled' => filter_var(env('ERP_ACCOUNTING_MULTI_SHIPMENT_AGGREGATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    'warehouse_bill_reconciliation' => [
        'continuous_delta_enabled' => filter_var(env('ERP_WAREHOUSE_BILL_ACCOUNTING_DELTA_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    'financial_bootstrap' => [
        'production_apply_enabled' => (bool) env('ERP_ACCOUNTING_FINANCIAL_BOOTSTRAP_PRODUCTION_APPLY_ENABLED', false),
    ],
];
