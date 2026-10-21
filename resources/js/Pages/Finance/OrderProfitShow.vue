<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type MoneyPart = {
    amount: string | null
    currency: string | null
    source_amount?: string | null
    source_currency?: string | null
    unit_rate?: string | null
    rule?: string | null
    state: string
}

type ShipmentRow = {
    shipment_id: number
    fulfillment_suffix: string | null
    warehouse_code: string | null
    warehouse_name: string | null
    warehouse_slug: string | null
    tracking_number: string | null
    carrier: string | null
    shipped_units: number | null
    items: Array<{ sku?: string | null; quantity?: number }>
    first_mile: MoneyPart | null
    handling: MoneyPart | null
    last_mile: MoneyPart | null
    shipment_total: string | null
    shipment_total_currency: string | null
    state: string
    missing_components: string[]
}

type CostLine = {
    cost_type: string
    label: string
    amount: string | null
    currency: string | null
    state: string
    formula: string
    provenance: string | null
    status: string | null
    source_reference: string | null
    source_at: string | null
    blocker: string | null
}

type Explanation = {
    order: {
        id: number
        erp_order_id: string | null
        external_order_number: string | null
        order_date: string | null
        source_type: string
        currency: string
        shipping_country: string | null
        product_count: number
        shipment_count: number
        original_shipment_count: number
        replacement_shipment_count: number
        multi_shipment: boolean
        updated_at: string | null
    }
    summary: Record<string, any>
    product: {
        state: string
        amount: string | null
        currency: string | null
        provenance?: string | null
        source_reference?: string | null
        blocker?: string | null
        lines: Array<Record<string, any>>
    }
    shipments: ShipmentRow[]
    logistics: { state: string; shipment_count: number; confirmed_shipment_count: number }
    replacement_loss: {
        state: string
        amount: string | null
        currency: string | null
        replacement_count: number
        breakdown: Array<{
            replacement_order_id: number
            erp_order_id: string | null
            suffix: string | null
            reason: string | null
            warehouse_code: string | null
            warehouse_name: string | null
            tracking_number: string | null
            shipped_units: number
            replacement_total: string
            currency: string
            components: Record<string, any>
        }>
        blocker: string | null
        double_count_guard: string | null
    }
    other_costs: CostLine[]
    formula: CostLine[]
}

const props = defineProps<{ explanation: Explanation }>()
const e = props.explanation
const money = (amount: string | null | undefined, currency: string | null | undefined) => amount === null || amount === undefined ? '—' : `${currency ?? e.order.currency} ${amount}`
const pct = (value: string | null | undefined) => value === null || value === undefined ? '—' : `${(Number(value) * 100).toFixed(1)}%`
const stateLabel = (state: string) => ({ confirmed: '已确认', estimated: '估算中', incomplete: '不完整', missing: '缺失', blocked: '受阻', review: '待复核' }[state] ?? '待确认')
const financeStatusLabel = (status: string | null | undefined) => ({ confirmed: '已确认', completed: '已完成', incomplete: '资料不完整', estimated: '含估算', unposted: '未核算', review: '待复核' }[String(status ?? 'unposted').toLowerCase()] ?? '待确认')
const missingComponentLabel = (name: string) => ({ 'First Mile': '头程费', Handling: '操作费', 'Last Mile': '尾程费' }[name] ?? name)
const costLabel = (type: string) => ({ product_cost: '产品成本', warehouse_cost: '仓储成本', first_mile_cost: '头程费', overseas_handling_cost: '海外仓操作费', last_mile_cost: '尾程物流费', payment_fee: '支付手续费', kol_commission: '网红佣金', refund_replacement_loss: '退款 / 补发损失', customs_tax: '关税', other_variable_cost: '其他变动成本' }[type] ?? '其他成本')
const costFormula = (type: string) => ({ product_cost: 'Σ（单件货本成本 × 主订单商品数量）', warehouse_cost: 'Σ 适用于主订单的仓储成本分摊', first_mile_cost: 'Σ 海外仓发货数量 × USD 1；中国直发 = 0', overseas_handling_cost: 'Σ 海外仓发货数量 × USD 1；中国直发 = 0', last_mile_cost: 'Σ 每个发货单的实际承运商 / 国际小包账单', payment_fee: '平台订单总额 × 3.5%', kol_commission: 'Σ 符合条件的 AffiliateWP 网红佣金', refund_replacement_loss: 'Σ 售后补发新增货本 + 仓库费 + 头程/操作费 + 尾程费；不重复计入原始履约', customs_tax: '当前规则：关税 = 0（后续可扩展）', other_variable_cost: '已确认的其他变动成本' }[type] ?? '以已确认成本证据为准')
const blockerLabel = (code: string | null | undefined) => ({ confirmed_product_cost_source_incomplete: '货本成本来源不完整', warehouse_storage_allocation_missing: '缺少仓储成本分摊证据', referral_snapshot_missing: '缺少网红佣金快照', carrier_billing_missing_on_shipment: '发货单缺少实际尾程账单', replacement_shipment_not_dispatched: '补发履约尚未实际发出', replacement_confirmed_product_cost_required: '补发商品缺少已确认货本', replacement_warehouse_cost_allocation_missing: '补发履约缺少仓库账单分摊', replacement_last_mile_billing_missing: '补发履约缺少实际尾程账单', stronger_or_different_existing_fact: '已有人工或更高优先级成本，需人工复核', no_eligible_referrals: '没有可计入的网红佣金', kol_commission_fx_snapshot_unavailable: '网红佣金汇率快照不可用' }[code ?? ''] ?? (code ? '系统证据条件未满足' : '缺少必要证据'))
const provenanceLabel = (value: string | null | undefined) => ({ confirmed_manual: '人工确认', provider_import: '平台导入', allocated_actual: '实际分摊', estimated_reference: '估算参考', confirmed: '已确认', estimated: '估算' }[value ?? ''] ?? (value ? '系统来源' : '—'))
const sourceTypeLabel = (value: string) => ({ woocommerce: 'WooCommerce', tiktok_shop: 'TikTok Shop', manual: '人工录入', historical_import: '历史导入' }[value] ?? value)
const countryLabel = (value: string | null) => ({ US: '美国', CA: '加拿大', GB: '英国', UK: '英国', DE: '德国', FR: '法国', AU: '澳大利亚', MX: '墨西哥', CL: '智利', AR: '阿根廷', UA: '乌克兰', RU: '俄罗斯', TH: '泰国', ID: '印度尼西亚', VN: '越南', IN: '印度', PH: '菲律宾', SG: '新加坡', JP: '日本', KR: '韩国' }[String(value ?? '').toUpperCase()] ?? value ?? '—')
const warehouseLabel = (value: string | null | undefined) => ({ FACTORY: '中国工厂', 'US-LA': '美西仓（US-LA）', 'US-NY': '美东纽约仓（US-NY）', 'US-MOREPLUS': '美西 MorePlus 仓', 'US-SM': '美西世迈仓', 'DE-TIANYUN': '德国天运仓', CA: '加拿大仓' }[String(value ?? '').toUpperCase()] ?? value ?? '—')
const totalLogistics = e.formula.filter(line => ['first_mile_cost', 'overseas_handling_cost', 'last_mile_cost'].includes(line.cost_type))
const formulaSymbol = (index: number) => index === 0 ? '' : '+'

// P8.2 maturity helpers
const s = e.summary
const ageDays: number | null = s.age_days ?? null
const maturityState: string | null = s.maturity_state ?? null
const reconciliationDue: boolean = s.reconciliation_due ?? false
const estimateAllowed: boolean = s.estimate_allowed ?? true
const costCompletionPct: number = s.cost_completion_percent ?? 0
const unresolvedEstimateCount: number = s.unresolved_estimate_count ?? 0
const hasEstimatedCosts: boolean = (s.estimated_cost_count ?? 0) > 0
const show30dBanner: boolean = reconciliationDue && hasEstimatedCosts
const show45dBanner: boolean = (ageDays ?? 0) > 45 && hasEstimatedCosts
const isEstimatedProfit: boolean = s.canonical_final_ready !== true && (s.canonical_estimated_ready === true)
const settlementStatus: string = s.revenue_status ?? 'incomplete'
const maturityLabel = (state: string | null) => ({ fresh_estimate_allowed: '新订单 · 估算允许', estimate_allowed: '估算允许（30天内）', actual_reconciliation_due: '待真实成本结算（30–45天）', actual_cost_overdue: '成本已逾期（超过45天）' }[state ?? ''] ?? '—')
const dataStatusLabel = () => {
    if (s.canonical_final_ready) return '最终确认'
    if (s.canonical_estimated_ready) return '含估算'
    return '数据不完整'
}
const settlementStatusLabel = (val: string) => ({ confirmed: '已确认', incomplete: '未完成', not_confirmed: '未确认' }[val] ?? val)
</script>

<template>
    <Head :title="`${e.order.erp_order_id ?? '订单'} · 成本与利润`" />
    <AuthenticatedLayout title="订单利润" :breadcrumbs="['BYL ERP', '订单', '财务']">
        <section class="profit-page" :class="{ 'estimated-page': isEstimatedProfit, 'overdue-page': show45dBanner }">
            <div v-if="isEstimatedProfit" class="status-hero" :class="show45dBanner ? 'status-hero-overdue' : 'status-hero-estimated'">
                <div class="status-mark">{{ show45dBanner ? '!' : '≈' }}</div>
                <div class="status-copy">
                    <strong>{{ show45dBanner ? '预估利润 · 实际成本已逾期' : '预估利润 · 非最终数据' }}</strong>
                    <span v-if="show45dBanner">订单已超过 45 天，当前利润仍包含估算成本，必须等待真实账单替换后才能成为最终利润。</span>
                    <span v-else>当前利润包含 {{ s.estimated_cost_count ?? 0 }} 项估算成本，仅用于经营判断；真实账单到账后会自动替换。</span>
                </div>
                <span class="status-chip">ESTIMATED</span>
            </div>

            <header class="order-header" :class="{ 'order-header-estimated': isEstimatedProfit }">
                <div>
                    <p class="crumb"><Link href="/accounting/order-costs">订单</Link> / 订单详情 / 财务</p>
                    <div class="title-row">
                        <h1>订单 #{{ e.order.erp_order_id ?? e.order.id }}</h1>
                        <span class="badge">客户订单</span>
                        <span class="badge blue">{{ e.order.shipment_count }} 个发货单</span>
                    </div>
                    <p class="meta">创建日期：{{ e.order.order_date ?? '—' }} · 来源平台：{{ sourceTypeLabel(e.order.source_type) }} · 收货国家：{{ countryLabel(e.order.shipping_country) }}</p>
                    <p class="submeta">{{ e.order.product_count }} 个商品 · {{ e.order.original_shipment_count }} 个原始履约<span v-if="e.order.replacement_shipment_count"> · {{ e.order.replacement_shipment_count }} 个售后补发</span> · {{ e.order.multi_shipment ? '多履约' : '单仓履约' }}</p>
                </div>
                <div class="finance-status" :class="[`state-${String(e.summary.finance_status ?? '').toLowerCase()}`, { 'finance-status-estimated': isEstimatedProfit }]">
                    <strong>{{ isEstimatedProfit ? '预估利润 · 非最终' : financeStatusLabel(e.summary.finance_status) }}</strong>
                    <span>{{ isEstimatedProfit ? `含 ${s.estimated_cost_count ?? 0} 项估算成本 · 等待真实账单替换` : (e.summary.canonical_final_ready ? '所有必要成本证据均已确认' : '必要成本证据仍不完整') }}</span>
                </div>
            </header>

            <nav class="tabs"><span>概览</span><span>商品（{{ e.order.product_count }}）</span><span>发货单（{{ e.order.shipment_count }}）</span><b>成本与利润</b><span>时间线</span><span>证据</span></nav>

            <!-- P8.2 KPI strip -->
            <section class="kpi-strip">
                <div class="kpi-item">
                    <span class="kpi-label">成本完整度</span>
                    <strong class="kpi-val">{{ costCompletionPct }}%</strong>
                    <small>{{ s.confirmed_cost_count ?? 0 }} 已确认 · {{ s.estimated_cost_count ?? 0 }} 估算 · {{ s.fallback_estimated_cost_count ?? 0 }} fallback · {{ s.missing_required_cost_count ?? 0 }} 项待真实证据</small>
                </div>
                <div class="kpi-item" :class="{ 'kpi-estimated': isEstimatedProfit }">
                    <span class="kpi-label">数据状态</span>
                    <strong class="kpi-val" :class="isEstimatedProfit ? 'amber' : ''">{{ isEstimatedProfit ? 'ESTIMATED · 预估' : dataStatusLabel() }}</strong>
                    <small>{{ isEstimatedProfit ? '非最终利润 · 等待真实成本' : (s.canonical_final_ready ? '所有成本已确认' : '数据待补充') }}</small>
                </div>
                <div class="kpi-item">
                    <span class="kpi-label">结算状态</span>
                    <strong class="kpi-val">{{ settlementStatusLabel(settlementStatus) }}</strong>
                    <small>收入来源已{{ settlementStatus === 'confirmed' ? '核实' : '待核实' }}</small>
                </div>
                <div class="kpi-item" v-if="ageDays !== null">
                    <span class="kpi-label">订单账龄</span>
                    <strong class="kpi-val" :class="reconciliationDue ? 'amber' : ''">{{ ageDays }} 天</strong>
                    <small>{{ maturityLabel(maturityState) }}</small>
                </div>
            </section>

            <!-- P8.2 overdue banners -->
            <div v-if="show45dBanner" class="overdue-banner overdue-red">
                ⚠ 超过 45 天 · 成本已严重逾期 · 含 {{ unresolvedEstimateCount }} 项未结算估算成本，请尽快补录真实账单
            </div>
            <div v-else-if="show30dBanner" class="overdue-banner overdue-amber">
                ⏳ 30+ 天订单 · 待真实成本结算 · 含 {{ unresolvedEstimateCount }} 项估算成本（真实月度账单到账后，系统将自动替换为实际金额）
            </div>

            <section class="summary-panel" :class="{ 'summary-estimated': isEstimatedProfit }">
                <h2>{{ isEstimatedProfit ? '订单利润汇总 · 预估模式' : '订单利润汇总' }} <span v-if="isEstimatedProfit" class="summary-estimated-tag">非最终</span></h2>
                <div class="summary-grid">
                    <article><span>净收入</span><strong>{{ money(e.summary.net_revenue, e.order.currency) }}</strong></article>
                    <em>−</em>
                    <article class="danger"><span>{{ isEstimatedProfit ? '预估总成本' : '最终确认成本' }}</span><strong>{{ money(isEstimatedProfit ? e.summary.canonical_estimated_total_cost : e.summary.canonical_known_cost, e.order.currency) }}</strong></article>
                    <em>=</em>
                    <article :class="{ 'estimated-summary-card': isEstimatedProfit }"><span>{{ isEstimatedProfit ? '预估贡献利润' : '贡献利润' }}</span><strong>{{ money(isEstimatedProfit ? e.summary.canonical_estimated_profit : e.summary.canonical_contribution_profit, e.order.currency) }}</strong><small v-if="isEstimatedProfit">含估算成本 · 非最终</small></article>
                    <article :class="{ 'estimated-summary-card': isEstimatedProfit }"><span>{{ isEstimatedProfit ? '预估利润率' : '利润率' }}</span><strong>{{ pct(isEstimatedProfit ? e.summary.canonical_estimated_margin : e.summary.canonical_margin) }}</strong><small v-if="isEstimatedProfit">仅供经营判断</small></article>
                    <article class="formula-card"><span>计算公式</span><strong>{{ isEstimatedProfit ? '净收入 − 预估总成本' : '净收入 − 最终确认成本' }}</strong><small>{{ isEstimatedProfit ? '缺失项使用 projection-only fallback；不会写入账本。' : '10/10 成本均已真实确认。' }}</small></article>
                </div>
            </section>

            <section class="cost-section">
                <div class="section-head"><h2>1. 产品成本 <small>（主订单持有，包含全部商品）</small></h2><strong>{{ money(e.product.amount, e.product.currency) }}</strong></div>
                <p class="rule-note">货本：只在主订单计算一次，包含所有商品；拆分为多个发货单后不会重复计算。</p>
                <div class="table-wrap"><table><thead><tr><th>#</th><th>商品</th><th>SKU</th><th>单件成本</th><th>数量</th><th>小计</th><th>成本来源</th><th>状态</th></tr></thead>
                    <tbody>
                        <tr v-for="(line, index) in e.product.lines" :key="line.item_id ?? index">
                            <td>{{ index + 1 }}</td><td>{{ line.product_name ?? '—' }}</td><td>{{ line.sku ?? '—' }}</td>
                            <td>{{ money(line.unit_cost, line.currency) }}</td><td>{{ line.quantity }}</td><td>{{ money(line.subtotal, line.currency) }}</td>
                            <td><span>{{ line.source_key ?? line.source_reference ?? '—' }}</span><small>{{ line.effective_from ?? '' }}</small></td>
                            <td><span class="state" :class="`state-${e.product.state}`">{{ stateLabel(e.product.state) }}</span></td>
                        </tr>
                        <tr v-if="!e.product.lines.length"><td colspan="8" class="empty">产品成本证据不可用：{{ blockerLabel(e.product.blocker) }}<small v-if="e.product.blocker">系统代码：{{ e.product.blocker }}</small></td></tr>
                    </tbody>
                    <tfoot><tr><th colspan="5">产品成本合计</th><th>{{ money(e.product.amount, e.product.currency) }}</th><th colspan="2">仅计入主订单 · 子发货单不重复计费</th></tr></tfoot>
                </table></div>
            </section>

            <section class="cost-section">
                <div class="section-head"><h2>2. 物流成本 <small>（按发货单核算，汇总到主订单）</small></h2></div>
                <p class="rule-note">物流成本：每个发货单独立核算，最后汇总到主订单。海外仓头程费 / 操作费按实际发货数量 × USD 1；中国直发两项均为 0。</p>
                <div class="table-wrap"><table><thead><tr><th>发货单</th><th>仓库 / 发货地</th><th>商品 / 数量</th><th>头程费</th><th>操作费</th><th>尾程费</th><th>本票物流成本</th><th>状态</th></tr></thead>
                    <tbody>
                        <tr v-for="row in e.shipments" :key="row.shipment_id">
                            <td><b>#{{ row.shipment_id }}</b><small>{{ row.tracking_number ?? '暂无物流单号' }}</small></td>
                            <td>{{ warehouseLabel(row.warehouse_code ?? row.warehouse_name ?? row.warehouse_slug) }}</td>
                            <td><span v-for="(item, idx) in row.items" :key="idx" class="item-chip">{{ item.sku ?? 'SKU' }} × {{ item.quantity ?? 0 }}</span><small>发货总件数：{{ row.shipped_units ?? '—' }}</small></td>
                            <td>{{ money(row.first_mile?.amount, row.first_mile?.currency) }}<small v-if="row.first_mile?.source_currency && row.first_mile.source_currency !== row.first_mile.currency">原币金额 {{ money(row.first_mile.source_amount, row.first_mile.source_currency) }}</small></td>
                            <td>{{ money(row.handling?.amount, row.handling?.currency) }}<small v-if="row.handling?.source_currency && row.handling.source_currency !== row.handling.currency">原币金额 {{ money(row.handling.source_amount, row.handling.source_currency) }}</small></td>
                            <td :class="{ 'estimated-cell': row.last_mile?.state === 'estimated' }">{{ money(row.last_mile?.amount, row.last_mile?.currency) }}<span v-if="row.last_mile?.state === 'estimated'" class="inline-estimated-tag">估算</span><small>{{ row.carrier ?? '待补承运商账单' }}</small></td>
                            <td><strong>{{ money(row.shipment_total, row.shipment_total_currency) }}</strong></td>
                            <td><span class="state" :class="`state-${row.state}`">{{ stateLabel(row.state) }}</span><small v-if="row.missing_components?.length">缺少：{{ row.missing_components.map(missingComponentLabel).join(' / ') }}</small></td>
                        </tr>
                        <tr v-if="!e.shipments.length"><td colspan="8" class="empty">未找到可核算的发货单成本证据。</td></tr>
                    </tbody>
                    <tfoot><tr><th colspan="3">主订单物流汇总</th><th v-for="line in totalLogistics" :key="line.cost_type">{{ money(line.amount, line.currency) }}</th><th>Σ 各发货单成本组成</th><th><span class="state" :class="`state-${e.logistics.state}`">{{ stateLabel(e.logistics.state) }}</span><small>{{ e.logistics.confirmed_shipment_count }}/{{ e.logistics.shipment_count }} 个发货单已完整</small></th></tr></tfoot>
                </table></div>
            </section>

            <section class="cost-section replacement-loss-section">
                <div class="section-head"><h2>3. 售后补发损失 <small>（R1 / R2… 独立增量成本，归回父订单）</small></h2><strong>{{ money(e.replacement_loss.amount, e.replacement_loss.currency) }}</strong></div>
                <p class="rule-note">补发不会增加销售收入；只汇总本次补发新增的商品货本、仓库成本、头程/操作费和尾程费。原始 A/B/C 物流成本不会再次计入。</p>
                <div v-if="e.replacement_loss.breakdown.length" class="table-wrap"><table><thead><tr><th>补发履约</th><th>原因 / 仓库</th><th>商品成本</th><th>头程费</th><th>操作费</th><th>仓库费</th><th>尾程费</th><th>补发合计</th></tr></thead>
                    <tbody><tr v-for="row in e.replacement_loss.breakdown" :key="row.replacement_order_id">
                        <td><b>{{ row.suffix ?? 'R' }} · {{ row.erp_order_id ?? row.replacement_order_id }}</b><small>{{ row.tracking_number ?? '暂无 Tracking' }}</small></td>
                        <td>{{ row.reason ?? '售后补发' }}<small>{{ warehouseLabel(row.warehouse_code ?? row.warehouse_name) }} · {{ row.shipped_units }} 件</small></td>
                        <td>{{ money(row.components.product_cost?.amount, row.components.product_cost?.currency) }}</td>
                        <td>{{ money(row.components.first_mile_cost?.amount, row.components.first_mile_cost?.currency) }}</td>
                        <td>{{ money(row.components.overseas_handling_cost?.amount, row.components.overseas_handling_cost?.currency) }}</td>
                        <td>{{ money(row.components.warehouse_cost?.amount, row.components.warehouse_cost?.currency) }}</td>
                        <td>{{ money(row.components.last_mile_cost?.amount, row.components.last_mile_cost?.currency) }}</td>
                        <td><strong>{{ money(row.replacement_total, row.currency) }}</strong></td>
                    </tr></tbody>
                    <tfoot><tr><th colspan="7">补发损失合计</th><th>{{ money(e.replacement_loss.amount, e.replacement_loss.currency) }}</th></tr></tfoot>
                </table></div>
                <div v-else class="replacement-empty" :class="`state-${e.replacement_loss.state}`">{{ e.replacement_loss.replacement_count ? blockerLabel(e.replacement_loss.blocker) : '当前订单没有售后补发履约。' }}</div>
            </section>

            <section class="cost-section">
                <div class="section-head"><h2>4. 其他订单级成本</h2></div>
                <p class="rule-note">订单级成本只在主订单计算一次；不会复制到拆分后的子发货单。</p>
                <div class="table-wrap"><table><thead><tr><th>成本项</th><th>计算依据</th><th>金额</th><th>状态</th><th>数据来源</th><th>证据 / 来源</th></tr></thead>
                    <tbody><tr v-for="line in e.other_costs" :key="line.cost_type">
                        <td><b>{{ costLabel(line.cost_type) }}</b></td><td>{{ costFormula(line.cost_type) }}</td><td>{{ money(line.amount, line.currency) }}</td>
                        <td><span class="state" :class="`state-${line.state}`">{{ stateLabel(line.state) }}</span><small v-if="line.blocker">{{ blockerLabel(line.blocker) }}</small><small v-if="line.blocker" class="tech-note">系统代码：{{ line.blocker }}</small></td>
                        <td>{{ provenanceLabel(line.provenance) }}<small v-if="line.provenance" class="tech-note">{{ line.provenance }}</small></td><td><code>{{ line.source_reference ?? '—' }}</code><small>{{ line.source_at ?? '' }}</small></td>
                    </tr></tbody>
                </table></div>
            </section>

            <section class="final-panel">
                <div>
                    <h2>5. 最终计算</h2>
                    <p>最终计算公式直接来自系统已确认的成本证据与核算预览；证据不完整时不会确认最终利润。</p>
                    <div class="formula-line">
                        <template v-for="(line, index) in e.formula" :key="line.cost_type">
                            <span class="operator">{{ formulaSymbol(index) }}</span>
                            <article :class="{ incomplete: line.amount === null, 'estimated-cost-card': line.state === 'estimated' }"><strong>{{ money(line.amount, line.currency) }}</strong><small>{{ costLabel(line.cost_type) }}</small><em v-if="line.state === 'estimated'" class="formula-estimated-tag">估算</em></article>
                        </template>
                        <span class="operator">=</span>
                        <article class="total"><strong>{{ money(isEstimatedProfit ? e.summary.canonical_estimated_total_cost : e.summary.canonical_known_cost, e.order.currency) }}</strong><small>{{ isEstimatedProfit ? '预估总成本' : '最终确认成本' }}</small></article>
                    </div>
                </div>
                <aside class="profit-box" :class="{ pending: !e.summary.canonical_final_ready, estimated: isEstimatedProfit }">
                    <span v-if="e.summary.canonical_final_ready">最终利润</span>
                    <span v-else-if="isEstimatedProfit">预估利润 <em class="est-tag">估算</em></span>
                    <span v-else>利润计算</span>
                    <strong v-if="e.summary.canonical_final_ready">{{ money(e.summary.canonical_contribution_profit, e.order.currency) }}</strong>
                    <strong v-else-if="isEstimatedProfit" class="estimated-profit">{{ money(e.summary.canonical_estimated_profit, e.order.currency) }}</strong>
                    <strong v-else>—</strong>
                    <p v-if="e.summary.canonical_final_ready">{{ money(e.summary.net_revenue, e.order.currency) }} − {{ money(e.summary.canonical_known_cost, e.order.currency) }}</p>
                    <p v-else-if="isEstimatedProfit">净收入 − 预估总成本（{{ s.confirmed_cost_count ?? 0 }} 项已确认 + {{ s.estimated_cost_count ?? 0 }} 项估算）</p>
                    <b v-if="e.summary.canonical_final_ready">利润率 = {{ pct(e.summary.canonical_margin) }}</b>
                    <b v-else-if="isEstimatedProfit">预估利润率 = {{ pct(e.summary.canonical_estimated_margin) }}</b>
                    <small v-if="isEstimatedProfit">预估置信度：{{ s.projection_confidence ?? 'low' }}。真实成本到账后逐项替换，10/10 确认后自动升级为最终利润。</small>
                    <small v-else-if="!e.summary.canonical_final_ready">必要成本证据不完整，最终利润暂不确认。</small>
                </aside>
            </section>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.profit-page { display:grid; gap:14px; color:#18282d; }
.order-header { display:flex; justify-content:space-between; gap:24px; padding:6px 2px 2px; }
.crumb { margin:0 0 8px; color:#78878c; font-size:12px; }.crumb a{color:#536a72;text-decoration:none}
.title-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }.title-row h1{margin:0;font-size:30px;letter-spacing:-.025em}
.badge { padding:5px 9px; border-radius:6px; background:#e6f6ec; color:#1f7653; font-size:12px; font-weight:700; }.badge.blue{background:#e8f2ff;color:#2563b8}
.meta,.submeta { margin:7px 0 0; color:#718087; font-size:12px; }.submeta{font-style:italic}
.finance-status { min-width:280px; align-self:center; padding:14px 16px; border:1px solid #cfe7d8; border-radius:8px; background:#effaf3; display:grid; gap:3px; }
.finance-status strong{font-size:17px;color:#18744f}.finance-status span{font-size:11px;color:#61736a}.finance-status.state-incomplete,.finance-status.state-unposted{background:#fff8eb;border-color:#edd9ad}.finance-status.state-incomplete strong,.finance-status.state-unposted strong{color:#9a6500}
.tabs { display:flex; gap:26px; align-items:center; border-bottom:1px solid #dfe6e8; padding:0 12px; color:#607078; font-size:12px; }.tabs>*{padding:11px 2px}.tabs b{color:#1b63d8;border-bottom:2px solid #1b63d8}
.summary-panel { border:1px solid #cfe3f3; background:#eff7ff; border-radius:8px; padding:13px; }.summary-panel h2,.cost-section h2,.final-panel h2{margin:0;font-size:16px}.summary-grid{display:grid;grid-template-columns:1.1fr auto 1.1fr auto 1.1fr 1fr 1.7fr;gap:10px;align-items:center;margin-top:12px}
.summary-grid article{min-height:78px;background:#fff;border:1px solid #dce6ea;border-radius:7px;padding:12px;display:grid;gap:4px;align-content:center}.summary-grid article span{font-size:11px;color:#6b7b82}.summary-grid article strong{font-size:19px}.summary-grid article.danger strong{color:#d12e2e}.summary-grid em{font-style:normal;font-weight:800;color:#64757a}.formula-card{border-color:#84b6f6!important}.formula-card strong{font-size:13px!important;color:#235fb3}.formula-card small{color:#60758a}
.cost-section { border:1px solid #dfe6e8; border-radius:8px; padding:12px; background:#fff; }.replacement-loss-section{border-color:#efd9b8;background:#fffdfa}.replacement-empty{padding:14px;border:1px dashed #d8e0e3;border-radius:6px;color:#718087;background:#fafcfc}.replacement-empty.state-blocked{border-color:#edc7c3;background:#fff5f4;color:#9b3b34}.section-head{display:flex;justify-content:space-between;gap:18px;align-items:center}.section-head h2 small{font-weight:500;color:#596b72}.rule-note{margin:7px 0 10px;color:#c0392b;font-size:12px}.table-wrap{overflow:auto;border:1px solid #e1e7e9;border-radius:6px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:9px 10px;border-right:1px solid #e5eaec;border-bottom:1px solid #e5eaec;text-align:left;vertical-align:middle}th:last-child,td:last-child{border-right:0}thead th,tfoot th{background:#f4f7f8;font-weight:700;white-space:nowrap}tfoot th{border-bottom:0}td small,td span+small{display:block;color:#849198;margin-top:3px}.item-chip{display:block;white-space:nowrap}.empty{text-align:center;color:#87959a;padding:18px}.state{display:inline-flex;padding:3px 7px;border-radius:999px;font-weight:700;font-size:10px}.state-confirmed{background:#e5f6eb;color:#18734e}.state-estimated,.state-incomplete{background:#fff2cf;color:#956100}.state-missing,.state-blocked{background:#feeceb;color:#b83832}.state-review{background:#f3eafa;color:#7a3ea1}code{font-size:10px;color:#52656d;word-break:break-all}

.final-panel{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:14px;border:1px solid #dfe6e8;border-radius:8px;padding:14px;background:#fff}.final-panel>div>p{margin:5px 0 12px;color:#6d7d84;font-size:11px}.formula-line{display:flex;align-items:stretch;gap:7px;overflow:auto}.formula-line article{min-width:108px;border:1px solid #dfe6e8;border-radius:7px;padding:9px;display:grid;gap:3px;background:#fafcfc}.formula-line article strong{font-size:14px}.formula-line article small{font-size:10px;color:#75868d}.formula-line article.incomplete{border-style:dashed;opacity:.65}.formula-line article.total{background:#fff1f1;border-color:#f2c7c7;color:#b42323}.operator{align-self:center;font-weight:800;color:#61747b}.profit-box{border:1px solid #bfe3ce;border-radius:8px;background:#eefaf3;padding:14px;display:grid;gap:7px;align-content:center}.profit-box>span{font-size:12px;color:#567467}.profit-box>strong{font-size:24px;color:#168052}.profit-box p{margin:0;color:#2d6e54}.profit-box b{color:#137248}.profit-box small{color:#8a6500}.profit-box.pending{background:#fff9eb;border-color:#ead7a8}.profit-box.pending>strong{color:#9a6500}.profit-box.estimated{background:#fff8e6;border-color:#f0c040}.profit-box.estimated>strong,.estimated-profit{color:#9a6000}.est-tag{background:#fff2cc;color:#806000;border:1px solid #e8c300;border-radius:4px;font-size:10px;padding:1px 5px;font-style:normal;vertical-align:middle}

/* P8.2 KPI strip */
.kpi-strip{display:flex;gap:12px;flex-wrap:wrap;border:1px solid #dfe6e8;border-radius:8px;padding:10px 14px;background:#f8fafc}
.kpi-item{display:grid;gap:2px;min-width:140px;flex:1}
.kpi-label{font-size:10px;color:#7a8a90;text-transform:uppercase;letter-spacing:.04em}
.kpi-val{font-size:18px;font-weight:700;color:#18282d}
.kpi-val.amber{color:#9a6000}
.kpi-item small{font-size:10px;color:#8a9aa0}

/* P8.2 page-level estimated state */
.status-hero{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:14px;border-radius:10px;padding:14px 16px;border:1px solid;box-shadow:0 8px 22px rgba(112,83,0,.08)}
.status-hero-estimated{background:#fff3c7;border-color:#d9ad00;color:#6f4e00}
.status-hero-overdue{background:#fff0ef;border-color:#e38a85;color:#8d211c;box-shadow:0 8px 22px rgba(142,33,28,.08)}
.status-mark{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;font-size:24px;font-weight:900;background:rgba(255,255,255,.72);border:1px solid currentColor}
.status-copy{display:grid;gap:3px}.status-copy strong{font-size:17px;letter-spacing:-.01em}.status-copy span{font-size:12px;line-height:1.55;opacity:.9}
.status-chip{font-size:11px;font-weight:800;letter-spacing:.08em;padding:7px 10px;border-radius:999px;background:#1f2937;color:#fff}
.order-header-estimated{border-left:4px solid #d9ad00;padding-left:14px}
.finance-status-estimated{background:#fff3c7!important;border-color:#d9ad00!important;box-shadow:inset 4px 0 0 #d9ad00}.finance-status-estimated strong{color:#7a5200!important}.finance-status-estimated span{color:#795f1b!important}
.kpi-estimated{background:#fff3c7;border:1px solid #dfb600;border-radius:8px;padding:8px 10px}.kpi-estimated .kpi-label,.kpi-estimated small{color:#80661b}.kpi-estimated .kpi-val{color:#7a5200}
.summary-panel.summary-estimated{background:#fff9e8;border-color:#d9ad00;box-shadow:inset 4px 0 0 #d9ad00}.summary-estimated-tag{display:inline-flex;margin-left:8px;padding:3px 7px;border-radius:999px;background:#fff0ad;border:1px solid #d9ad00;color:#7a5200;font-size:10px;vertical-align:middle}
.summary-grid article.estimated-summary-card{background:#fff3c7;border-color:#d9ad00;box-shadow:inset 0 0 0 1px rgba(217,173,0,.08)}.summary-grid article.estimated-summary-card span,.summary-grid article.estimated-summary-card small{color:#795f1b}.summary-grid article.estimated-summary-card strong{color:#7a5200;font-size:21px}
.estimated-cell{background:#fff8df!important;box-shadow:inset 4px 0 0 #d9ad00}.inline-estimated-tag{display:inline-flex;margin-left:6px;padding:2px 5px;border-radius:4px;background:#fff0ad;border:1px solid #d9ad00;color:#7a5200;font-size:9px;font-weight:800}
.formula-line article.estimated-cost-card{background:#fff8df;border-color:#d9ad00;box-shadow:inset 0 0 0 1px rgba(217,173,0,.08)}.formula-estimated-tag{font-style:normal;font-size:9px;font-weight:800;color:#7a5200;background:#fff0ad;border:1px solid #d9ad00;border-radius:4px;padding:2px 5px;width:max-content}
.profit-box.estimated{background:#fff3c7;border:2px solid #d9ad00;box-shadow:0 10px 24px rgba(112,83,0,.12),inset 5px 0 0 #d9ad00;padding:16px}.profit-box.estimated>span{font-weight:800;color:#6f4e00}.profit-box.estimated>strong,.profit-box.estimated .estimated-profit{font-size:28px;color:#7a5200}.profit-box.estimated p,.profit-box.estimated b{color:#6f4e00}.profit-box.estimated small{color:#80661b}.profit-box.estimated .est-tag{background:#1f2937;border-color:#1f2937;color:#fff;letter-spacing:.05em}

/* P8.2 overdue banners */
.overdue-banner{border-radius:8px;padding:10px 14px;font-size:12px;font-weight:600;margin:0}
.overdue-amber{background:#fff8e6;border:1px solid #f0c040;color:#7d5300}
.overdue-red{background:#fff0ef;border:1px solid #f5a5a2;color:#9a1f1a}

@media(max-width:1180px){.summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.summary-grid em{display:none}.formula-card{grid-column:span 2}.final-panel{grid-template-columns:1fr}.finance-status{min-width:220px}}
@media(max-width:760px){.status-hero{grid-template-columns:36px 1fr}.status-chip{grid-column:2;width:max-content}.order-header{display:grid}.finance-status{min-width:0}.tabs{overflow:auto;gap:18px}.summary-grid{grid-template-columns:1fr 1fr}.formula-card{grid-column:1/-1}.final-panel{padding:10px}.title-row h1{font-size:24px}.kpi-strip{gap:8px}.kpi-item{min-width:120px}}
</style>
