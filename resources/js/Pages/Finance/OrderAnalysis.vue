<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import {
    useAccountGridPreferences,
    type GridColumnDefinition,
    type GridPreferenceValue,
} from '../../Composables/useAccountGridPreferences'

type CostBreakdown = {
    product_cost: string | null
    logistics_cost: string | null
    other_cost: string | null
}

type Summary = {
    completeness: string
    finance_status: string
    revenue_status: string
    cost_status: string
    profit_status: string
    missing_facts: string[]
    aggregation_missing_facts: string[]
    fx_status: string
    base_currency_aggregate_eligible: boolean
    commercial_revenue_before_refund: string | null
    net_revenue: string | null
    known_cost: string | null
    current_stage_profit: string | null
    current_stage_margin: string | null
    posted_cost_count: number
    confirmed_cost_count: number
    estimated_cost_count: number
    required_cost_count: number
    p82_estimated_profit_base_ready: boolean
    p82_required_cost_count: number
    p82_required_cost_covered_count: number
    p82_missing_required_costs: string[]
    canonical_estimated_ready: boolean
    canonical_estimated_total_cost: string | null
    canonical_estimated_cost_breakdown: {
        product_cost: string
        logistics_cost: string
        other_cost: string
    }
    canonical_estimated_profit: string | null
    canonical_estimated_margin: string | null
    posted_estimated_cost_count: number
    fallback_estimated_cost_count: number
    projection_confidence: string
    total_cost: string | null
    contribution_profit: string | null
    margin: string | null
    cost_breakdown: CostBreakdown
}

type ProductRef = {
    product_id: number | null
    sku: string | null
    name: string | null
}

type Row = {
    id: number
    erp_order_id: string | null
    external_order_number: string | null
    order_date: string | null
    currency: string
    source_type: string
    country: string | null
    recipient_name: string | null
    products: ProductRef[]
    revenue_provenance: string | null
    provider_order_status: string | null
    provider_reconciliation_status: string | null
    paid_at: string | null
    summary: Summary
    values: Record<string, string | null>
}

type Pagination = {
    current_page: number
    last_page: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
}

type ColumnKey =
    | 'order_number'
    | 'date'
    | 'source'
    | 'country'
    | 'products'
    | 'currency'
    | 'revenue'
    | 'refund'
    | 'net_revenue'
    | 'product_cost'
    | 'shipping_cost'
    | 'other_cost'
    | 'total_cost'
    | 'profit'
    | 'final_profit'
    | 'margin'
    | 'completeness'
    | 'missing'

type Preference = GridPreferenceValue<ColumnKey>

type Kpis = {
    eligible_orders: number
    revenue_ready_orders: number
    estimated_profit_orders: number
    estimated_profit_percent: number
    p82_required_cost_ready_orders: number
    p82_required_cost_percent: number
    confirmed_profit_orders: number
    incomplete_orders: number
    completeness_percent: number
    final_profit_percent_of_revenue_ready: number
    fx_pending_orders: number
}

const props = defineProps<{
    orders: Row[]
    pagination: Pagination
    fields: string[]
    filters: Record<string, string>
    kpis: Kpis
    gridPreferenceNamespace: string
    gridPreference: Preference | null
}>()

const definitions: GridColumnDefinition<ColumnKey>[] = [
    { key: 'order_number', label: 'ERP订单号' },
    { key: 'date', label: '日期' },
    { key: 'source', label: '来源' },
    { key: 'country', label: '国家' },
    { key: 'products', label: 'SKU / 产品' },
    { key: 'currency', label: '币种' },
    { key: 'revenue', label: '销售额' },
    { key: 'refund', label: '退款' },
    { key: 'net_revenue', label: '净收入' },
    { key: 'product_cost', label: '产品成本' },
    { key: 'shipping_cost', label: '物流成本' },
    { key: 'other_cost', label: '其他成本' },
    { key: 'total_cost', label: '预估总成本' },
    { key: 'profit', label: '预估利润' },
    { key: 'final_profit', label: '最终确认利润' },
    { key: 'margin', label: '预估 Margin' },
    { key: 'completeness', label: '完整性' },
    { key: 'missing', label: '缺失项' },
]

const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({
    namespace: props.gridPreferenceNamespace,
    definitions,
    requiredKeys: ['order_number'],
    serverValue: props.gridPreference,
})

const editing = ref<number | null>(null)
const editingRow = computed(() => props.orders.find(row => row.id === editing.value) ?? null)
const editValues = reactive<Record<string, string>>({})
const saving = ref(false)
const saved = ref(false)
const filters = reactive({
    search: props.filters.search ?? '',
    source_type: props.filters.source_type ?? '',
    country: props.filters.country ?? '',
    product: props.filters.product ?? props.filters.sku ?? '',
    fx_status: props.filters.fx_status ?? '',
    country_missing: props.filters.country_missing ?? '',
    product_missing: props.filters.product_missing ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    completeness: props.filters.completeness ?? '',
    profit_status: props.filters.profit_status ?? '',
    sort: props.filters.sort ?? 'order_date',
    direction: props.filters.direction ?? 'desc',
})

const fieldLabels: Record<string, string> = {
    merchandise_list_amount: '商品原价合计',
    discount_amount: '折扣',
    shipping_revenue: '向客户收取运费',
    amount_paid: '实付金额（旧/手工）',
    refund_amount: '退款',
    product_cost: '产品成本',
    warehouse_cost: '仓库成本',
    first_mile_cost: '头程成本',
    overseas_handling_cost: '海外处理成本',
    last_mile_cost: '尾程 / 配送成本',
    kol_commission: 'KOL / Affiliate 佣金',
    payment_fee: '支付手续费',
    customs_tax: '关税 / 税费成本',
    refund_replacement_loss: '退款 / 补发损失',
    other_variable_cost: '其他变动成本',
}

const missingLabels: Record<string, string> = {
    settlement_components: '收入结算事实',
    merchandise_list_amount: '商品收入',
    discount_amount: '折扣',
    shipping_revenue: '运费收入',
    refund_amount: '退款',
    provider_order_total: 'Woo 订单总额',
    tax_collected: '已收税额',
    payment_settlement: '付款结算证据',
    provider_order_status_not_revenue_eligible: '订单尚未进入可确认收入状态',
    provider_reconciliation: 'Woo 收入对账',
    revenue_provenance: '收入来源',
    product_cost: '产品成本',
    warehouse_cost: '仓库成本',
    first_mile_cost: '头程成本',
    overseas_handling_cost: '海外处理成本',
    last_mile_cost: '尾程成本',
    kol_commission: 'KOL / Affiliate 佣金',
    payment_fee: '支付手续费',
    customs_tax: '关税 / 税费成本',
    refund_replacement_loss: '退款 / 补发损失',
    other_variable_cost: '其他变动成本',
    transaction_fx_snapshot: 'USD 汇总 FX 快照',
}

const revenueFields = ['merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'amount_paid', 'refund_amount']
const costFields = props.fields.filter(field => !revenueFields.includes(field))

const query = () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
const submit = () => router.get('/accounting/order-costs', query(), { preserveState: true, replace: true })
const exportCsv = () => {
    window.location.href = `/accounting/order-costs/export?${new URLSearchParams(query() as Record<string, string>)}`
}
const edit = (row: Row) => {
    editing.value = row.id
    saved.value = false
    for (const field of props.fields) editValues[field] = row.values[field] ?? ''
}

const decimalCents = (value: string | undefined): number | null => {
    const normalized = (value ?? '').trim()
    if (!/^-?\d+(?:\.\d{0,2})?$/.test(normalized)) return null
    const negative = normalized.startsWith('-')
    const [whole, fraction = ''] = normalized.replace('-', '').split('.')
    const cents = Number(whole) * 100 + Number((fraction + '00').slice(0, 2))
    return negative ? -cents : cents
}
const centsMoney = (cents: number | null, currency: string): string => cents === null ? '—' : `${currency} ${(cents / 100).toFixed(2)}`
const livePreview = computed(() => {
    const row = editingRow.value
    if (!row) return null
    const refund = decimalCents(editValues.refund_amount)
    const merchandise = decimalCents(editValues.merchandise_list_amount)
    const discount = decimalCents(editValues.discount_amount)
    const shipping = decimalCents(editValues.shipping_revenue)
    const amountPaid = decimalCents(editValues.amount_paid)
    let netRevenue: number | null = null
    if (refund !== null && merchandise !== null && discount !== null && shipping !== null) {
        netRevenue = merchandise - discount + shipping - refund
    } else if (refund !== null && amountPaid !== null) {
        netRevenue = amountPaid - refund
    }

    let knownCost = 0
    let posted = 0
    let invalidCost = false
    const missing: string[] = []
    for (const field of costFields) {
        const raw = (editValues[field] ?? '').trim()
        if (raw === '') { missing.push(field); continue }
        const cents = decimalCents(raw)
        if (cents === null) { invalidCost = true; continue }
        knownCost += cents
        posted++
    }
    const stageProfit = netRevenue !== null && !invalidCost ? netRevenue - knownCost : null
    const stageMargin = stageProfit !== null && netRevenue !== null && netRevenue !== 0 ? stageProfit / netRevenue : null
    const finalReady = netRevenue !== null && !invalidCost && posted === costFields.length
    return {
        currency: row.currency, netRevenue, knownCost, stageProfit, stageMargin, posted, required: costFields.length,
        finalProfit: finalReady ? stageProfit : null, missing, invalidCost,
    }
})
const liveMoney = (cents: number | null) => centsMoney(cents, livePreview.value?.currency ?? editingRow.value?.currency ?? 'USD')
const liveMargin = computed(() => livePreview.value?.stageMargin === null || livePreview.value?.stageMargin === undefined ? '—' : `${(livePreview.value.stageMargin * 100).toFixed(2)}%`)
const liveCoverage = computed(() => !livePreview.value?.required ? 0 : Math.round(livePreview.value.posted / livePreview.value.required * 100))
const liveMissingText = computed(() => livePreview.value?.missing.length ? livePreview.value.missing.map(field => fieldLabels[field] ?? field).join('、') : '无')

const save = (row: Row) => router.put(`/accounting/order-costs/${row.id}`, editValues, {
    preserveScroll: true,
    preserveState: true,
    onStart: () => { saving.value = true; saved.value = false },
    onSuccess: () => { saved.value = true },
    onFinish: () => { saving.value = false },
})

const orderPath = (row: Row) => `/customers/orders/${row.id}`
const productPath = (product: ProductRef) => product.product_id ? `/products/${product.product_id}` : null
const orderNumber = (row: Row) => row.erp_order_id ?? '—'
const money = (value: string | null, row: Row) => value === null ? '—' : `${row.currency} ${value}`
const margin = (value: string | null) => value === null ? '—' : `${(Number(value) * 100).toFixed(2)}%`
const statusLabel = (status: string) => ({
    UNPOSTED: '未入账',
    INCOMPLETE: '不完整',
    ESTIMATED: '估算',
    CONFIRMED: '已确认',
    RECONCILED: '已对账',
}[status] ?? status)
const missingText = (row: Row) => {
    const all = [...row.summary.missing_facts, ...row.summary.aggregation_missing_facts]
    return all.length ? all.map(item => missingLabels[item] ?? item.replace(/_/g, ' ')).join('、') : '—'
}
const cell = (row: Row, key: Exclude<ColumnKey, 'products'>) => ({
    order_number: orderNumber(row),
    date: row.order_date ?? '—',
    source: row.source_type,
    country: row.country ?? '—',
    currency: row.currency,
    revenue: money(row.summary.commercial_revenue_before_refund, row),
    refund: money(row.values.refund_amount ?? null, row),
    net_revenue: money(row.summary.net_revenue, row),
    product_cost: money(row.summary.canonical_estimated_cost_breakdown.product_cost, row),
    shipping_cost: money(row.summary.canonical_estimated_cost_breakdown.logistics_cost, row),
    other_cost: money(row.summary.canonical_estimated_cost_breakdown.other_cost, row),
    total_cost: money(row.summary.canonical_estimated_total_cost, row),
    profit: money(row.summary.canonical_estimated_profit, row),
    final_profit: row.summary.profit_status === 'confirmed' ? money(row.summary.contribution_profit, row) : '待确认',
    margin: margin(row.summary.canonical_estimated_margin),
    completeness: `预估 ${row.summary.canonical_estimated_ready ? '已计算' : '待收入'} · fallback ${row.summary.fallback_estimated_cost_count}/10；最终 ${statusLabel(row.summary.finance_status)} · ${row.summary.confirmed_cost_count}/${row.summary.required_cost_count}`,
    missing: missingText(row),
}[key])
</script>

<template>
    <Head title="订单利润 · BYL ERP" />
    <AuthenticatedLayout title="订单利润" :breadcrumbs="['BYL ERP', '会计', '订单利润']">
        <section class="mvp-page">
            <div class="mvp-page-heading">
                <div>
                    <p class="mvp-kicker">FINANCE / ORDER PROFIT</p>
                    <h2>订单利润</h2>
                    <p>收入一旦就绪就先计算预估利润：真实成本优先，缺失项使用只读 fallback 估算且不写入账本；真实证据逐项替换估算，10/10 成本全部确认后自动升级为最终利润。</p>
                </div>
                <div class="mvp-form-actions page-header-actions">
                    <button class="mvp-button" @click="exportCsv">导出 CSV</button>
                </div>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card"><span>运营订单</span><strong>{{ kpis.eligible_orders }}</strong></div>
                <div class="kpi-card"><span>收入就绪</span><strong>{{ kpis.revenue_ready_orders }}</strong></div>
                <div class="kpi-card"><span>已有预估利润</span><strong>{{ kpis.estimated_profit_orders }}</strong><small>{{ kpis.estimated_profit_percent }}% · 收入就绪即计算</small></div>
                <div class="kpi-card"><span>6 项成本已有直接证据</span><strong>{{ kpis.p82_required_cost_ready_orders }}</strong><small>{{ kpis.p82_required_cost_percent }}% · 其余使用 fallback</small></div>
                <div class="kpi-card"><span>最终利润已确认</span><strong>{{ kpis.confirmed_profit_orders }}</strong><small>{{ kpis.final_profit_percent_of_revenue_ready }}% · 10/10 实际确认</small></div>
                <div class="kpi-card"><span>USD 汇总待 FX</span><strong>{{ kpis.fx_pending_orders }}</strong></div>
            </div>

            <div class="profit-formula-strip">
                <div><strong>当前阶段利润</strong><span>= 净收入 − 当前已入账成本</span></div>
                <div><strong>最终确认利润</strong><span>= 净收入 − 产品成本 − 仓库成本 − 头程成本 − 海外仓操作费 − 尾程物流 − 支付手续费 − KOL佣金 − 关税 − 退款/补发损失 − 其他变动成本</span></div>
                <p>成本缺失不会阻断利润计算：系统先给 projection-only 预估值；真实成本逐项到账后自动替换，全部确认后显示最终利润。</p>
            </div>

            <form class="mvp-toolbar filter-grid" @submit.prevent="submit">
                <input v-model="filters.search" aria-label="搜索订单" placeholder="订单号 / 收件人 / SKU">
                <select v-model="filters.source_type" aria-label="来源">
                    <option value="">全部来源</option>
                    <option value="manual">manual</option>
                    <option value="woocommerce">woocommerce</option>
                    <option value="alibaba">alibaba</option>
                    <option value="shopify">shopify</option>
                    <option value="email">email</option>
                    <option value="whatsapp">whatsapp</option>
                    <option value="api">api</option>
                    <option value="other">other</option>
                </select>
                <input v-model="filters.country" aria-label="国家" placeholder="国家代码，如 US">
                <input v-model="filters.product" aria-label="产品或 SKU" placeholder="SKU / 产品名称">
                <input v-model="filters.date_from" aria-label="开始日期" type="date">
                <input v-model="filters.date_to" aria-label="结束日期" type="date">
                <select v-model="filters.fx_status" aria-label="USD 汇总 FX 状态">
                    <option value="">全部 FX 状态</option>
                    <option value="aggregate_ready">USD 汇总可用</option>
                    <option value="pending">FX 待处理</option>
                </select>
                <select v-model="filters.completeness" aria-label="完整性">
                    <option value="">全部财务状态</option>
                    <option value="UNPOSTED">未入账</option>
                    <option value="INCOMPLETE">不完整</option>
                    <option value="ESTIMATED">估算</option>
                    <option value="CONFIRMED">已确认</option>
                    <option value="RECONCILED">已对账</option>
                </select>
                <select v-model="filters.profit_status" aria-label="利润状态">
                    <option value="">全部利润状态</option>
                    <option value="loss">亏损订单</option>
                    <option value="confirmed">已确认利润</option>
                    <option value="not_confirmed">未确认利润</option>
                </select>
                <select v-model="filters.sort" aria-label="排序字段">
                    <option value="order_date">按日期</option>
                    <option value="order_number">按订单号</option>
                    <option value="source">按来源</option>
                    <option value="currency">按币种</option>
                </select>
                <select v-model="filters.direction" aria-label="排序方向">
                    <option value="desc">降序</option>
                    <option value="asc">升序</option>
                </select>
                <button class="mvp-button" type="submit">筛选</button>
            </form>

            <div class="grid-toolbar"><span>共 {{ pagination.total }} 条运营订单事实</span></div>

            <div class="mvp-table-wrap order-profit-grid">
                <table class="mvp-table">
                    <thead>
                        <tr>
                            <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th>
                            <th class="action-column">操作</th>
                            <ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" />
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in orders" :key="row.id">
                            <tr>
                                <td v-for="column in visibleColumns" :key="column.key">
                                    <template v-if="column.key === 'order_number'"><Link class="record-link" :href="orderPath(row)">{{ orderNumber(row) }}</Link></template>
                                    <template v-if="column.key === 'products'">
                                        <div v-if="row.products.length" class="product-links">
                                            <template v-for="product in row.products" :key="`${product.product_id}-${product.sku}-${product.name}`">
                                                <Link v-if="productPath(product)" class="record-link" :href="productPath(product) as string">
                                                    {{ product.sku || product.name || `Product #${product.product_id}` }}
                                                </Link>
                                                <span v-else>{{ product.sku || product.name || '未关联产品' }}</span>
                                            </template>
                                        </div>
                                        <span v-else class="missing">—</span>
                                    </template>
                                    <span
                                        v-else
                                        :class="{
                                            missing: cell(row, column.key as Exclude<ColumnKey, 'products'>) === '—',
                                            'status-pill': column.key === 'completeness',
                                            [`status-${row.summary.finance_status.toLowerCase()}`]: column.key === 'completeness',
                                        }"
                                    >
                                        {{ cell(row, column.key as Exclude<ColumnKey, 'products'>) }}
                                    </span>
                                </td>
                                <td class="action-column">
                                    <div class="row-actions">
                                        <Link class="mvp-button" :href="`/accounting/order-costs/${row.id}`">成本公式</Link>
                                        <button class="mvp-button" @click="edit(row)">{{ editing === row.id ? '查看中' : '订单事实' }}</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!orders.length">
                            <td :colspan="visibleColumns.length + 3" class="mvp-empty">暂无符合条件的订单利润事实</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Teleport to="body">
                <div v-if="editingRow" class="finance-drawer-backdrop" @click.self="editing = null">
                    <aside class="finance-drawer" aria-label="订单财务事实编辑" role="dialog" aria-modal="true">
                        <div class="finance-editor-header">
                            <div>
                                <p class="finance-editor-kicker">财务事实 / 成本</p>
                                <h3>{{ orderNumber(editingRow) }}</h3>
                            </div>
                            <button class="finance-drawer-close" type="button" aria-label="关闭" title="关闭" @click="editing = null">×</button>
                        </div>

                        <div class="finance-drawer-scroll">
                            <section v-if="livePreview" class="finance-live-preview">
                                <div class="finance-live-head">
                                    <div><strong>利润实时预览</strong><span>可见即所得 · 修改下方字段会立即联动，保存前不会写入数据</span></div>
                                    <span class="finance-live-badge">成本覆盖 {{ liveCoverage }}%</span>
                                </div>
                                <div class="finance-live-grid">
                                    <article><span>净收入</span><strong>{{ liveMoney(livePreview.netRevenue) }}</strong><small>收入公式实时计算</small></article>
                                    <article><span>已知成本</span><strong>{{ liveMoney(livePreview.knownCost) }}</strong><small>{{ livePreview.posted }} / {{ livePreview.required }} 项已填写</small></article>
                                    <article class="emphasis"><span>当前阶段利润</span><strong>{{ liveMoney(livePreview.stageProfit) }}</strong><small>阶段 Margin {{ liveMargin }}</small></article>
                                    <article><span>最终确认利润</span><strong>{{ livePreview.finalProfit === null ? '待确认' : liveMoney(livePreview.finalProfit) }}</strong><small>{{ livePreview.finalProfit === null ? `尚缺：${liveMissingText}` : '全部必要成本已填写' }}</small></article>
                                </div>
                                <div class="finance-live-formula">
                                    <span><b>当前阶段利润</b> = 净收入 − 当前已填写成本</span>
                                    <span><b>最终确认利润</b> = 净收入 − 全部必要成本</span>
                                </div>
                                <p v-if="livePreview.invalidCost" class="finance-live-warning">存在无法识别的成本金额，利润预览已暂停；请使用最多两位小数的数字。</p>
                                <p v-if="saved" class="finance-live-saved">✓ 已保存，订单利润事实已更新。</p>
                            </section>

                            <div class="fact-meta">
                                <span>收入来源：<b>{{ editingRow.revenue_provenance || '未入账' }}</b></span>
                                <span>Woo 状态：<b>{{ editingRow.provider_order_status || '—' }}</b></span>
                                <span>Woo 对账：<b>{{ editingRow.provider_reconciliation_status || '—' }}</b></span>
                                <span>付款时间：<b>{{ editingRow.paid_at || '—' }}</b></span>
                            </div>
                            <p class="editor-note">手工修改收入字段会明确切换为 confirmed_manual；只补成本且收入值未变化时，会保留 Woo provider_import 来源。</p>

                            <div class="finance-editor-columns">
                                <section class="finance-editor-column">
                                    <div class="finance-editor-section-title">收入事实</div>
                                    <div class="finance-editor-table">
                                        <label v-for="field in revenueFields" :key="field" class="finance-editor-field">
                                            <span>{{ fieldLabels[field] ?? field }}</span>
                                            <input v-model="editValues[field]" inputmode="decimal">
                                        </label>
                                    </div>
                                </section>

                                <section class="finance-editor-column">
                                    <div class="finance-editor-section-title">成本事实</div>
                                    <div class="finance-editor-table">
                                        <label v-for="field in costFields" :key="field" class="finance-editor-field">
                                            <span>{{ fieldLabels[field] ?? field }}</span>
                                            <input v-model="editValues[field]" inputmode="decimal">
                                        </label>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <div class="row-actions finance-editor-actions">
                            <button class="mvp-button" type="button" @click="editing = null">取消</button>
                            <button class="mvp-button mvp-button-primary" type="button" :disabled="saving" @click="save(editingRow)">{{ saving ? '保存中…' : '保存财务事实' }}</button>
                        </div>
                    </aside>
                </div>
            </Teleport>

            <nav v-if="pagination.last_page > 1" class="pagination" aria-label="分页">
                <Link
                    v-for="link in pagination.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    :class="{ active: link.active, disabled: !link.url }"
                    preserve-scroll
                    v-html="link.label"
                />
            </nav>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
.profit-formula-strip { margin: 0 0 16px; padding: 13px 15px; border: 1px solid #dfe7ea; border-radius: 8px; background: #f7faff; display: grid; gap: 7px; }
.profit-formula-strip div { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 10px; align-items: start; font-size: 12px; color: #40555c; }
.profit-formula-strip strong { color: #18282d; }
.profit-formula-strip p { margin: 2px 0 0; color: #71848b; font-size: 11px; }
.kpi-card { border: 1px solid #e1e8e8; background: #f7f9f9; border-radius: 8px; padding: 14px; display: grid; gap: 4px; }
.kpi-card span { color: #64757a; font-size: 12px; }
.kpi-card strong { font-size: 22px; color: #18282d; }
.filter-grid { display: grid; grid-template-columns: repeat(5, minmax(140px, 1fr)); gap: 8px; }
.grid-toolbar { display: flex; justify-content: space-between; align-items: center; margin: 14px 0 8px; }
.column-settings { position: relative; }
.column-trigger { min-width: 38px; font-weight: 700; }
.column-menu { position: absolute; right: 0; z-index: 20; min-width: 220px; max-height: 420px; overflow: auto; background: #fff; border: 1px solid #d8e0e3; border-radius: 8px; padding: 10px; box-shadow: 0 10px 28px rgb(20 33 38 / 14%); }
.column-menu p { margin: 0 0 6px; font-size: 12px; color: #65777c; }
.column-menu label { display: flex; gap: 8px; align-items: center; padding: 5px; cursor: grab; }
.column-menu button { margin-top: 8px; }
.order-profit-grid { overflow-x: auto; }
.order-profit-grid .mvp-table { min-width: 1780px; }
.identity-column { position: sticky; left: 0; z-index: 3; min-width: 150px; background: #fff; }
.action-column { position: sticky; right: 0; z-index: 3; min-width: 120px; background: #fff; }
thead .identity-column, thead .action-column { z-index: 4; }
.record-link { color: #1b5f72; font-weight: 600; text-decoration: none; }
.record-link:hover { text-decoration: underline; }
.product-links { display: flex; flex-direction: column; gap: 4px; min-width: 130px; }
.missing { color: #8a989c; }
.status-pill { display: inline-flex; border-radius: 999px; padding: 3px 8px; font-size: 12px; background: #eef2f3; white-space: nowrap; }
.status-confirmed, .status-reconciled { background: #e7f5ec; }
.status-estimated { background: #fff4d6; }
.status-incomplete, .status-unposted { background: #f2f3f4; }
.finance-drawer-backdrop { position: fixed; inset: 0; z-index: 1000; background: rgb(18 31 36 / 26%); }
.finance-drawer { position: absolute; top: 0; right: 0; width: min(1120px, 96vw); height: 100vh; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; background: #fff; border-left: 1px solid #cfdadd; box-shadow: -14px 0 36px rgb(20 33 38 / 18%); }
.finance-editor-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px 18px; border-bottom: 1px solid #dfe6e8; background: #f7f9f9; }
.finance-editor-header h3 { margin: 2px 0 0; font-size: 18px; color: #18282d; }
.finance-editor-kicker { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: .08em; color: #65777c; }
.finance-drawer-close { width: 34px; height: 34px; padding: 0; border: 1px solid #c9d4d7; border-radius: 6px; background: #fff; color: #40545a; font-size: 24px; line-height: 30px; cursor: pointer; }
.finance-drawer-close:hover { border-color: #879aa0; background: #f1f5f5; color: #18282d; }
.finance-drawer-scroll { min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
.finance-live-preview { position: sticky; top: 0; z-index: 5; margin: 0; padding: 14px 18px; border-bottom: 1px solid #dfe6e8; background: rgba(255,255,255,.98); backdrop-filter: blur(10px); }
.finance-live-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:10px; }
.finance-live-head > div { display:grid; gap:3px; }
.finance-live-head strong { color:#18282d; font-size:15px; }
.finance-live-head span { color:#71848b; font-size:11px; }
.finance-live-badge { display:inline-flex; padding:5px 9px; border-radius:999px; background:#eef7f5; color:#16705f !important; font-weight:700; white-space:nowrap; }
.finance-live-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
.finance-live-grid article { display:grid; gap:4px; min-width:0; padding:11px 12px; border:1px solid #e0e7e9; border-radius:9px; background:#fafcfc; }
.finance-live-grid article > span,.finance-live-grid article > small { color:#71848b; font-size:11px; }
.finance-live-grid article > strong { color:#18282d; font-size:18px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.finance-live-grid article.emphasis { border-color:#b9dfcb; background:#f2fbf6; }
.finance-live-grid article.emphasis > strong { color:#15865f; }
.finance-live-formula { display:flex; gap:18px; flex-wrap:wrap; margin-top:9px; color:#5f7075; font-size:11px; }
.finance-live-formula b { color:#33474e; }
.finance-live-warning { margin:9px 0 0; color:#a65c00; font-size:11px; }
.finance-live-saved { margin:9px 0 0; color:#16705f; font-size:11px; font-weight:700; }
.fact-meta { display: flex; flex-wrap: wrap; gap: 8px 24px; padding: 14px 18px 0; font-size: 12px; color: #5f7075; }
.editor-note { margin: 8px 18px 14px; color: #5f7075; font-size: 12px; }
.finance-editor-columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; gap: 14px; padding: 0 18px 18px; }
.finance-editor-column { min-width: 0; border: 1px solid #d8e0e3; border-radius: 6px; overflow: hidden; background: #fff; }
.finance-editor-section-title { padding: 10px 12px; border-bottom: 1px solid #d8e0e3; background: #eef3f4; font-size: 13px; font-weight: 700; color: #24383e; text-align: left; }
.finance-editor-table { display: grid; }
.finance-editor-field { display: grid; grid-template-columns: minmax(120px, 42%) minmax(110px, 1fr); align-items: center; min-height: 48px; margin: 0; border-bottom: 1px solid #e6ecee; background: #fff; }
.finance-editor-field:last-child { border-bottom: 0; }
.finance-editor-field > span { align-self: stretch; display: flex; align-items: center; padding: 9px 10px; border-right: 1px solid #e6ecee; background: #fafbfb; color: #40545a; font-size: 12px; text-align: left; }
.finance-editor-field input { width: calc(100% - 16px); min-width: 0; height: 34px; margin: 6px 8px; padding: 6px 9px; border: 1px solid #9fb0b5; border-radius: 4px; background: #fff; color: #18282d; font: inherit; box-sizing: border-box; }
.finance-editor-field input:hover { border-color: #617a82; }
.finance-editor-field input:focus { outline: 2px solid rgba(27, 95, 114, .18); outline-offset: 0; border-color: #1b5f72; }
.row-actions { display: flex; justify-content: flex-end; gap: 8px; }
.finance-editor-actions { padding: 12px 18px; border-top: 1px solid #dfe6e8; background: #f7f9f9; }
.pagination { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 16px; }
.pagination a { padding: 5px 9px; border: 1px solid #d8e0e3; border-radius: 4px; }
.pagination a.active { background: #18282d; color: #fff; }
.pagination a.disabled { opacity: .45; pointer-events: none; }
@media (max-width: 1100px) { .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filter-grid { grid-template-columns: repeat(2, minmax(140px, 1fr)); } }
@media (max-width: 900px) { .finance-live-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } .finance-editor-columns { grid-template-columns: 1fr; } }
@media (max-width: 720px) { .finance-drawer { width: 100vw; } .finance-live-grid { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .kpi-grid, .filter-grid { grid-template-columns: 1fr; } }
</style>
