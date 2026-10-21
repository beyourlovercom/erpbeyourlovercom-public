<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type DashboardWorkspace = { role:string; label:string; mode:'boss'|'work'; eyebrow:string; title:string; description:string }
type DashboardVisibility = { operations:boolean; inventory:boolean; sync:boolean; finance:boolean; procurement:boolean }
type RoleSnapshotCard = { label:string; value:number|string; href:string; helper?:string }
type RoleSnapshot = { kind:string; title:string; description:string; cards:RoleSnapshotCard[]; highlights:string[] }
type StatusMetric = { key:string; label:string; url:string; count:number }
type OperationsSnapshot = Record<'wait_stock'|'awaiting'|'transit'|'exception'|'review'|'completed'|'cancelled', StatusMetric|null>
type InventoryRisk = { stock_alert_rows:number; out_of_stock_rows:number; low_stock_rows:number; quantity_available:number; warehouse_data_gap_count:number; warehouse_total_count:number }
type SyncHealth = { pending:number; retry_due:number; retry_waiting:number; processing:number; failed:number; conflicts:number }
type FinanceTrendRow = { date:string; revenue_usd:string; estimated_cost_usd:string; estimated_profit_usd:string; aggregate_orders:number }
type FinanceSnapshot = {
  date_from:string; date_to:string; eligible_orders:number; revenue_ready_orders:number; estimated_profit_orders:number
  estimated_profit_percent:number; final_profit_orders:number; final_profit_percent:number; fx_excluded_orders:number
  aggregate_orders:number; estimated_revenue_usd:string; estimated_cost_usd:string; estimated_profit_usd:string
  projection_confidence:Record<'high'|'moderate'|'low'|'very_low', number>; trend:FinanceTrendRow[]
}
type ProcurementRow = { id:number; procurement_number:string; status:string; source_order_erp_id:string|null; carrier:string|null; tracking_number:string|null; updated_at:string|null }
type ProcurementProgress = { active:number; counts:Record<'pending'|'purchasing'|'ordered'|'completed'|'cancelled', number>; recent:ProcurementRow[] }

const props = withDefaults(defineProps<{
  workspace:DashboardWorkspace; quickActions?:Array<{label:string;href:string}>; roleSnapshot?:RoleSnapshot|null; visibility:DashboardVisibility; period?:'7d'|'30d'|'90d'|'custom'; dateFrom?:string|null; dateTo?:string|null
  operations?:OperationsSnapshot|null; inventoryRisk?:InventoryRisk|null; syncHealth?:SyncHealth|null
  financeSnapshot?:FinanceSnapshot|null; procurementProgress?:ProcurementProgress|null
}>(), { quickActions:() => [], roleSnapshot:null, period:'7d', dateFrom:null, dateTo:null, operations:null, inventoryRisk:null, syncHealth:null, financeSnapshot:null, procurementProgress:null })

const periodOptions = [{ value:'7d',label:'近 7 天' },{ value:'30d',label:'近 30 天' },{ value:'90d',label:'近 90 天' }] as const
const periodHref = (period:string) => `/dashboard?period=${period}`
const statusMeta = [
  { value:'pending', label:'待采购' }, { value:'purchasing', label:'采购中' }, { value:'ordered', label:'已下单' },
  { value:'completed', label:'已完成' }, { value:'cancelled', label:'已取消' },
] as const
const statusLabel = (value:string) => statusMeta.find(item => item.value === value)?.label || value
const integer = (value:number) => new Intl.NumberFormat('zh-CN').format(value)
const roleValue = (value:number|string) => typeof value === 'number' ? integer(value) : value
const usd = (value:string) => `$${new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value || 0))}`
const percent = (value:number) => `${Number(value).toFixed(value % 1 === 0 ? 0 : 1)}%`
const financeUrl = computed(() => `/accounting/order-costs?date_from=${props.dateFrom || ''}&date_to=${props.dateTo || ''}`)

const chart = computed(() => {
  const rows = props.financeSnapshot?.trend || []
  const values = rows.flatMap(row => [Number(row.revenue_usd), Number(row.estimated_cost_usd), Number(row.estimated_profit_usd)])
  const min = Math.min(0, ...values)
  const max = Math.max(0, ...values)
  const span = max - min || 1
  const point = (value:number, index:number) => {
    const x = rows.length <= 1 ? 50 : (index / (rows.length - 1)) * 100
    const y = 33 - ((value - min) / span) * 30
    return `${x.toFixed(2)},${y.toFixed(2)}`
  }
  const points = (key:'revenue_usd'|'estimated_cost_usd'|'estimated_profit_usd') => rows.map((row,i) => point(Number(row[key]),i)).join(' ')
  const zeroY = 33 - ((0 - min) / span) * 30
  return { rows, revenue:points('revenue_usd'), cost:points('estimated_cost_usd'), profit:points('estimated_profit_usd'), zeroY }
})
</script>

<template>
  <Head title="仪表盘 · BYL ERP" />
  <AuthenticatedLayout title="仪表盘" :breadcrumbs="['BYL ERP', '仪表盘']">
    <section class="dashboard-shell-page">
      <header class="dashboard-hero">
        <div>
          <p class="mvp-kicker">{{ workspace.eyebrow }}</p>
          <h2>{{ workspace.title }}</h2>
          <p>{{ workspace.description }}</p>
        </div>
        <div v-if="visibility.finance" class="dashboard-period" aria-label="经营时间范围">
          <Link v-for="item in periodOptions" :key="item.value" class="period-option" :class="{ active: props.period === item.value }" :href="periodHref(item.value)">{{ item.label }}</Link>
          <Link class="period-option" :class="{ active: props.period === 'custom' }" href="/dashboard?period=custom">自定义</Link>
        </div>
      </header>

      <div v-if="workspace.mode === 'work' && quickActions.length" class="dashboard-role-actions" aria-label="今日快捷入口">
        <span>今日快捷入口</span>
        <Link v-for="action in quickActions" :key="action.href" class="mvp-button" :href="action.href">{{ action.label }}</Link>
      </div>

      <form v-if="visibility.finance && props.period === 'custom'" class="dashboard-custom-period" method="get" action="/dashboard">
        <input type="hidden" name="period" value="custom">
        <label>开始日期<input type="date" name="date_from" :value="props.dateFrom || ''"></label>
        <label>结束日期<input type="date" name="date_to" :value="props.dateTo || ''"></label>
        <button class="mvp-button mvp-button-primary" type="submit">应用时间范围</button>
      </form>

      <nav class="dashboard-section-nav" aria-label="仪表盘分区">
        <a v-if="roleSnapshot" href="#role-workspace">我的工作</a>
        <a v-if="visibility.operations" href="#operations">当前运营</a>
        <a v-if="visibility.inventory" href="#inventory">库存风险</a>
        <a v-if="visibility.sync" href="#sync">同步健康</a>
        <a v-if="visibility.finance" href="#finance">经营财务</a>
        <a v-if="visibility.procurement" href="#procurement">采购</a>
      </nav>

      <section v-if="roleSnapshot" id="role-workspace" class="dashboard-section-shell role-workspace-section">
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">角色工作台</p><h3>{{ roleSnapshot.title }}</h3><p>{{ roleSnapshot.description }}</p></div>
        </div>
        <div class="dashboard-kpi-grid role-workspace-grid">
          <Link v-for="card in roleSnapshot.cards" :key="card.label + card.href" class="dashboard-live-card" :href="card.href">
            <span>{{ card.label }}</span><strong>{{ roleValue(card.value) }}</strong><small>{{ card.helper || '查看明细 →' }}</small>
          </Link>
        </div>
        <div v-if="roleSnapshot.highlights.length" class="role-workspace-highlights">
          <span v-for="item in roleSnapshot.highlights" :key="item">{{ item }}</span>
        </div>
      </section>

      <section v-if="visibility.operations && operations" id="operations" class="dashboard-section-shell">
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">当前运营</p><h3>履约状态</h3><p>实时状态来自 Logistics OS；各状态按业务语义展示，不做错误相加。</p></div>
          <Link class="mvp-button" href="/logistics">进入物流系统</Link>
        </div>
        <Link v-if="operations.wait_stock" class="context-strip" :href="operations.wait_stock.url">
          <span>待分仓</span><strong>{{ integer(operations.wait_stock.count) }}</strong><small>未分仓订单；分仓后进入国内发货或海外仓发货</small>
        </Link>
        <div class="operations-funnel" aria-label="履约状态">
          <Link v-for="item in [operations.awaiting,operations.transit,operations.exception,operations.review].filter(Boolean)" :key="item!.key" class="dashboard-live-card" :class="{ alert: item!.key === 'exception' || item!.key === 'review' }" :href="item!.url">
            <span>{{ item!.label }}</span><strong>{{ integer(item!.count) }}</strong><small>查看明细 →</small>
          </Link>
        </div>
        <div class="operations-secondary">
          <Link v-if="operations.completed" :href="operations.completed.url">已完结 <strong>{{ integer(operations.completed.count) }}</strong></Link>
          <Link v-if="operations.cancelled" :href="operations.cancelled.url">已取消 <strong>{{ integer(operations.cancelled.count) }}</strong></Link>
        </div>
      </section>

      <section v-if="visibility.inventory && inventoryRisk" id="inventory" class="dashboard-section-shell">
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">库存风险</p><h3>库存健康</h3><p>预警口径与 Inventory OS 完全一致。</p></div>
          <Link class="mvp-button" href="/inventory">进入库存系统</Link>
        </div>
        <div class="dashboard-kpi-grid">
          <Link class="dashboard-live-card emphasis" href="/inventory/products?stock_status=alert"><span>库存预警</span><strong>{{ integer(inventoryRisk.stock_alert_rows) }}</strong><small>没货 + 库存紧张</small></Link>
          <Link class="dashboard-live-card" href="/inventory/products?stock_status=out_of_stock"><span>没货</span><strong>{{ integer(inventoryRisk.out_of_stock_rows) }}</strong><small>可用库存 ≤ 1</small></Link>
          <Link class="dashboard-live-card" href="/inventory/products?stock_status=low_stock"><span>库存紧张</span><strong>{{ integer(inventoryRisk.low_stock_rows) }}</strong><small>可用库存 2–9</small></Link>
          <Link class="dashboard-live-card" href="/inventory/list"><span>仓库数据缺口</span><strong>{{ inventoryRisk.warehouse_data_gap_count }}/{{ inventoryRisk.warehouse_total_count }}</strong><small>尚无完整库存基线</small></Link>
        </div>
        <div class="summary-foot">当前可用库存 <strong>{{ integer(inventoryRisk.quantity_available) }}</strong> 件</div>
      </section>

      <section v-if="visibility.sync && syncHealth" id="sync" class="dashboard-section-shell">
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">同步健康</p><h3>网站同步与重试</h3><p>人工待同步与自动 outbox 分开看，不制造重复总数。</p></div>
          <Link class="mvp-button" href="/sync">进入同步系统</Link>
        </div>
        <div class="dashboard-kpi-grid">
          <Link class="dashboard-live-card" href="/sync/orders?status=pending"><span>待同步</span><strong>{{ integer(syncHealth.pending) }}</strong><small>人工同步队列</small></Link>
          <Link class="dashboard-live-card" href="/sync"><span>重试到期</span><strong>{{ integer(syncHealth.retry_due) }}</strong><small>可立即重试</small></Link>
          <Link class="dashboard-live-card" href="/sync"><span>同步失败</span><strong>{{ integer(syncHealth.failed) }}</strong><small>需要处理</small></Link>
          <Link class="dashboard-live-card" href="/sync"><span>冲突</span><strong>{{ integer(syncHealth.conflicts) }}</strong><small>远端证据不一致</small></Link>
        </div>
        <div class="summary-foot">等待重试 {{ integer(syncHealth.retry_waiting) }} · 正在处理 {{ integer(syncHealth.processing) }}</div>
      </section>

      <section v-if="visibility.finance" id="finance" class="dashboard-section-shell">
        <div v-if="!financeSnapshot" class="finance-loading" aria-busy="true">
          <div><p class="mvp-kicker">经营财务</p><h3>正在加载经营趋势</h3><p>运营、库存和同步已先展示；财务区间数据独立加载。</p></div>
          <div class="loading-bars"><span></span><span></span><span></span><span></span></div>
        </div>
        <template v-else>
        <div class="dashboard-section-heading">
          <div><p class="mvp-kicker">经营财务</p><h3>收入与预估利润</h3><p>{{ financeSnapshot.date_from }} 至 {{ financeSnapshot.date_to }} · 所有预估利润均为非最终值。</p></div>
          <Link class="mvp-button" :href="financeUrl">进入订单利润</Link>
        </div>
        <div class="dashboard-kpi-grid finance-kpis">
          <Link class="dashboard-live-card" :href="financeUrl"><span>经营订单</span><strong>{{ integer(financeSnapshot.eligible_orders) }}</strong><small>选定区间客户订单</small></Link>
          <Link class="dashboard-live-card" :href="financeUrl"><span>收入就绪</span><strong>{{ integer(financeSnapshot.revenue_ready_orders) }}</strong><small>可进入利润估算</small></Link>
          <Link class="dashboard-live-card estimated" :href="financeUrl"><span>已有预估利润</span><strong>{{ integer(financeSnapshot.estimated_profit_orders) }}</strong><small>{{ percent(financeSnapshot.estimated_profit_percent) }} · 预估 / 非最终</small></Link>
          <Link class="dashboard-live-card" :href="financeUrl"><span>最终利润覆盖</span><strong>{{ percent(financeSnapshot.final_profit_percent) }}</strong><small>{{ integer(financeSnapshot.final_profit_orders) }} 单已最终确认</small></Link>
        </div>

        <div class="finance-summary-row">
          <div><span>可聚合收入</span><strong>{{ usd(financeSnapshot.estimated_revenue_usd) }}</strong></div>
          <div><span>预估总成本</span><strong>{{ usd(financeSnapshot.estimated_cost_usd) }}</strong><small>预估 · 非最终</small></div>
          <div><span>预估利润</span><strong>{{ usd(financeSnapshot.estimated_profit_usd) }}</strong><small>预估 · 非最终</small></div>
          <div><span>FX 排除</span><strong>{{ integer(financeSnapshot.fx_excluded_orders) }}</strong><small>未纳入 USD 金额</small></div>
        </div>

        <div class="finance-chart-card">
          <div class="chart-heading">
            <div><strong>经营趋势</strong><small>仅聚合 FX 安全订单 · {{ integer(financeSnapshot.aggregate_orders) }} 单</small></div>
            <div class="chart-legend"><span class="revenue">收入</span><span class="cost">预估成本</span><span class="profit">预估利润</span></div>
          </div>
          <svg v-if="chart.rows.length" viewBox="0 0 100 36" preserveAspectRatio="none" role="img" aria-label="经营趋势图">
            <line x1="0" x2="100" :y1="chart.zeroY" :y2="chart.zeroY" class="zero-line" />
            <polyline :points="chart.revenue" class="trend-line revenue-line" />
            <polyline :points="chart.cost" class="trend-line cost-line" />
            <polyline :points="chart.profit" class="trend-line profit-line" />
          </svg>
          <div class="chart-axis"><span>{{ financeSnapshot.date_from }}</span><span>{{ financeSnapshot.date_to }}</span></div>
          <div class="finance-health">置信度：高 {{ financeSnapshot.projection_confidence.high }} · 中 {{ financeSnapshot.projection_confidence.moderate }} · 低 {{ financeSnapshot.projection_confidence.low }} · 很低 {{ financeSnapshot.projection_confidence.very_low }}</div>
        </div>
        </template>
      </section>

      <section v-if="visibility.procurement" id="procurement" class="dashboard-section-shell procurement-section">
        <template v-if="procurementProgress && procurementProgress.active > 0">
          <div class="dashboard-section-heading">
            <div><p class="mvp-kicker">采购</p><h3>采购进度</h3><p>当前存在活跃采购，保留为运营工作区。</p></div>
            <Link class="mvp-button" href="/purchase">查看全部采购</Link>
          </div>
          <div class="dashboard-kpi-grid procurement-stat-grid">
            <Link class="dashboard-live-card" href="/purchase"><span>活跃采购</span><strong>{{ procurementProgress.active }}</strong></Link>
            <Link v-for="item in statusMeta" :key="item.value" class="dashboard-live-card" :href="`/purchase?status=${item.value}`"><span>{{ item.label }}</span><strong>{{ procurementProgress.counts[item.value] }}</strong></Link>
          </div>
          <div class="procurement-recent">
            <Link v-for="row in procurementProgress.recent" :key="row.id" class="procurement-row" :href="`/purchase/orders/${row.id}`">
              <div class="procurement-id"><strong>{{ row.procurement_number }}</strong><span>源订单 {{ row.source_order_erp_id || '—' }}</span></div>
              <span class="procurement-status">{{ statusLabel(row.status) }}</span>
              <span class="procurement-logistics">{{ row.carrier || '暂无物流' }}<template v-if="row.tracking_number"> · {{ row.tracking_number }}</template></span>
            </Link>
          </div>
        </template>
        <Link v-else class="procurement-quiet" href="/purchase">
          <div><p class="mvp-kicker">采购</p><h3>采购系统</h3><p>当前无活跃采购，不占用首页主视线。</p></div>
          <span>进入采购系统 →</span>
        </Link>
      </section>

      <section v-if="!roleSnapshot && !visibility.operations && !visibility.inventory && !visibility.sync && !visibility.finance && !visibility.procurement" class="dashboard-empty">当前账号暂无可展示的业务总览模块。</section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.dashboard-shell-page{display:grid;gap:18px;max-width:1440px;margin:0 auto;color:var(--byl-text)}
.dashboard-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;padding:6px 0 2px}
.dashboard-hero h2{margin:6px 0;font-size:30px;letter-spacing:-.02em}.dashboard-hero>div>p:not(.mvp-kicker){margin:0;max-width:760px;color:var(--byl-text-secondary);line-height:1.7}
.dashboard-period{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.period-option,.dashboard-section-nav a{display:inline-flex;align-items:center;min-height:36px;padding:0 12px;border:1px solid var(--byl-border-control);border-radius:var(--byl-radius-round);background:var(--byl-surface);color:var(--byl-text-secondary);font-size:12px;text-decoration:none}
.period-option.active{border-color:var(--byl-accent);background:var(--byl-status-bg);color:var(--byl-status-ink);font-weight:700}
.dashboard-custom-period{display:flex;align-items:end;gap:10px;padding:14px 16px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-xl);background:var(--byl-surface)}
.dashboard-custom-period label{display:grid;gap:5px;color:var(--byl-text-muted);font-size:11px}.dashboard-custom-period input{min-height:38px;border:1px solid var(--byl-border-control);border-radius:var(--byl-radius-md);padding:0 10px;background:var(--byl-surface);color:var(--byl-text);font:inherit}
.dashboard-section-nav{display:flex;gap:8px;overflow-x:auto;padding:2px 0 4px;scrollbar-width:none}.dashboard-section-nav::-webkit-scrollbar{display:none}.dashboard-section-nav a{white-space:nowrap}.dashboard-section-nav a:hover{border-color:var(--byl-accent);color:var(--byl-accent)}
.dashboard-section-shell{scroll-margin-top:86px;padding:20px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-xl);background:var(--byl-surface);box-shadow:var(--byl-shadow-card)}
.dashboard-section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:16px}.dashboard-section-heading h3,.procurement-quiet h3{margin:5px 0 6px;font-size:20px}.dashboard-section-heading p:not(.mvp-kicker),.procurement-quiet p:not(.mvp-kicker){margin:0;color:var(--byl-text-secondary);font-size:13px}
.context-strip{display:grid;grid-template-columns:auto auto 1fr;align-items:center;gap:12px;margin-bottom:10px;padding:10px 14px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-readonly-bg);color:inherit;text-decoration:none}.context-strip span{font-size:12px;color:var(--byl-text-secondary)}.context-strip strong{font-size:18px}.context-strip small{text-align:right;color:var(--byl-text-muted)}
.operations-funnel{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.dashboard-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.dashboard-live-card{display:block;min-width:0;padding:15px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-readonly-bg);color:inherit;text-decoration:none;transition:border-color .15s ease,transform .15s ease}.dashboard-live-card:hover{border-color:var(--byl-accent);transform:translateY(-1px)}.dashboard-live-card span{display:block;color:var(--byl-text-muted);font-size:11px}.dashboard-live-card strong{display:block;margin-top:8px;font-size:25px;color:var(--byl-text)}.dashboard-live-card small{display:block;margin-top:6px;color:var(--byl-text-muted);font-size:10px}.dashboard-live-card.emphasis,.dashboard-live-card.estimated{background:var(--byl-status-bg)}.dashboard-live-card.alert strong{color:var(--byl-danger,var(--byl-text))}
.operations-secondary{display:flex;justify-content:flex-end;gap:18px;margin-top:12px}.operations-secondary a{color:var(--byl-text-secondary);font-size:12px;text-decoration:none}.operations-secondary strong{margin-left:5px;color:var(--byl-text)}
.summary-foot{margin-top:12px;color:var(--byl-text-muted);font-size:11px}.summary-foot strong{color:var(--byl-text)}
.role-workspace-section{border-color:color-mix(in srgb,var(--byl-accent) 24%,var(--byl-border-subtle));background:color-mix(in srgb,var(--byl-surface) 96%,var(--byl-status-bg))}
.role-workspace-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
.role-workspace-highlights{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.role-workspace-highlights span{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:var(--byl-radius-round);background:var(--byl-readonly-bg);color:var(--byl-text-secondary);font-size:11px}
.finance-loading{display:grid;gap:16px}.finance-loading h3{margin:5px 0 6px;font-size:20px}.finance-loading p:not(.mvp-kicker){margin:0;color:var(--byl-text-secondary);font-size:13px}.loading-bars{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.loading-bars span{height:72px;border-radius:var(--byl-radius-lg);background:var(--byl-readonly-bg);animation:pulse 1.4s ease-in-out infinite}.loading-bars span:nth-child(2){animation-delay:.12s}.loading-bars span:nth-child(3){animation-delay:.24s}.loading-bars span:nth-child(4){animation-delay:.36s}@keyframes pulse{50%{opacity:.45}}
.finance-summary-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}.finance-summary-row>div{padding:14px;border-top:1px solid var(--byl-border-subtle)}.finance-summary-row span,.finance-summary-row small{display:block;color:var(--byl-text-muted);font-size:10px}.finance-summary-row strong{display:block;margin:6px 0 3px;font-size:17px}
.finance-chart-card{margin-top:14px;padding:16px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg)}.chart-heading{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.chart-heading>div:first-child{display:grid;gap:4px}.chart-heading small{color:var(--byl-text-muted);font-size:10px}.chart-legend{display:flex;gap:12px;font-size:10px;color:var(--byl-text-secondary)}.chart-legend span:before{content:'';display:inline-block;width:12px;height:2px;margin-right:5px;vertical-align:middle;background:currentColor}.chart-legend .revenue{color:var(--byl-accent)}.chart-legend .cost{color:var(--byl-text-secondary)}.chart-legend .profit{color:var(--byl-status-ink)}
.finance-chart-card svg{display:block;width:100%;height:220px;margin-top:12px;overflow:visible}.trend-line{fill:none;stroke-width:.7;vector-effect:non-scaling-stroke}.revenue-line{stroke:var(--byl-accent)}.cost-line{stroke:var(--byl-text-secondary)}.profit-line{stroke:var(--byl-status-ink)}.zero-line{stroke:var(--byl-border-control);stroke-width:.25;vector-effect:non-scaling-stroke;stroke-dasharray:2 2}.chart-axis{display:flex;justify-content:space-between;color:var(--byl-text-muted);font-size:10px}.finance-health{margin-top:10px;color:var(--byl-text-muted);font-size:10px}
.procurement-section{padding:0;overflow:hidden}.procurement-section>.dashboard-section-heading,.procurement-section>.dashboard-kpi-grid,.procurement-section>.procurement-recent{margin-left:20px;margin-right:20px}.procurement-section>.dashboard-section-heading{margin-top:20px}.procurement-recent{display:grid;gap:8px;padding:0 0 20px}.procurement-row{display:grid;grid-template-columns:minmax(180px,1.2fr) 100px minmax(180px,1fr);gap:14px;align-items:center;padding:12px 14px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);text-decoration:none;color:inherit}.procurement-id{display:grid;gap:3px}.procurement-id span,.procurement-logistics{font-size:12px;color:var(--byl-text-secondary)}.procurement-status{font-size:12px;font-weight:700}
.procurement-quiet{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 20px;color:inherit;text-decoration:none}.procurement-quiet span{color:var(--byl-accent);font-size:12px;font-weight:700}.dashboard-empty{padding:36px;border:1px dashed var(--byl-border-control);border-radius:var(--byl-radius-xl);color:var(--byl-text-muted);text-align:center}
@media(max-width:1100px){.operations-funnel{grid-template-columns:repeat(3,minmax(0,1fr))}.dashboard-kpi-grid,.finance-summary-row,.role-workspace-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.loading-bars{grid-template-columns:repeat(2,1fr)}.dashboard-shell-page{gap:14px}.dashboard-hero{align-items:flex-start;flex-direction:column}.dashboard-hero h2{font-size:25px}.dashboard-period{justify-content:flex-start;width:100%;flex-wrap:nowrap;overflow-x:auto}.period-option{white-space:nowrap}.dashboard-custom-period{display:grid;grid-template-columns:1fr 1fr}.dashboard-custom-period button{grid-column:1/-1}.dashboard-section-shell{padding:16px}.dashboard-section-heading{display:grid}.dashboard-section-heading .mvp-button{justify-self:start}.operations-funnel,.dashboard-kpi-grid,.finance-summary-row{grid-template-columns:repeat(2,minmax(0,1fr))}.context-strip{grid-template-columns:auto auto}.context-strip small{grid-column:1/-1;text-align:left}.finance-chart-card svg{height:170px}.procurement-section{padding:0}.procurement-row{grid-template-columns:1fr;gap:5px}.procurement-quiet{align-items:flex-start;flex-direction:column}}
@media(max-width:430px){.operations-funnel,.dashboard-kpi-grid,.finance-summary-row,.role-workspace-grid{grid-template-columns:1fr}.dashboard-custom-period{grid-template-columns:1fr}.dashboard-custom-period button{grid-column:auto}.finance-chart-card svg{height:140px}}
.dashboard-role-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 16px}.dashboard-role-actions>span{font-size:12px;font-weight:700;color:#64748b;margin-right:2px}
@media(max-width:640px){.dashboard-role-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.dashboard-role-actions>span{grid-column:1/-1}.dashboard-role-actions .mvp-button{text-align:center;justify-content:center}}
</style>
