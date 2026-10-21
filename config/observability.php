<?php

return [
    'request_id_header' => 'X-ERP-Request-ID',
    'expose_diagnostics' => (bool) env('ERP_OBSERVABILITY_EXPOSE_DIAGNOSTICS', false),
    'query_budget' => (int) env('ERP_OBSERVABILITY_QUERY_BUDGET', 100),
    'query_time_budget_ms' => (float) env('ERP_OBSERVABILITY_QUERY_TIME_BUDGET_MS', 500),
    'duration_budget_ms' => (float) env('ERP_OBSERVABILITY_DURATION_BUDGET_MS', 2000),
    'revision' => env('APP_REVISION', env('DEPLOY_REVISION', env('GIT_SHA'))),
];
