<?php

namespace App\Modules\Identity\Application;

final class PermissionCatalog
{
    /** @return array<string, array{label: string, permissions: list<array{key: string, label: string}>}> */
    public static function groups(): array
    {
        return [
            '通用' => [
                'label' => '通用',
                'permissions' => [
                    ['key' => 'dashboard.view', 'label' => '查看仪表盘'],
                ],
            ],
            '订单' => [
                'label' => '订单',
                'permissions' => [
                    ['key' => 'orders.view', 'label' => '查看订单'],
                    ['key' => 'orders.create', 'label' => '创建订单'],
                    ['key' => 'orders.edit', 'label' => '编辑订单'],
                ],
            ],
            '客户' => [
                'label' => '客户',
                'permissions' => [
                    ['key' => 'customers.view', 'label' => '查看客户'],
                    ['key' => 'customers.operate', 'label' => '操作客户'],
                    ['key' => 'customers.import', 'label' => '导入客户（保留）'],
                    ['key' => 'customers.export', 'label' => '导出客户（保留）'],
                ],
            ],
            '物流' => [
                'label' => '物流',
                'permissions' => [
                    ['key' => 'logistics.view', 'label' => '查看物流'],
                    ['key' => 'logistics.operate', 'label' => '操作物流'],
                ],
            ],
            '网红' => [
                'label' => '网红',
                'permissions' => [
                    ['key' => 'kol.view', 'label' => '查看网红'],
                    ['key' => 'kol.operate', 'label' => '操作网红'],
                    ['key' => 'kol.views.manage', 'label' => '管理网红视图'],
                    ['key' => 'kol.approvals.manage', 'label' => '管理网红审批'],
                    ['key' => 'kol.affiliates.manage', 'label' => '管理 AffiliateWP 网红'],
                    ['key' => 'kol.payouts.request', 'label' => '发起网红结算'],
                    ['key' => 'kol.payouts.approve', 'label' => '审批网红结算'],
                    ['key' => 'kol.payouts.manage', 'label' => '执行网红付款并同步 Provider'],
                ],
            ],
            '库存' => [
                'label' => '库存',
                'permissions' => [
                    ['key' => 'inventory.view', 'label' => '查看库存'],
                    ['key' => 'inventory.operate', 'label' => '编辑库存'],
                    ['key' => 'inventory.warehouses.manage', 'label' => '管理仓库'],
                    ['key' => 'inventory.packaging.submit', 'label' => '提交包装与测量证据'],
                    ['key' => 'inventory.packaging.review', 'label' => '审核包装与测量证据'],
                ],
            ],
            '采购' => [
                'label' => '采购',
                'permissions' => [
                    ['key' => 'procurement.view', 'label' => '查看采购'],
                    ['key' => 'procurement.create', 'label' => '创建采购订单'],
                    ['key' => 'procurement.operate', 'label' => '执行采购订单'],
                ],
            ],
            '产品' => [
                'label' => '产品',
                'permissions' => [
                    ['key' => 'products.view', 'label' => '查看产品'],
                ],
            ],
            '指南' => [
                'label' => '指南',
                'permissions' => [
                    ['key' => 'guides.view', 'label' => '查看指南'],
                    ['key' => 'guides.manage', 'label' => '管理指南内容'],
                ],
            ],
            '财务' => [
                'label' => '财务',
                'permissions' => [
                    ['key' => 'accounting.view', 'label' => '查看财务'],
                    ['key' => 'analysis.view', 'label' => '查看分析报表'],
                ],
            ],
            '同步' => [
                'label' => '同步',
                'permissions' => [
                    ['key' => 'sync.view', 'label' => '查看同步'],
                    ['key' => 'sync.operate', 'label' => '操作同步'],
                ],
            ],
            '营销' => [
                'label' => '营销',
                'permissions' => [
                    ['key' => 'marketing.view', 'label' => '查看营销'],
                    ['key' => 'marketing.operate', 'label' => '操作营销'],
                ],
            ],
            '系统' => [
                'label' => '系统',
                'permissions' => [
                    ['key' => 'system.permissions.manage', 'label' => '管理系统权限'],
                    ['key' => 'audit.view', 'label' => '查看审计日志'],
                    ['key' => 'backup.view', 'label' => '查看备份与迁移'],
                    ['key' => 'backup.create', 'label' => '创建备份'],
                    ['key' => 'backup.download', 'label' => '下载备份'],
                    ['key' => 'backup.publish_github', 'label' => '发布备份到 GitHub'],
                    ['key' => 'backup.delete', 'label' => '删除备份文件'],
                    ['key' => 'backup.restore', 'label' => '执行恢复'],
                    ['key' => 'backup.manage', 'label' => '管理备份策略'],
                ],
            ],
            '兼容' => [
                'label' => '兼容',
                'permissions' => [
                    ['key' => 'prototype.view', 'label' => '原型预览（兼容）'],
                    ['key' => 'architecture.foundation.view', 'label' => '架构基础（内部）'],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::groups() as $group) {
            foreach ($group['permissions'] as $perm) {
                $keys[] = $perm['key'];
            }
        }

        return $keys;
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::allKeys(), true);
    }

    /** @param list<string> $keys */
    public static function validate(array $keys): bool
    {
        foreach ($keys as $key) {
            if (! self::isValid($key)) {
                return false;
            }
        }

        return true;
    }
}
