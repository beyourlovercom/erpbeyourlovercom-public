<?php

declare(strict_types=1);

// P7C_SYNTHETIC_FIXTURE: All records here are fictional; not production customer data.
// Review-only candidate; do not deploy, publish, or replace Private source automatically.
return [
    'orders' => [
        ['order_number' => 'P7C-DEMO-0001', 'created_at' => '2026-01-01 10:00', 'shipping_status' => '待发货', 'owner' => 'Demo Operator', 'customer' => 'Fictional Customer 01', 'country' => 'US', 'sku' => 'DEMO-SKU-01', 'quantity' => 1, 'warehouse' => 'DEMO-US', 'tracking_number' => 'FAKE-TRACK-0001'],
        ['order_number' => 'P7C-DEMO-0002', 'created_at' => '2026-01-02 10:00', 'shipping_status' => '已发待单号', 'owner' => 'Demo Operator', 'customer' => 'Fictional Customer 02', 'country' => 'US', 'sku' => 'DEMO-SKU-02', 'quantity' => 2, 'warehouse' => 'DEMO-US', 'tracking_number' => 'FAKE-TRACK-0002'],
        ['order_number' => 'P7C-DEMO-0003', 'created_at' => '2026-01-03 10:00', 'shipping_status' => '运输中', 'owner' => 'Demo Operator', 'customer' => 'Fictional Customer 03', 'country' => 'US', 'sku' => 'DEMO-SKU-03', 'quantity' => 3, 'warehouse' => 'DEMO-US', 'tracking_number' => 'FAKE-TRACK-0003'],
        ['order_number' => 'P7C-DEMO-0004', 'created_at' => '2026-01-04 10:00', 'shipping_status' => '已收货', 'owner' => 'Demo Operator', 'customer' => 'Fictional Customer 04', 'country' => 'US', 'sku' => 'DEMO-SKU-04', 'quantity' => 4, 'warehouse' => 'DEMO-US', 'tracking_number' => 'FAKE-TRACK-0004'],
        ['order_number' => 'P7C-DEMO-0005', 'created_at' => '2026-01-05 10:00', 'shipping_status' => '缺货', 'owner' => 'Demo Operator', 'customer' => 'Fictional Customer 05', 'country' => 'US', 'sku' => 'DEMO-SKU-05', 'quantity' => 5, 'warehouse' => 'DEMO-US', 'tracking_number' => 'FAKE-TRACK-0005'],
        ['order_number' => 'P7C-DEMO-0006', 'created_at' => '2026-01-06 10:00', 'shipping_status' => '信息缺失', 'owner' => 'Demo Operator', 'customer' => 'Fictional Customer 06', 'country' => 'US', 'sku' => 'DEMO-SKU-06', 'quantity' => 6, 'warehouse' => 'DEMO-US', 'tracking_number' => 'FAKE-TRACK-0006'],
        ['order_number' => 'P7C-DEMO-0007', 'created_at' => '2026-01-07 10:00', 'shipping_status' => '补发', 'owner' => 'Demo Operator', 'customer' => 'Fictional Customer 07', 'country' => 'US', 'sku' => 'DEMO-SKU-07', 'quantity' => 7, 'warehouse' => 'DEMO-US', 'tracking_number' => 'FAKE-TRACK-0007'],
        ['order_number' => 'P7C-DEMO-0008', 'created_at' => '2026-01-08 10:00', 'shipping_status' => '无需发货', 'owner' => 'Demo Operator', 'customer' => 'Fictional Customer 08', 'country' => 'US', 'sku' => 'DEMO-SKU-08', 'quantity' => 8, 'warehouse' => 'DEMO-US', 'tracking_number' => 'FAKE-TRACK-0008'],
    ],
    'detail' => [
        'order_number' => 'P7C-DEMO-0001',
        'shipping_status' => '待发货',
        'warehouse' => 'DEMO-US',
        'owner' => 'Demo Operator',
        'customer' => ['name' => 'Alex Example', 'phone' => '+1 202-555-0101', 'email' => 'demo@example.invalid', 'country' => 'US', 'state' => 'CA', 'city' => 'Sample City', 'address' => '100 Example Avenue', 'postcode' => '00000', 'website' => 'https://example.invalid', 'website_order' => 'P7C-DEMO-0001', 'created_at' => '2026-01-01 10:00', 'synced_at' => '2026-01-01 10:30', 'product' => 'Sample Product 01', 'quantity' => 1],
        'structured_address' => ['Full name' => 'Alex Example', 'Address Street' => '100 Example Avenue', 'City' => 'Sample City', 'State' => 'CA', 'Zipcode' => '00000', 'Phone' => '+1 202-555-0101', 'Contact Email' => 'demo@example.invalid', 'Country' => 'US', 'Notes' => 'FICTIONAL TEST RECORD ONLY'],
        'carrier' => 'Demo Carrier',
        'tracking_number' => 'FAKE-TRACK-0001',
        'quantity' => 1,
        'timeline' => [
            ['actor' => 'Demo Operator', 'action' => 'Created synthetic order', 'time' => '2026-01-01 10:00'],
            ['actor' => 'Demo Warehouse', 'action' => 'Assigned synthetic stock', 'time' => '2026-01-01 11:00'],
            ['actor' => 'Demo Operator', 'action' => 'Confirmed synthetic address', 'time' => '2026-01-01 12:00'],
            ['actor' => 'Demo Logistics', 'action' => 'Prepared synthetic parcel', 'time' => '2026-01-01 13:00'],
            ['actor' => 'Demo Logistics', 'action' => 'Created fake tracking', 'time' => '2026-01-01 14:00'],
            ['actor' => 'Demo Operator', 'action' => 'Confirmed synthetic export', 'time' => '2026-01-01 15:00'],
            ['actor' => 'Demo System', 'action' => 'Completed fictional timeline', 'time' => '2026-01-01 16:00'],
        ],
    ],
];