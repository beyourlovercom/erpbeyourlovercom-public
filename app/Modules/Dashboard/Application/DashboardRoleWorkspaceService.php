<?php

namespace App\Modules\Dashboard\Application;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Guides\Infrastructure\Models\GuideArticle;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\KolOperationalDashboardService;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Kol\Infrastructure\Models\KolContent;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;

final class DashboardRoleWorkspaceService
{
    public function __construct(private readonly KolOperationalDashboardService $kolDashboard) {}

    /** @return array<string,mixed>|null */
    public function snapshot(string $role, User $user): ?array
    {
        return match ($role) {
            'kol_specialist', 'kol_manager' => $user->can('kol.view') ? $this->kol($user) : null,
            'sales_representative' => $user->can('customers.view') ? $this->sales($user) : null,
            'writer' => $user->can('guides.view') ? $this->writer($user) : null,
            'custom' => $this->custom($user),
            default => null,
        };
    }

    /** @return array<string,mixed>|null */
    private function custom(User $user): ?array
    {
        if ($user->can('kol.view')) {
            return $this->kol($user);
        }
        if ($user->can('customers.view')) {
            return $this->sales($user);
        }
        if ($user->can('guides.view')) {
            return $this->writer($user);
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function kol(User $user): array
    {
        $operational = $this->kolDashboard->metrics($user, ['period' => 'week']);
        $kols = Kol::query()->whereNull('archived_at');
        if (! KolScope::isAllScope($user)) {
            $kols->where('owner_id', (int) $user->getAuthIdentifier());
        }
        $kolIds = (clone $kols)->pluck('id');

        $collaborations = KolCollaboration::query()->whereIn('kol_id', $kolIds);
        $activeCollaborations = (clone $collaborations)->whereIn('status', ['confirmed', 'in_progress'])->count();
        $awaitingFulfillmentOrder = (clone $collaborations)
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->whereIn('approval_status', ['approved', 'not_required'])
            ->where('fulfillment_required', true)
            ->whereNull('order_id')
            ->count();
        $pendingShipment = (clone $collaborations)
            ->whereHas('order', fn ($query) => $query->where('order_status', OrderStatus::PendingShipment->value))
            ->count();
        $pendingContent = KolContent::query()
            ->whereIn('kol_id', $kolIds)
            ->where(fn ($query) => $query->whereNull('publication_status')->orWhere('publication_status', '!=', 'published'))
            ->count();
        $eligibleCommission = array_sum(array_map(
            fn (array $row): int => (int) ($row['referral_count'] ?? 0),
            $operational['eligible_commission'] ?? [],
        ));

        $isAllScope = KolScope::isAllScope($user);
        $orderQueueUrl = $isAllScope ? '/kol/orders/team' : '/kol/orders/mine';

        $cards = [
            ['label' => $isAllScope ? '团队网红' : '我的网红', 'value' => (clone $kols)->count(), 'href' => '/kol/library', 'helper' => '当前未归档'],
            ['label' => '进行中合作', 'value' => $activeCollaborations, 'href' => '/kol/collaborations', 'helper' => '已确认 + 进行中'],
            ['label' => '待建立发货单', 'value' => $awaitingFulfillmentOrder, 'href' => '/kol/collaborations', 'helper' => '已批准且需要履约'],
            ['label' => '待发货网红订单', 'value' => $pendingShipment, 'href' => $orderQueueUrl, 'helper' => '关联订单待发货'],
            ['label' => '待发布内容', 'value' => $pendingContent, 'href' => '/kol/content', 'helper' => '尚未标记已发布'],
            ['label' => '可申请佣金', 'value' => $eligibleCommission, 'href' => '/kol/commissions', 'helper' => 'AffiliateWP 可申请 referral'],
        ];

        if (is_array($operational['manager_tasks'] ?? null)) {
            $cards[] = ['label' => '待审批网红', 'value' => (int) ($operational['manager_tasks']['kols'] ?? 0), 'href' => '/kol/approvals/kols', 'helper' => '经理审批队列'];
            $cards[] = ['label' => '待审批合作', 'value' => (int) ($operational['manager_tasks']['collaborations'] ?? 0), 'href' => '/kol/approvals/collaborations', 'helper' => '经理审批队列'];
            $cards[] = ['label' => '待审批佣金', 'value' => (int) ($operational['manager_tasks']['payouts'] ?? 0), 'href' => '/kol/approvals/payouts', 'helper' => '经理审批队列'];
        }

        return [
            'kind' => 'kol',
            'title' => '今日网红待办',
            'description' => $isAllScope ? '团队范围 · 从网红、合作、履约、内容和佣金进入处理。' : '仅统计分配给我的网红与合作，直接进入对应队列处理。',
            'cards' => $cards,
            'highlights' => [
                '本周新增网红 '.(int) ($operational['new_kols'] ?? 0),
                '本周合作 '.(int) ($operational['collaboration_records'] ?? 0),
                '本周发布 '.(int) ($operational['publication_records'] ?? 0),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function sales(User $user): array
    {
        $today = CarbonImmutable::today();
        $start = $today->subDays(6);
        $customers = Customer::query()->whereNull('archived_at')->where('owner_id', $user->getAuthIdentifier());
        $orders = Order::query()->whereNull('fulfillment_parent_id')->where('owner_id', $user->getAuthIdentifier());

        return [
            'kind' => 'sales',
            'title' => '我的客户与订单',
            'description' => '当前系统尚没有独立“跟进任务”字段，因此这里只展示可从现有客户与订单证据准确计算的工作量。',
            'cards' => [
                ['label' => '我的客户', 'value' => (clone $customers)->count(), 'href' => '/customers/library', 'helper' => '当前未归档'],
                ['label' => '近 7 日新增客户', 'value' => (clone $customers)->where('created_at', '>=', $start->startOfDay())->count(), 'href' => '/customers/library', 'helper' => '按客户创建时间'],
                ['label' => '待发货订单', 'value' => (clone $orders)->where('order_status', OrderStatus::PendingShipment->value)->count(), 'href' => '/customers/orders', 'helper' => '我负责的客户订单'],
                ['label' => '已发货进行中', 'value' => (clone $orders)->where('order_status', OrderStatus::Shipped->value)->count(), 'href' => '/customers/orders', 'helper' => '尚未完结'],
            ],
            'highlights' => [
                '近 7 日订单 '.(clone $orders)->whereDate('order_date', '>=', $start->toDateString())->count(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function writer(User $user): array
    {
        $start = CarbonImmutable::today()->subDays(6)->startOfDay();
        $mine = GuideArticle::query()->where('created_by', $user->getAuthIdentifier());

        return [
            'kind' => 'writer',
            'title' => '我的内容任务',
            'description' => '当前读取 Guides/知识库的真实内容状态；Content Production V1 接入后可继续扩展写作、审核与发布队列。',
            'cards' => [
                ['label' => '我的内容', 'value' => (clone $mine)->count(), 'href' => '/guides', 'helper' => '我创建的教程 / 手册'],
                ['label' => '草稿', 'value' => (clone $mine)->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'published'))->count(), 'href' => '/guides', 'helper' => '尚未发布'],
                ['label' => '已发布', 'value' => (clone $mine)->published()->count(), 'href' => '/guides', 'helper' => '当前可见内容'],
                ['label' => '近 7 日有更新', 'value' => (clone $mine)->where('updated_at', '>=', $start)->count(), 'href' => '/guides', 'helper' => '按更新时间'],
            ],
            'highlights' => [],
        ];
    }
}
