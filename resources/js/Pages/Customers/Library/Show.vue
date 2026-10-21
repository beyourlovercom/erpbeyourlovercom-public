<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../../Composables/useAccountGridPreferences'

type Customer = {
    id: number
    customer_number: string
    name: string
    email: string | null
    phone: string | null
    country_code: string | null
    language_code: string | null
    source_label: string
    owner: { name: string } | null
    notes: string | null
    archived_at: string | null
    created_at: string | null
}
type Address = {
    name: string | null
    company: string | null
    phone: string | null
    country_code: string | null
    province_state: string | null
    city: string | null
    address_line_1: string | null
    address_line_2: string | null
    postal_code: string | null
} | null
type RevenueByCurrency = {
    currency: string
    revenue: number
    average_order_value: number
    order_count: number
}
type Metrics = {
    order_count: number
    first_order_date: string | null
    last_order_date: string | null
    revenue_by_currency: RevenueByCurrency[]
    shipment_count: number
}
type SupportTicket = { id: number; ticket_number: string; subject: string; status: string; priority: string; source_type: string; assignee: { name: string } | null; last_activity_at: string | null; created_at: string | null }
type Order = {
    id: number
    erp_order_id: string | null
    external_order_number: string | null
    number: string | null
    order_date: string | null
    status: string
    revenue: number
    currency: string
    shipment_status: string | null
}
type Shipment = {
    id: number
    erp_order_id: string | null
    external_order_number: string | null
    order_number: string | null
    order_id: number
    carrier: string | null
    tracking_number: string | null
    shipped_at: string | null
    status: string | null
}
type OrderColumn = 'order_number' | 'date' | 'amount' | 'order_status' | 'shipment_status'
type ShipmentColumn = 'order_number' | 'carrier' | 'tracking' | 'shipped_at' | 'status'
const orderDefinitions: GridColumnDefinition<OrderColumn>[] = [
    { key: 'order_number', label: 'ERP订单号' }, { key: 'date', label: '日期' }, { key: 'amount', label: '金额' },
    { key: 'order_status', label: '订单状态' }, { key: 'shipment_status', label: '物流状态' },
]
const shipmentDefinitions: GridColumnDefinition<ShipmentColumn>[] = [
    { key: 'order_number', label: 'ERP订单号' }, { key: 'carrier', label: '承运商' }, { key: 'tracking', label: '追踪号' },
    { key: 'shipped_at', label: '发货日期' }, { key: 'status', label: '状态' },
]
const orderGrid = reactive(useAccountGridPreferences<OrderColumn>({ namespace: 'grid.customers.library.show.orders.v1', definitions: orderDefinitions, requiredKeys: ['order_number'] }))
const shipmentGrid = reactive(useAccountGridPreferences<ShipmentColumn>({ namespace: 'grid.customers.library.show.shipments.v1', definitions: shipmentDefinitions, requiredKeys: ['order_number'] }))
type SubscriptionFact = { id: number; channel: string | null; provider: string | null; source_site: string | null; status: string | null; consent_source: string | null; subscribed_at: string | null; unsubscribed_at: string | null; status_updated_at: string | null; last_synced_at: string | null }
type Group = { id: number; name: string; description: string | null }

const props = defineProps<{
    customer: Customer
    billingAddress: Address
    shippingAddress: Address
    metrics: Metrics
    recentOrders: Order[]
    shipments: Shipment[]
    subscription: { matched: boolean; subscription_facts: SubscriptionFact[] }
    audit: { action: string; action_label: string; actor_id: number | null; actor_name: string | null; created_at: string | null; reason: string | null }[]
    systemSegments: { key: string; label: string }[]
    manualGroups: Group[]
    canOperate: boolean
    supportTickets: SupportTicket[]
    pendingSupportTickets: number
}>()

const money = (value: number, currency: string) =>
    new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(value)

const dateTime = (value: string | null) => {
    if (!value) return '—'
    const parsed = new Date(value)
    if (Number.isNaN(parsed.getTime())) return value
    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(parsed)
}

const supportLabel = (value: string) => ({
    open: '待处理', waiting_customer: '等待客户', waiting_internal: '内部处理中',
    resolved: '已解决', closed: '已关闭', low: '低', normal: '普通', high: '高',
    urgent: '紧急', manual: '手工录入',
} as Record<string, string>)[value] ?? value

const initials = computed(() => {
    const name = props.customer.name.trim()
    const parts = name.split(/\s+/)
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    return name.slice(0, 2).toUpperCase()
})

const archive = () => {
    if (window.confirm(`确认归档客户「${props.customer.name}」？`)) {
        router.post(`/customers/library/${props.customer.id}/archive`)
    }
}
const restore = () => {
    if (window.confirm(`确认恢复客户「${props.customer.name}」？`)) {
        router.post(`/customers/library/${props.customer.id}/restore`)
    }
}

function addressLines(addr: Address): string[] {
    if (!addr) return []
    const parts = [
        addr.name, addr.company, addr.phone,
        [addr.address_line_1, addr.address_line_2].filter(Boolean).join(', '),
        [addr.city, addr.province_state].filter(Boolean).join(', '),
        [addr.country_code, addr.postal_code].filter(Boolean).join(' '),
    ]
    return parts.filter((p): p is string => typeof p === 'string' && p.trim() !== '')
}
</script>
<template>
    <Head :title="`${customer.name} · BYL ERP`" />
    <AuthenticatedLayout title="客户档案" :breadcrumbs="['BYL ERP', '客户', '客户库', customer.name]">
        <div class="erp-form-page">
            <div class="erp-form-content">

                <!-- Page header + action zone -->
                <div class="erp-form-header-row">
                    <div>
                        <p class="erp-form-subtitle" style="margin-bottom: 4px;">{{ customer.customer_number }}</p>
                        <h1 class="erp-form-title">{{ customer.name }}</h1>
                    </div>
                    <div class="erp-form-header-actions page-header-actions">
                        <Link class="erp-btn erp-btn-secondary" href="/customers/library">返回列表</Link>
                        <button v-if="canOperate && customer.archived_at" class="erp-btn erp-btn-secondary" type="button" @click="restore">恢复</button>
                        <button v-if="canOperate && !customer.archived_at" class="erp-btn erp-btn-danger" type="button" @click="archive">归档</button>
                        <Link v-if="canOperate && !customer.archived_at" class="erp-btn erp-btn-primary" :href="`/customers/library/${customer.id}/edit`">编辑</Link>
                    </div>
                </div>

                <!-- Profile hero -->
                <article class="erp-form-card profile-hero">
                    <div class="profile-avatar">{{ initials }}</div>
                    <div class="profile-details">
                        <h2 class="profile-name">{{ customer.name }}</h2>
                        <p class="profile-sub">{{ customer.customer_number }}<span v-if="customer.archived_at" class="archived-badge">已归档</span></p>
                        <div class="profile-meta-row">
                            <span v-if="customer.email">✉ {{ customer.email }}</span>
                            <span v-if="customer.phone">📞 {{ customer.phone }}</span>
                            <span v-if="customer.country_code">🌏 {{ customer.country_code }}</span>
                            <span v-if="customer.language_code">🗣 {{ customer.language_code }}</span>
                            <span>来源：{{ customer.source_label }}</span>
                            <span v-if="customer.owner">负责人：{{ customer.owner.name }}</span>
                        </div>
                        <p v-if="customer.notes" class="profile-notes">{{ customer.notes }}</p>
                    </div>
                </article>

                <nav class="c360-section-nav" aria-label="客户360分区导航">
                    <a href="#groups">分组</a><a href="#addresses">地址</a><a href="#orders">购买</a><a href="#shipments">发货</a><a href="#subscriptions">订阅事实</a><a href="#support">支持</a><a href="#activity">活动</a>
                </nav>

                <article id="groups" class="erp-form-card c360-groups-card">
                    <div class="section-heading"><h2 class="erp-form-card-title">客户分组</h2><Link class="mvp-link" href="/customers/groups">管理分组</Link></div>
                    <div class="group-columns">
                        <div><p class="group-label">系统分群 <span>只读计算</span></p><div v-if="systemSegments.length" class="group-chips"><span v-for="group in systemSegments" :key="group.key" class="group-chip system">{{ group.label }}</span></div><p v-else class="integration-unavailable">当前没有命中的系统分群</p></div>
                        <div><p class="group-label">手动分组 <span>已保存成员关系</span></p><div v-if="manualGroups.length" class="group-chips"><span v-for="group in manualGroups" :key="group.id" class="group-chip manual">{{ group.name }}</span></div><p v-else class="integration-unavailable">当前未加入手动分组</p></div>
                    </div>
                </article>

                <!-- Six KPI cards -->
                <div class="c360-kpi-grid">
                    <!-- 1. 累计订单 -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">累计订单</p>
                        <p class="kpi-value">{{ metrics.order_count }}</p>
                        <p class="kpi-sublabel">首购 {{ metrics.first_order_date ?? '—' }}</p>
                    </article>

                    <!-- 2. 累计收入（按币种） -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">累计消费</p>
                        <template v-if="metrics.revenue_by_currency.length === 0">
                            <p class="kpi-value kpi-muted">—</p>
                        </template>
                        <template v-else>
                            <div v-for="row in metrics.revenue_by_currency" :key="row.currency" class="kpi-currency-block">
                                <p class="kpi-value kpi-value-md">{{ money(row.revenue, row.currency) }}</p>
                                <p class="kpi-sublabel">{{ row.currency }}</p>
                            </div>
                        </template>
                    </article>

                    <!-- 3. 平均客单价（按币种） -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">平均客单价</p>
                        <template v-if="metrics.revenue_by_currency.length === 0">
                            <p class="kpi-value kpi-muted">—</p>
                        </template>
                        <template v-else>
                            <div v-for="row in metrics.revenue_by_currency" :key="row.currency" class="kpi-currency-block">
                                <p class="kpi-value kpi-value-md">{{ money(row.average_order_value, row.currency) }}</p>
                                <p class="kpi-sublabel">{{ row.currency }} · {{ row.order_count }} 笔</p>
                            </div>
                        </template>
                    </article>

                    <!-- 4. 最近购买 -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">最近购买</p>
                        <p class="kpi-value kpi-date">{{ metrics.last_order_date ?? '—' }}</p>
                    </article>

                    <!-- 5. 发货次数 -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">发货次数</p>
                        <p class="kpi-value">{{ metrics.shipment_count }}</p>
                    </article>

                    <!-- 6. 客服工单 -->
                    <article class="erp-form-card c360-kpi-card">
                        <p class="kpi-label">待处理工单</p>
                        <p class="kpi-value">{{ pendingSupportTickets }}</p>
                        <p class="kpi-sublabel">待处理客户支持工单</p>
                    </article>
                </div>

                <!-- 账单地址 / 收货地址 -->
                <div id="addresses" class="c360-address-grid">
                    <article class="erp-form-card">
                        <h2 class="erp-form-card-title">账单地址</h2>
                        <template v-if="billingAddress">
                            <p v-for="line in addressLines(billingAddress)" :key="line" class="address-line">{{ line }}</p>
                        </template>
                        <p v-else class="address-empty">未设置账单地址</p>
                    </article>
                    <article class="erp-form-card">
                        <h2 class="erp-form-card-title">收货地址</h2>
                        <template v-if="shippingAddress">
                            <p v-for="line in addressLines(shippingAddress)" :key="line" class="address-line">{{ line }}</p>
                        </template>
                        <p v-else class="address-empty">未设置收货地址</p>
                    </article>
                </div>

                <!-- 采购历史 -->
                <article id="orders" class="erp-form-card">
                    <h2 class="erp-form-card-title">历史购买记录</h2>
                    <div class="mvp-table-wrap">
                        <table class="mvp-table">
                            <thead>
                                <tr><th v-for="column in orderGrid.visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="orderGrid.columns" :required-keys="['order_number']" :sync-state="orderGrid.syncState" @toggle="orderGrid.toggle" @start-drag="orderGrid.startDrag" @drop="orderGrid.drop" @reset="orderGrid.reset" /></tr>
                            </thead>
                            <tbody>
                                <tr v-for="order in recentOrders" :key="order.id">
                                    <td v-for="column in orderGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link :href="`/customers/orders/${order.id}`" class="mvp-link">{{ order.erp_order_id ?? '—' }}</Link></template><template v-else-if="column.key === 'date'">{{ order.order_date ?? '—' }}</template><template v-else-if="column.key === 'amount'">{{ money(order.revenue, order.currency) }}</template><template v-else-if="column.key === 'order_status'">{{ order.status }}</template><template v-else>{{ order.shipment_status ?? '—' }}</template></td>
                                </tr>
                                <tr v-if="recentOrders.length === 0">
                                    <td :colspan="orderGrid.visibleColumns.length + 1" class="mvp-empty">暂无已明确关联的订单</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <!-- 发货记录 -->
                <article id="shipments" class="erp-form-card">
                    <h2 class="erp-form-card-title">发货记录</h2>
                    <div class="mvp-table-wrap">
                        <table class="mvp-table">
                            <thead>
                                <tr><th v-for="column in shipmentGrid.visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="shipmentGrid.columns" :required-keys="['order_number']" :sync-state="shipmentGrid.syncState" @toggle="shipmentGrid.toggle" @start-drag="shipmentGrid.startDrag" @drop="shipmentGrid.drop" @reset="shipmentGrid.reset" /></tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in shipments" :key="s.id">
                                    <td v-for="column in shipmentGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="s.erp_order_id" :href="`/customers/orders/${s.order_id}`" class="mvp-link">{{ s.erp_order_id }}</Link><span v-if="!s.erp_order_id">—</span></template><template v-else-if="column.key === 'carrier'">{{ s.carrier ?? '—' }}</template><template v-else-if="column.key === 'tracking'">{{ s.tracking_number ?? '—' }}</template><template v-else-if="column.key === 'shipped_at'">{{ s.shipped_at ?? '—' }}</template><template v-else>{{ s.status ?? '—' }}</template></td>
                                </tr>
                                <tr v-if="shipments.length === 0">
                                    <td :colspan="shipmentGrid.visibleColumns.length + 1" class="mvp-empty">暂无发货记录</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <!-- 订阅记录 -->
                <article id="subscriptions" class="erp-form-card">
                    <h2 class="erp-form-card-title">订阅记录</h2>
                    <p class="subscriber-summary">身份匹配：{{ subscription.matched ? '已匹配' : '未匹配' }} · 订阅事实：{{ subscription.subscription_facts.length }} 条；不推断统一同意状态。</p>
                    <p v-if="!customer.email" class="integration-unavailable">该客户没有 Email，无法匹配 Subscriber 身份。</p>
                    <p v-else-if="!subscription.matched" class="integration-unavailable">未找到匹配的 Marketing Subscriber 身份。</p>
                    <p v-else-if="subscription.subscription_facts.length === 0" class="integration-unavailable">已匹配 Subscriber 身份，但暂无订阅事实。</p>
                    <div v-else class="mvp-table-wrap">
                        <table class="mvp-table">
                            <thead><tr><th>Provider</th><th>来源网站</th><th>状态</th><th>同意来源</th><th>订阅时间</th><th>退订时间</th><th>状态时间</th><th>最后同步</th></tr></thead>
                            <tbody><tr v-for="fact in subscription.subscription_facts" :key="fact.id">
                                <td>{{ fact.provider ?? '—' }}</td><td>{{ fact.source_site ?? '—' }}</td><td>{{ fact.status ?? '—' }}</td><td>{{ fact.consent_source ?? '—' }}</td>
                                <td>{{ fact.subscribed_at ?? '—' }}</td><td>{{ fact.unsubscribed_at ?? '—' }}</td><td>{{ fact.status_updated_at ?? '—' }}</td><td>{{ fact.last_synced_at ?? '—' }}</td>
                            </tr></tbody>
                        </table>
                    </div>
                </article>

                <!-- 客户支持工单 -->
                <article id="support" class="erp-form-card">
                    <h2 class="erp-form-card-title">客户支持工单</h2>
                    <div class="c360-section-actions"><Link v-if="canOperate" class="erp-btn erp-btn-primary" :href="`/customers/library/${customer.id}/support-tickets/create`">新建工单</Link></div>
                    <div v-if="supportTickets.length" class="mvp-table-wrap">
                        <table class="mvp-table"><thead><tr><th>工单号</th><th>主题</th><th>状态</th><th>优先级</th><th>来源</th><th>负责人</th><th>最后活动</th><th>创建时间</th><th>操作</th></tr></thead>
                            <tbody><tr v-for="ticket in supportTickets" :key="ticket.id"><td>{{ ticket.ticket_number }}</td><td>{{ ticket.subject }}</td><td>{{ supportLabel(ticket.status) }}</td><td>{{ supportLabel(ticket.priority) }}</td><td>{{ supportLabel(ticket.source_type) }}</td><td>{{ ticket.assignee?.name ?? '—' }}</td><td>{{ ticket.last_activity_at ?? '—' }}</td><td>{{ ticket.created_at ?? '—' }}</td><td><Link class="erp-btn erp-btn-secondary table-action-btn" :href="`/customers/library/${customer.id}/support-tickets/${ticket.id}`">查看</Link></td></tr></tbody>
                        </table>
                    </div>
                    <p v-else class="integration-unavailable">暂无客户支持工单</p>
                </article>

                <!-- 操作审计 -->
                <article id="activity" class="erp-form-card">
                    <h2 class="erp-form-card-title">活动记录</h2>
                    <ul class="c360-audit-list">
                        <li v-for="event in audit" :key="(event.created_at ?? '') + event.action">
                            <span class="audit-action">{{ event.action_label }}</span>
                            <span class="audit-meta">{{ dateTime(event.created_at) }} · {{ event.actor_name ?? (event.actor_id ? `用户 ${event.actor_id}` : '系统') }}<span v-if="event.reason"> · {{ event.reason }}</span></span>
                        </li>
                        <li v-if="audit.length === 0" class="audit-empty">暂无审计记录</li>
                    </ul>
                </article>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
<style scoped>
/* Profile hero */
.profile-hero { display: flex; gap: 20px; align-items: flex-start; margin-bottom: 16px; }
.profile-avatar {
    width: 56px; height: 56px; border-radius: 50%;
    background: #2D8C84; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 700; flex-shrink: 0;
}
.profile-details { flex: 1; }
.profile-name { font-size: 1.3rem; font-weight: 700; color: #142126; margin: 0 0 2px; }
.profile-sub { font-size: 12px; color: #7A8C94; margin: 0 0 6px; }
.profile-meta-row { display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px; color: #344B53; margin-bottom: 6px; }
.profile-notes { font-size: 13px; color: #7A8C94; margin: 0; font-style: italic; }
.c360-section-nav { position: sticky; top: 8px; z-index: 2; display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 10px; margin-bottom: 16px; background: rgba(255,255,255,.94); border: 1px solid #DCE5E7; border-radius: 8px; }
.c360-section-nav a { color: #236F6A; text-decoration: none; font-size: 12px; padding: 4px 8px; }
.c360-section-nav a:hover { background: #F3FAF9; border-radius: 5px; }
.c360-groups-card { margin-bottom: 16px; }
.section-heading { display: flex; justify-content: space-between; align-items: center; }
.group-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.group-label { margin: 0 0 8px; font-size: 12px; color: #7A8C94; }
.group-label span { margin-left: 6px; font-size: 10px; }
.group-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.group-chip { padding: 4px 8px; border-radius: 999px; font-size: 12px; }
.group-chip.system { color: #236F6A; background: #E6F5F2; }
.group-chip.manual { color: #755414; background: #FFF4D6; }
.subscriber-summary { margin: -8px 0 12px; font-size: 12px; color: #7A8C94; }
.archived-badge {
    display: inline-block; margin-left: 8px;
    background: #FEF3CD; color: #856404;
    font-size: 10px; padding: 1px 6px; border-radius: 4px; vertical-align: middle;
}

/* KPI grid - 6 cards */
.c360-kpi-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}
.c360-kpi-card { display: flex; flex-direction: column; gap: 4px; }
.kpi-unavailable { opacity: 0.6; }
.kpi-label { font-size: 12px; color: #7A8C94; margin: 0; }
.kpi-value { font-size: 1.5rem; font-weight: 700; color: #142126; margin: 0; line-height: 1.2; }
.kpi-value-md { font-size: 1.1rem; }
.kpi-date { font-size: 1.0rem; }
.kpi-muted { font-size: 1rem; font-weight: 500; color: #7A8C94; }
.kpi-sublabel { font-size: 11px; color: #7A8C94; margin: 0; }
.kpi-currency-block { margin-top: 2px; }

/* Addresses */
.c360-address-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.address-line { font-size: 14px; color: #142126; margin: 2px 0; }
.address-empty { font-size: 13px; color: #7A8C94; font-style: italic; margin: 0; }

/* Integration panels */
.integration-unavailable { font-size: 13px; color: #7A8C94; font-style: italic; margin: 0; }

/* Audit */
.c360-audit-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.c360-audit-list li { display: flex; flex-direction: column; gap: 2px; }
.audit-action { font-size: 13px; color: #142126; font-weight: 500; }
.audit-meta { font-size: 11px; color: #7A8C94; }
.audit-empty { font-size: 13px; color: #7A8C94; font-style: italic; }

.erp-btn-danger { background: #D9534F; color: #fff; border-color: #D9534F; }
.erp-btn-danger:hover { background: #c9302c; }

@media (max-width: 1100px) { .c360-kpi-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px) {
    .c360-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .c360-address-grid { grid-template-columns: 1fr; }
    .profile-hero { flex-direction: column; }
    .group-columns { grid-template-columns: 1fr; }
}
@media (max-width: 440px) { .c360-kpi-grid { grid-template-columns: 1fr; } }
</style>
