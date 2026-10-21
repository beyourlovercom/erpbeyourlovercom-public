<?php

namespace App\Modules\Dashboard\Application;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;

final class DashboardWorkspaceResolver
{
    /** @return array{role:string,label:string,mode:string,eyebrow:string,title:string,description:string} */
    public function resolve(?User $user): array
    {
        $preset = $this->preset($user);

        $meta = match ($preset) {
            RolePreset::Administrator => ['mode' => 'boss', 'eyebrow' => '老板 / 管理员总览', 'title' => '经营与运营总览', 'description' => '先看经营结果、异常和瓶颈，再进入对应业务系统处理。'],
            RolePreset::Operations => ['mode' => 'work', 'eyebrow' => '运营工作台', 'title' => '今日运营总览', 'description' => '聚合今天需要推进的履约、库存、同步与经营信号。'],
            RolePreset::Logistics => ['mode' => 'work', 'eyebrow' => '物流工作台', 'title' => '今日履约任务', 'description' => '优先处理待分仓、国内/海外发货、运输异常与复核任务。'],
            RolePreset::Finance => ['mode' => 'work', 'eyebrow' => '财务工作台', 'title' => '经营财务总览', 'description' => '聚焦收入、成本、利润覆盖与需要补齐的财务证据。'],
            RolePreset::KolSpecialist, RolePreset::KolManager => ['mode' => 'work', 'eyebrow' => '网红工作台', 'title' => '今日网红运营', 'description' => '聚合网红履约、审批、订单与待处理事项。'],
            RolePreset::SalesRepresentative => ['mode' => 'work', 'eyebrow' => '业务员工作台', 'title' => '今日客户与订单', 'description' => '聚合客户、订单和需要继续跟进的业务事项。'],
            RolePreset::Writer => ['mode' => 'work', 'eyebrow' => '内容工作台', 'title' => '今日内容任务', 'description' => '聚合内容与文档工作入口；业务数据仅按授权展示。'],
            default => ['mode' => 'work', 'eyebrow' => '我的工作台', 'title' => '今日工作总览', 'description' => '仅展示当前账号已授权的业务模块和入口。'],
        };

        return [
            'role' => $preset?->value ?? 'custom',
            'label' => $preset?->label() ?? '自定义权限',
        ] + $meta;
    }

    private function preset(?User $user): ?RolePreset
    {
        if ($user === null) {
            return null;
        }

        $roleKeys = $user->roles()->pluck('key')->all();

        foreach ($this->priority() as $preset) {
            if (in_array($preset->roleKey(), $roleKeys, true)) {
                return $preset;
            }
        }

        return null;
    }

    /** @return list<RolePreset> */
    private function priority(): array
    {
        return [
            RolePreset::Administrator,
            RolePreset::Operations,
            RolePreset::KolManager,
            RolePreset::Finance,
            RolePreset::Logistics,
            RolePreset::KolSpecialist,
            RolePreset::SalesRepresentative,
            RolePreset::Writer,
        ];
    }
}
