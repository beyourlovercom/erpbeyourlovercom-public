<?php

return [
    'admin_bypass' => [
        'enabled' => env('ERP_LOCAL_ADMIN_BYPASS', false) === true,
        'email' => (string) env('ERP_LOCAL_ADMIN_EMAIL', 'test@example.com'),
    ],
];
