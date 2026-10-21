<?php

return [
    'logistics' => ['icon' => '🚚', 'label' => '物流', 'children' => [
        'wait-stock' => ['label' => '待分仓', 'path' => '/logistics/wait-stock'],
        'awaiting-tracking' => ['label' => '已发待单号', 'path' => '/logistics/awaiting-tracking'],
        'in-transit' => ['label' => '运输中', 'path' => '/logistics/in-transit'],
        'exceptions' => ['label' => '异常订单', 'path' => '/logistics/exceptions'],
    ]],
    'customers' => ['icon' => '👥', 'label' => '客户', 'children' => [
        'orders' => ['label' => '订单', 'path' => '/customers/orders'],
        'orders.create' => ['label' => '新建订单', 'path' => '/customers/orders/create'],
    ]],
    'kol' => ['icon' => '👥', 'label' => '网红', 'children' => [
        'orders' => ['label' => '订单', 'path' => '/kol/orders'],
        'orders.create' => ['label' => '新建订单', 'path' => '/kol/orders/create'],
        'orders.mine' => ['label' => '我的订单', 'path' => '/kol/orders/mine'],
        'orders.team' => ['label' => '团队订单', 'path' => '/kol/orders/team'],
    ]],
    'inventory' => ['icon' => '📦', 'label' => '库存', 'children' => [
        'overview' => ['label' => '库存总览', 'path' => '/inventory'],
        'list' => ['label' => '仓库清单', 'path' => '/inventory/list'],
        'products' => ['label' => '产品库存', 'path' => '/inventory/products'],
    ]],
    'procurement' => ['icon' => '📦', 'label' => '采购', 'children' => [
        'overview' => ['label' => '采购总览', 'path' => '/purchase'],
        'files' => ['label' => '采购文件', 'path' => '/purchase/files'],
        'records' => ['label' => '记录', 'path' => '/purchase/records'],
    ]],
    'products' => ['icon' => '🏷️', 'label' => '产品', 'children' => [
        'index' => ['label' => '产品索引', 'path' => '/products'],
        'skus' => ['label' => 'SKU', 'path' => '/products/skus'],
        'sku-aliases' => ['label' => 'SKU别名', 'path' => '/products/sku-aliases'],
    ]],
    'guides' => ['icon' => '📖', 'label' => '指南', 'children' => [
        'index' => ['label' => '全部教程', 'path' => '/guides'],
        'videos' => ['label' => '视频教程', 'path' => '/guides/videos'],
        'manuals' => ['label' => '操作手册', 'path' => '/guides/manuals'],
        'changelog' => ['label' => '版本更新', 'path' => '/settings/changelog'],
    ]],
    'accounting' => ['icon' => '💰', 'label' => '会计', 'children' => [
        'cost-calculation' => ['label' => '成本核算表', 'path' => '/accounting/cost-calculation'],
        'order-costs' => ['label' => '每一单成本表', 'path' => '/accounting/order-costs'],
        'expense-analysis' => ['label' => '费用分析表', 'group' => true, 'children' => [
            'actual-2025' => ['label' => '2025实际', 'path' => '/accounting/expense-analysis/2025-actual'],
            'execution-2026' => ['label' => '2026执行', 'path' => '/accounting/expense-analysis/2026-execution'],
            'forecast-2027' => ['label' => '2027预估', 'path' => '/accounting/expense-analysis/2027-forecast'],
            'monthly' => ['label' => '每月明细表', 'path' => '/accounting/expense-analysis/monthly'],
            'optimization' => ['label' => '可优化的部分', 'path' => '/accounting/expense-analysis/optimization'],
            'variance' => ['label' => '预估和实际的差别', 'path' => '/accounting/expense-analysis/variance'],
        ]],
        'kol-commissions' => ['label' => '网红佣金表', 'path' => '/accounting/kol-commissions'],
    ]],
    'settings' => ['icon' => '⚙️', 'label' => '设置', 'children' => [
        'statuses' => ['label' => '状态设置', 'path' => '/settings/statuses'],
        'warehouses' => ['label' => '仓库设置', 'path' => '/settings/warehouses'],
        'users' => ['label' => '用户', 'path' => '/settings/users'],
        'roles-permissions' => ['label' => '角色与权限', 'path' => '/settings/roles-permissions'],
        'audit-logs' => ['label' => '操作日志', 'path' => '/settings/audit-logs'],
        'changelog' => ['label' => '版本更新', 'path' => '/settings/changelog'],
    ]],
];
