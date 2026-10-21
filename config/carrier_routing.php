<?php

$deFulfillmentCountries = ['DE', 'CZ', 'PL', 'SK', 'HU', 'SI', 'AT', 'NL', 'BE', 'FR', 'ES', 'IT', 'PT', 'GR', 'EE'];

$countries = [
    'CA' => [
        'automatic' => true,
        'strategy' => 'fixed_by_warehouse',
        'warehouses' => [
            'CA' => ['carrier' => 'Canada Post', 'service' => null],
        ],
        'manual_overrides' => [],
        'auto_rank_carriers' => false,
        'compare_price' => false,
        'compare_transit_time' => false,
    ],
    'US' => [
        'automatic' => true,
        'strategy' => 'fixed_by_warehouse',
        'warehouses' => [
            'US-LA' => ['carrier' => 'USPS', 'service' => null],
            'US-NY' => ['carrier' => 'USPS', 'service' => null],
            'US-SM' => ['carrier' => 'USPS', 'service' => null],
        ],
        'manual_overrides' => ['DHL'],
        'auto_rank_carriers' => false,
        'compare_price' => false,
        'compare_transit_time' => false,
    ],
];

foreach ($deFulfillmentCountries as $country) {
    $countries[$country] = [
        'automatic' => true,
        'strategy' => 'fixed_by_warehouse',
        'warehouses' => [
            'DE' => ['carrier' => 'DHL', 'service' => null],
        ],
        'manual_overrides' => [],
        'auto_rank_carriers' => false,
        'compare_price' => false,
        'compare_transit_time' => false,
    ];
}

return [
    // Carrier routing business policy single source of truth.
    // Canonical values stay stable in Shipment/Carrier Master; UI labels may be bilingual.
    'version' => '2026-09-20-v7',
    'labels' => [
        '4PX' => '4PX / 速达',
        'POSTNL international' => 'POSTNL / 佰事达',
        'Weship' => 'Weship / 纬狮',
        'KingTrans' => 'KingTrans / 唐歌',
        'DHL' => 'DHL',
        'Canada Post' => 'Canada Post / 加拿大邮政',
        'SF Express' => 'SF Express / 顺丰速运',
        'Singapore Post' => 'Singapore Post / 新加坡邮政',
        'JD' => 'JD / 京东快递',
        'USPS' => 'USPS / 美国邮政',
        'FedEx' => 'FedEx / 联邦',
        'HX EXPRESS' => 'HX EXPRESS / 浩辛',
        'Royal Mail' => 'Royal Mail / 英国皇家',
    ],
    'warehouse_defaults' => [
        'US-LA' => ['carrier' => 'USPS', 'service' => null],
        'US-NY' => ['carrier' => 'USPS', 'service' => null],
        'US-SM' => ['carrier' => 'USPS', 'service' => null],
        'DE' => ['carrier' => 'DHL', 'service' => null],
        'CA' => ['carrier' => 'Canada Post', 'service' => null],
    ],
    'factory_fallback' => [
        'warehouse_code' => 'FACTORY',
        'carrier' => '4PX',
        'service' => 'SD经济挂号-带电',
        'automatic_execution' => false,
        'rate_source' => [
            'file' => '仓库-物流-国内小包费用.xlsx',
            'sheet' => 'SD经济挂号-带电',
        ],
    ],
    'factory_country_overrides' => [
        'MX' => ['carrier' => 'Weship', 'service' => null, 'rate_source' => null, 'reason' => 'Owner-frozen: 墨西哥由纬狮发货'],
        'CL' => ['carrier' => 'Weship', 'service' => null, 'rate_source' => null, 'reason' => 'Owner-frozen: 智利由纬狮发货'],
        'AR' => ['carrier' => 'POSTNL international', 'service' => null, 'rate_source' => null, 'reason' => 'Owner-frozen: 阿根廷由佰事达发货'],
        'UA' => ['carrier' => 'POSTNL international', 'service' => null, 'rate_source' => null, 'reason' => 'Owner-frozen: 乌克兰由佰事达发货'],
        'RU' => ['carrier' => 'POSTNL international', 'service' => null, 'rate_source' => null, 'reason' => 'Owner-frozen: 俄罗斯由佰事达发货'],
        'TH' => ['carrier' => 'KingTrans', 'service' => null, 'rate_source' => null, 'reason' => 'Owner-frozen: 泰国由唐歌发货'],
        'ID' => ['carrier' => 'KingTrans', 'service' => null, 'rate_source' => null, 'reason' => 'Owner-frozen: 印尼/印度尼西亚由唐歌发货'],
        'VN' => ['carrier' => 'KingTrans', 'service' => null, 'rate_source' => null, 'reason' => 'Owner-frozen: 越南由唐歌发货'],
    ],
    'countries' => $countries,
];
