<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type MetricRow = { label: string; confirmed_order_count: number; revenue_usd: string; cost_usd: string; profit_usd: string; margin: number | null; drilldown_url: string }
type TrendRow = { date: string; revenue_usd: string; cost_usd: string; profit_usd: string }
type TableKey = 'country' | 'product' | 'source'
const props = defineProps<{
    filters: Record<string, string>
    kpis: { confirmed_revenue: string; confirmed_cost: string; confirmed_profit: string; margin: number | null; confirmed_loss_orders: number; profit_completeness_percent: number }
    data_health: { eligible_operational_orders: number; confirmed_profit_orders: number; incomplete_orders: number; fx_pending_orders: number }
    trend: TrendRow[]
    tables: Record<TableKey, MetricRow[]>
    allocation_rule: string
}>()
const filters = reactive({
    date_from: props.filters.date_from ?? '', date_to: props.filters.date_to ?? '', country: props.filters.country ?? '',
    source_type: props.filters.source_type ?? '', product: props.filters.product ?? '',
})
const apply = () => router.get('/accounting', Object.fromEntries(Object.entries(filters).filter(([, value]) => value)), { preserveState: true, replace: true })
const reset = () => router.get('/accounting')
const percent = (value: number | null) => value === null ? '—' : `${(value * 100).toFixed(2)}%`
const money = (value: string) => `$${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const tableDefinitions: { key: TableKey; label: string }[] = [
    { key: 'country', label: '国家' }, { key: 'product', label: '产品 / SKU' }, { key: 'source', label: '订单来源' },
]
const chartWidth = 720
const chartHeight = 220
const chartPadding = 18
const chartValues = computed(() => props.trend.flatMap(row => [Number(row.revenue_usd), Number(row.cost_usd), Number(row.profit_usd)]))
const chartMin = computed(() => Math.min(0, ...chartValues.value))
const chartMax = computed(() => Math.max(1, ...chartValues.value))
const x = (index: number) => props.trend.length <= 1 ? chartWidth / 2 : chartPadding + index * ((chartWidth - chartPadding * 2) / (props.trend.length - 1))
const y = (value: number) => chartPadding + (chartMax.value - value) * ((chartHeight - chartPadding * 2) / (chartMax.value - chartMin.value || 1))
const pathFor = (field: keyof Pick<TrendRow, 'revenue_usd' | 'cost_usd' | 'profit_usd'>) => props.trend
    .map((row, index) => `${index === 0 ? 'M' : 'L'} ${x(index).toFixed(1)} ${y(Number(row[field])).toFixed(1)}`)
    .join(' ')
const zeroY = computed(() => y(0))
const singleTrend = computed(() => props.trend.length === 1 ? props.trend[0] : null)
</script>

<template>
    <Head title="会计总览 · BYL ERP" />
    <AuthenticatedLayout title="会计总览" :breadcrumbs="['BYL ERP', '会计', '概览']">
        <section class="mvp-page accounting-dashboard">
            <div class="mvp-page-heading">
                <div><p class="mvp-kicker">FINANCE / PROFIT DASHBOARD</p><h2>会计总览</h2><p>仅确认或已对账订单进入确认金额；历史物流订单、估算与 FX 未冻结订单均不进入 USD 汇总。</p></div>
                <div class="page-header-actions"><Link class="mvp-button" href="/accounting/order-costs?profit_status=loss">亏损订单</Link><Link class="mvp-button" href="/accounting/order-costs">订单利润</Link></div>
            </div>

            <form class="mvp-card dashboard-filters" @submit.prevent="apply">
                <label>开始日期<input v-model="filters.date_from" type="date"></label>
                <label>结束日期<input v-model="filters.date_to" type="date"></label>
                <label>国家<input v-model.trim="filters.country" placeholder="US"></label>
                <label>订单来源<select v-model="filters.source_type"><option value="">全部来源</option><option value="woocommerce">woocommerce</option><option value="website">website</option><option value="manual">manual</option><option value="manual_other">manual_other</option><option value="kol">kol</option></select></label>
                <label>产品 / SKU<input v-model.trim="filters.product" placeholder="SKU 或产品名称"></label>
                <div class="filter-actions"><button class="mvp-button mvp-button-primary">应用筛选</button><button type="button" class="mvp-button" @click="reset">重置</button></div>
            </form>

            <div class="kpi-grid">
                <article class="kpi-card kpi-card-primary"><span class="kpi-label">确认收入</span><div class="kpi-value"><strong>{{ money(kpis.confirmed_revenue) }}</strong><small>USD</small></div><p>仅计入可进入 USD 汇总的确认订单</p></article>
                <article class="kpi-card"><span class="kpi-label">确认成本</span><div class="kpi-value"><strong>{{ money(kpis.confirmed_cost) }}</strong><small>USD</small></div><p>已确认的订单级总成本</p></article>
                <article class="kpi-card kpi-card-profit"><span class="kpi-label">确认利润</span><div class="kpi-value"><strong>{{ money(kpis.confirmed_profit) }}</strong><small>USD</small></div><p>确认收入减确认成本</p></article>
                <article class="kpi-card"><span class="kpi-label">Margin</span><div class="kpi-value"><strong>{{ percent(kpis.margin) }}</strong></div><p>确认利润 / 确认收入</p></article>
                <article class="kpi-card" :class="{ 'kpi-card-alert': kpis.confirmed_loss_orders > 0 }"><span class="kpi-label">确认亏损订单</span><div class="kpi-value"><strong>{{ kpis.confirmed_loss_orders }}</strong><small>单</small></div><p>仅 A7 confirmed loss authority</p></article>
                <article class="kpi-card"><span class="kpi-label">利润完整度</span><div class="kpi-value"><strong>{{ kpis.profit_completeness_percent }}%</strong></div><p>{{ data_health.confirmed_profit_orders }} / {{ data_health.eligible_operational_orders }} 单已确认</p></article>
            </div>

            <div class="trend-health-grid">
                <div class="mvp-card trend-card">
                    <div class="card-heading"><div><p class="mvp-kicker">DAILY TREND</p><h3>确认利润趋势</h3></div><div class="trend-legend"><span class="legend revenue">收入</span><span class="legend cost">成本</span><span class="legend profit">利润</span></div></div>
                    <div v-if="trend.length > 1" class="chart-shell">
                        <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" role="img" aria-label="确认收入、成本、利润日趋势">
                            <line x1="18" :y1="zeroY" x2="702" :y2="zeroY" class="zero-line" />
                            <path :d="pathFor('revenue_usd')" class="series revenue-line" />
                            <path :d="pathFor('cost_usd')" class="series cost-line" />
                            <path :d="pathFor('profit_usd')" class="series profit-line" />
                        </svg>
                        <div class="chart-dates"><span>{{ trend[0]?.date }}</span><span>{{ trend[trend.length - 1]?.date }}</span></div>
                    </div>
                    <div v-else-if="singleTrend" class="single-day-trend">
                        <div class="single-day-heading"><span>当前仅有 1 个确认日期</span><strong>{{ singleTrend.date }}</strong></div>
                        <div class="single-day-metrics">
                            <div><span>确认收入</span><strong>{{ money(singleTrend.revenue_usd) }}</strong></div>
                            <div><span>确认成本</span><strong>{{ money(singleTrend.cost_usd) }}</strong></div>
                            <div><span>确认利润</span><strong>{{ money(singleTrend.profit_usd) }}</strong></div>
                        </div>
                    </div>
                    <p v-else class="empty-state">当前筛选范围暂无可进入 USD 汇总的确认金额。</p>
                </div>
                <aside class="mvp-card health-card">
                    <div><p class="mvp-kicker">DATA HEALTH</p><h3>数据状态</h3></div>
                    <dl><div><dt>可纳入运营订单</dt><dd>{{ data_health.eligible_operational_orders }}</dd></div><div><dt>确认利润订单</dt><dd>{{ data_health.confirmed_profit_orders }}</dd></div><div><dt>未确认 / 不完整</dt><dd>{{ data_health.incomplete_orders }}</dd></div><div :class="{ 'health-warning': data_health.fx_pending_orders > 0 }"><dt>FX 待处理</dt><dd>{{ data_health.fx_pending_orders }}</dd></div></dl>
                    <p>FX 待处理订单保留在数据健康度中，但不会进入 USD 金额 KPI 与聚合表。</p>
                </aside>
            </div>

            <div class="aggregation-grid">
                <div v-for="entry in tableDefinitions" :key="entry.key" class="mvp-card aggregation-card">
                    <h3>{{ entry.label }}</h3>
                    <div class="table-wrap"><table><thead><tr><th>维度</th><th>订单</th><th>收入</th><th>成本</th><th>利润</th><th>Margin</th></tr></thead><tbody>
                        <tr v-for="row in tables[entry.key]" :key="row.label"><td><Link :href="row.drilldown_url">{{ row.label }}</Link></td><td>{{ row.confirmed_order_count }}</td><td>{{ money(row.revenue_usd) }}</td><td>{{ money(row.cost_usd) }}</td><td>{{ money(row.profit_usd) }}</td><td>{{ percent(row.margin) }}</td></tr>
                        <tr v-if="!tables[entry.key].length"><td colspan="6" class="empty-cell">暂无确认金额</td></tr>
                    </tbody></table></div>
                </div>
            </div>
            <p class="allocation-note"><strong>产品分摊规则：</strong>{{ allocation_rule }}</p>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.dashboard-filters { display: grid; grid-template-columns: repeat(5, minmax(130px, 1fr)) auto; align-items: end; gap: 12px; margin-bottom: 16px; }
.dashboard-filters label { display: grid; gap: 5px; font-size: 12px; color: #64748b; }
.dashboard-filters input, .dashboard-filters select { min-height: 38px; border: 1px solid #cbd5e1; border-radius: 7px; padding: 7px 9px; background: white; color: #0f172a; }
.filter-actions { display: flex; gap: 8px; }
.kpi-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; margin-top: 16px; }
.kpi-card { min-width: 0; border: 1px solid #dbe4ec; border-radius: 12px; background: #fff; padding: 16px 16px 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, .035); }
.kpi-card-primary { border-top: 3px solid #2563eb; padding-top: 14px; }
.kpi-card-profit { border-top: 3px solid #059669; padding-top: 14px; }
.kpi-card-alert { border-top: 3px solid #dc2626; padding-top: 14px; }
.kpi-label { display: block; color: #64748b; font-size: 12px; font-weight: 600; letter-spacing: .02em; }
.kpi-value { display: flex; align-items: baseline; gap: 7px; margin-top: 8px; min-height: 34px; }
.kpi-value strong { color: #0f172a; font-size: clamp(22px, 1.55vw, 29px); line-height: 1; font-weight: 750; letter-spacing: -.025em; font-variant-numeric: tabular-nums; white-space: nowrap; }
.kpi-value small { color: #94a3b8; font-size: 11px; font-weight: 700; }
.kpi-card p { margin: 9px 0 0; color: #94a3b8; font-size: 11px; line-height: 1.45; }
.trend-health-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(260px, .72fr); gap: 16px; margin-top: 16px; }
.card-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.card-heading h3, .health-card h3, .aggregation-card h3 { margin: 2px 0 12px; }
.trend-legend { display: flex; gap: 12px; color: #64748b; font-size: 12px; }
.legend::before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 999px; margin-right: 5px; }
.legend.revenue::before { background: #2563eb; }.legend.cost::before { background: #f59e0b; }.legend.profit::before { background: #059669; }
.chart-shell { min-height: 230px; } svg { width: 100%; min-width: 560px; height: 220px; overflow: visible; }
.series { fill: none; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }.revenue-line { stroke: #2563eb; }.cost-line { stroke: #f59e0b; }.profit-line { stroke: #059669; }.zero-line { stroke: #cbd5e1; stroke-width: 1; stroke-dasharray: 5 5; }
.chart-dates { display: flex; justify-content: space-between; color: #94a3b8; font-size: 11px; }
.single-day-trend { display: grid; align-content: center; min-height: 205px; padding: 18px 4px 8px; }
.single-day-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; color: #94a3b8; font-size: 12px; }
.single-day-heading strong { color: #475569; font-size: 13px; }
.single-day-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 18px; }
.single-day-metrics div { border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; padding: 16px; }
.single-day-metrics span { display: block; color: #64748b; font-size: 12px; }
.single-day-metrics strong { display: block; margin-top: 7px; color: #0f172a; font-size: 22px; font-variant-numeric: tabular-nums; }
.health-card dl { display: grid; gap: 3px; margin: 0; }.health-card dl div { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding: 10px 0; }.health-card dt { color: #64748b; font-size: 13px; }.health-card dd { margin: 0; color: #0f172a; font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
.health-card dl .health-warning { margin: 2px -8px 0; padding: 10px 8px; border: 1px solid #fed7aa; border-radius: 8px; background: #fff7ed; }.health-card dl .health-warning dt, .health-card dl .health-warning dd { color: #c2410c; }
.health-card > p { margin: 14px 0 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.aggregation-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 16px; }.table-wrap { overflow-x: auto; } table { width: 100%; border-collapse: collapse; font-size: 13px; } th, td { border-bottom: 1px solid #e2e8f0; padding: 9px 8px; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; } th:first-child, td:first-child { text-align: left; } th { color: #64748b; font-weight: 600; } td { color: #334155; } td a { color: #0369a1; text-decoration: underline; text-underline-offset: 2px; }.empty-cell, .empty-state { color: #94a3b8; text-align: center !important; }.allocation-note { color: #64748b; font-size: 12px; margin-top: 16px; }
@media (max-width: 1440px) { .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 1280px) { .dashboard-filters { grid-template-columns: repeat(3, minmax(150px, 1fr)); }.aggregation-grid { grid-template-columns: 1fr; } }
@media (max-width: 900px) { .dashboard-filters, .trend-health-grid, .kpi-grid { grid-template-columns: 1fr; }.filter-actions { justify-content: flex-start; }.chart-shell { overflow-x: auto; }.single-day-metrics { grid-template-columns: 1fr; } }
</style>
