<?php

return [
    'historical_import_commit_enabled' => filter_var(env('LOGISTICS_HISTORICAL_IMPORT_COMMIT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'historical_order_materialization_apply_enabled' => filter_var(env('LOGISTICS_HISTORICAL_ORDER_MATERIALIZATION_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'historical_shipment_attempt_apply_enabled' => filter_var(env('LOGISTICS_HISTORICAL_SHIPMENT_ATTEMPT_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'historical_order_date_correction_apply_enabled' => filter_var(env('LOGISTICS_HISTORICAL_ORDER_DATE_CORRECTION_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'historical_review_rescue_commit_enabled' => filter_var(env('LOGISTICS_HISTORICAL_REVIEW_RESCUE_COMMIT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'historical_rescue_order_apply_enabled' => filter_var(env('LOGISTICS_HISTORICAL_RESCUE_ORDER_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'historical_rescue_shipment_attempt_apply_enabled' => filter_var(env('LOGISTICS_HISTORICAL_RESCUE_SHIPMENT_ATTEMPT_APPLY_ENABLED', false), FILTER_VALIDATE_BOOL),
];
