<?php

return [
    'version' => 'china-factory-sd-economy-registered-battery-v2',
    'source' => [
        'file' => '仓库-物流-国内小包费用.xlsx',
        'sheet' => 'SD经济挂号-带电',
        'effective_date' => null,
    ],
    'formula' => 'actual_weight_kg, rounded_up_to_gram',
    'billing_basis' => 'actual_weight_only',
    'billing_basis_source' => 'owner_operational_rule_2026-09-08',
    'countries' => [
        'US' => ['minimum_billing_kg' => 0.05, 'bands' => [
            ['max_kg' => 0.10, 'per_kg_rmb' => 131, 'base_rmb' => 25], ['max_kg' => 0.20, 'per_kg_rmb' => 131, 'base_rmb' => 23],
            ['max_kg' => 0.45, 'per_kg_rmb' => 131, 'base_rmb' => 21], ['max_kg' => 0.70, 'per_kg_rmb' => 131, 'base_rmb' => 21],
            ['max_kg' => 1.00, 'per_kg_rmb' => 131, 'base_rmb' => 14], ['max_kg' => 3.00, 'per_kg_rmb' => 131, 'base_rmb' => 14],
            ['max_kg' => 6.00, 'per_kg_rmb' => 131, 'base_rmb' => 14], ['max_kg' => 30.00, 'per_kg_rmb' => 131, 'base_rmb' => 14],
        ]],
        'CA' => ['minimum_billing_kg' => 0.05, 'bands' => [
            ['max_kg' => 0.15, 'per_kg_rmb' => 104, 'base_rmb' => 26], ['max_kg' => 0.30, 'per_kg_rmb' => 104, 'base_rmb' => 26],
            ['max_kg' => 0.45, 'per_kg_rmb' => 104, 'base_rmb' => 26], ['max_kg' => 0.75, 'per_kg_rmb' => 113, 'base_rmb' => 27],
            ['max_kg' => 1.00, 'per_kg_rmb' => 113, 'base_rmb' => 27], ['max_kg' => 1.50, 'per_kg_rmb' => 113, 'base_rmb' => 28],
            ['max_kg' => 2.00, 'per_kg_rmb' => 120, 'base_rmb' => 28], ['max_kg' => 30.00, 'per_kg_rmb' => 120, 'base_rmb' => 28],
        ]],
        'MX' => ['bands' => [
            ['max_kg' => 0.25, 'per_kg_rmb' => 102, 'base_rmb' => 23], ['max_kg' => 0.50, 'per_kg_rmb' => 102, 'base_rmb' => 23],
            ['max_kg' => 1.00, 'per_kg_rmb' => 102, 'base_rmb' => 23], ['max_kg' => 1.50, 'per_kg_rmb' => 109, 'base_rmb' => 25],
            ['max_kg' => 2.00, 'per_kg_rmb' => 109, 'base_rmb' => 25], ['max_kg' => 10.00, 'per_kg_rmb' => 109, 'base_rmb' => 25],
        ]],
        'GB' => ['bands' => [['max_kg' => 2.00, 'per_kg_rmb' => 84, 'base_rmb' => 21], ['max_kg' => 15.00, 'per_kg_rmb' => 81, 'base_rmb' => 21]]],
        'DE' => ['bands' => [
            ['max_kg' => 0.10, 'per_kg_rmb' => 115, 'base_rmb' => 27], ['max_kg' => 0.30, 'per_kg_rmb' => 115, 'base_rmb' => 26],
            ['max_kg' => 2.00, 'per_kg_rmb' => 115, 'base_rmb' => 27], ['max_kg' => 3.00, 'per_kg_rmb' => 115, 'base_rmb' => 27],
            ['max_kg' => 30.00, 'per_kg_rmb' => 120, 'base_rmb' => 27],
        ]],
        'FR' => ['bands' => [['max_kg' => 0.40, 'per_kg_rmb' => 117, 'base_rmb' => 24], ['max_kg' => 30.00, 'per_kg_rmb' => 117, 'base_rmb' => 28]]],
        'IE' => ['bands' => [['max_kg' => 20.00, 'per_kg_rmb' => 125, 'base_rmb' => 28]]],
        'IL' => ['bands' => [['max_kg' => 1.00, 'per_kg_rmb' => 159, 'base_rmb' => 26], ['max_kg' => 2.00, 'per_kg_rmb' => 166, 'base_rmb' => 26], ['max_kg' => 8.00, 'per_kg_rmb' => 180, 'base_rmb' => 26]]],
    ],
    'eu27_item_surcharge_eur' => 3.00,
    'eu27_countries' => ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'],
    'us_excluded_zip_prefixes' => ['006', '007', '008', '009', '967', '968', '969', '995', '996', '997', '998', '999'],
];
