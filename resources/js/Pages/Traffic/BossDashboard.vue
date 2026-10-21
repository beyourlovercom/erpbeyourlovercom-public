<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type TrendRow = { date:string; impressions:number; clicks:number; spend:string; provider_conversions:number; inquiries:number; qualified_inquiries:null }
type CampaignRow = { external_campaign_id:string; name:string; status:string|null; advertising_channel_type:string|null; impressions:number; clicks:number; spend:string; provider_conversions:number }
type SourceRow = { source_type:string; inquiries:number; qualified_inquiries:null }
const props = defineProps<{
  project:{ key:string; name:string }
  filters:{ date_from:string; date_to:string }
  summary:{ currency_code:string|null; mixed_currency:boolean; impressions:number; clicks:number; spend:string|null; provider_conversions:number; inquiries:number; qualified_inquiries:null; qualification_state:string; cost_per_inquiry:string|null; cost_per_qualified_inquiry:null; ctr_percent:number|null; cpc:string|null }
  trend:TrendRow[]
  campaigns:CampaignRow[]
  sources:SourceRow[]
  data_health:{ traffic_rows:number; inquiry_rows:number; data_from:string|null; data_to:string|null; account_last_synced_at:string|null; sync_mode:string; automatic_sync_enabled:boolean; qualification_state:string; attribution_scope:string }
}>()

const filters = reactive({ date_from: props.filters.date_from, date_to: props.filters.date_to })
const apply = () => router.get('/marketing/traffic', { project: props.project.key, ...filters }, { preserveState: true, replace: true })
const reset = () => router.get('/marketing/traffic', { project: props.project.key })
const integer = (value:number) => value.toLocaleString('zh-CN')
const percent = (value:number|null) => value === null ? '—' : `${value.toFixed(2)}%`
const currency = (value:string|null) => {
  if (value === null || props.summary.mixed_currency || !props.summary.currency_code) return '—'
  return new Intl.NumberFormat('zh-CN', { style: 'currency', currency: props.summary.currency_code, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value))
}
const sourceLabel = (value:string) => ({ google_ads_form_lead:'表单 Lead', google_ads_whatsapp_lead:'WhatsApp Lead' } as Record<string,string>)[value] || value
const syncLabel = computed(() => props.data_health.automatic_sync_enabled ? '自动同步' : '手动同步')

const chartWidth = 620
const chartHeight = 150
const chartPadding = 12
const pathFor = (field:'spend'|'inquiries') => {
  const values = props.trend.map(row => Number(row[field]))
  if (!values.length) return ''
  const min = Math.min(...values, 0)
  const max = Math.max(...values, 1)
  return props.trend.map((row, index) => {
    const x = props.trend.length === 1 ? chartWidth / 2 : chartPadding + index * ((chartWidth - chartPadding * 2) / (props.trend.length - 1))
    const y = chartPadding + (max - Number(row[field])) * ((chartHeight - chartPadding * 2) / (max - min || 1))
    return `${index === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`
  }).join(' ')
}
</script>

<template>
  <Head :title="`${project.name} 流量总览 · BYL ERP`" />
  <AuthenticatedLayout title="流量总览" :breadcrumbs="['BYL ERP', '营销', '流量总览']">
    <section class="mvp-page traffic-boss-dashboard">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">YBY TRAFFIC / BOSS DASHBOARD</p><h2>{{ project.name }} · 流量总览</h2><p>Google Ads 投放与 Inquiry Metrics 的老板视角汇总。当前询盘只做到项目 / 来源级，不虚构 Campaign 级归因。</p></div>
      </div>

      <form class="mvp-card dashboard-filters" @submit.prevent="apply">
        <label>开始日期<input v-model="filters.date_from" type="date"></label>
        <label>结束日期<input v-model="filters.date_to" type="date"></label>
        <div class="filter-actions"><button class="mvp-button mvp-button-primary">应用</button><button type="button" class="mvp-button" @click="reset">最近 30 天</button></div>
      </form>

      <div class="freshness-strip" :class="{ stale: !data_health.automatic_sync_enabled }">
        <strong>数据截至 {{ data_health.data_to || '—' }}</strong>
        <span>{{ syncLabel }} · 当前尚未启用 Google Ads Production 自动同步</span>
      </div>

      <div class="kpi-grid">
        <article class="kpi-card primary"><span>广告消耗</span><strong>{{ currency(summary.spend) }}</strong><small>{{ summary.currency_code || '—' }}</small></article>
        <article class="kpi-card"><span>展示</span><strong>{{ integer(summary.impressions) }}</strong><small>CTR {{ percent(summary.ctr_percent) }}</small></article>
        <article class="kpi-card"><span>点击</span><strong>{{ integer(summary.clicks) }}</strong><small>CPC {{ currency(summary.cpc) }}</small></article>
        <article class="kpi-card success"><span>询盘</span><strong>{{ integer(summary.inquiries) }}</strong><small>表单 + WhatsApp</small></article>
        <article class="kpi-card"><span>每询盘成本</span><strong>{{ currency(summary.cost_per_inquiry) }}</strong><small>项目级 blended CPL</small></article>
        <article class="kpi-card unavailable"><span>有效询盘</span><strong>—</strong><small>Case Truth 建立后开放</small></article>
      </div>

      <div class="trend-grid">
        <article class="mvp-card trend-card">
          <div class="card-heading"><div><p class="mvp-kicker">DAILY SPEND</p><h3>日消耗趋势</h3></div><strong>{{ currency(summary.spend) }}</strong></div>
          <div v-if="trend.length" class="chart-shell"><svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" role="img" aria-label="每日广告消耗趋势"><path :d="pathFor('spend')" class="spend-line" /></svg><div class="chart-dates"><span>{{ trend[0]?.date }}</span><span>{{ trend[trend.length - 1]?.date }}</span></div></div>
          <p v-else class="empty-state">当前日期范围没有广告数据。</p>
        </article>
        <article class="mvp-card trend-card">
          <div class="card-heading"><div><p class="mvp-kicker">DAILY INQUIRIES</p><h3>日询盘趋势</h3></div><strong>{{ integer(summary.inquiries) }}</strong></div>
          <div v-if="trend.length" class="chart-shell"><svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" role="img" aria-label="每日询盘趋势"><path :d="pathFor('inquiries')" class="inquiry-line" /></svg><div class="chart-dates"><span>{{ trend[0]?.date }}</span><span>{{ trend[trend.length - 1]?.date }}</span></div></div>
          <p v-else class="empty-state">当前日期范围没有询盘数据。</p>
        </article>
      </div>

      <div class="detail-grid">
        <article class="mvp-card campaign-card">
          <div class="card-heading"><div><p class="mvp-kicker">CAMPAIGNS</p><h3>Google Ads Campaign</h3></div><span>{{ campaigns.length }} 个</span></div>
          <div class="table-wrap"><table><thead><tr><th>Campaign</th><th>展示</th><th>点击</th><th>Ads 转化</th><th>消耗</th></tr></thead><tbody>
            <tr v-for="row in campaigns" :key="row.external_campaign_id"><td><strong>{{ row.name }}</strong><small>{{ row.external_campaign_id }}</small></td><td>{{ integer(row.impressions) }}</td><td>{{ integer(row.clicks) }}</td><td>{{ row.provider_conversions }}</td><td>{{ currency(row.spend) }}</td></tr>
            <tr v-if="!campaigns.length"><td colspan="5" class="empty-cell">暂无 Campaign 数据</td></tr>
          </tbody></table></div>
          <p class="scope-note">Ads 转化来自 Google Ads provider 数据；当前不把项目级询盘反推到 Campaign。</p>
        </article>

        <aside class="mvp-card source-card">
          <div><p class="mvp-kicker">INQUIRY SOURCES</p><h3>询盘来源</h3></div>
          <div class="source-list"><div v-for="row in sources" :key="row.source_type"><span>{{ sourceLabel(row.source_type) }}</span><strong>{{ integer(row.inquiries) }}</strong></div><p v-if="!sources.length" class="empty-state">暂无询盘来源数据。</p></div>
          <div class="health-block"><h4>数据健康</h4><dl><div><dt>Traffic rows</dt><dd>{{ data_health.traffic_rows }}</dd></div><div><dt>Inquiry rows</dt><dd>{{ data_health.inquiry_rows }}</dd></div><div><dt>有效询盘</dt><dd>暂不可用</dd></div><div><dt>归因粒度</dt><dd>项目 / 来源</dd></div></dl></div>
        </aside>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.dashboard-filters{display:flex;align-items:end;gap:12px;margin-bottom:14px}.dashboard-filters label{display:grid;gap:5px;color:#64748b;font-size:12px}.dashboard-filters input{min-height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;background:#fff}.filter-actions{display:flex;gap:8px}.freshness-strip{display:flex;justify-content:space-between;gap:16px;padding:10px 14px;border:1px solid #dbe4ec;border-radius:10px;background:#f8fafc;color:#475569;font-size:12px}.freshness-strip.stale{border-color:#fed7aa;background:#fff7ed;color:#9a3412}.kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-top:14px}.kpi-card{display:grid;gap:7px;min-width:0;padding:16px;border:1px solid #dbe4ec;border-radius:12px;background:#fff}.kpi-card.primary{border-top:3px solid #2563eb}.kpi-card.success{border-top:3px solid #059669}.kpi-card.unavailable{background:#f8fafc}.kpi-card span{color:#64748b;font-size:12px;font-weight:650}.kpi-card strong{font-size:clamp(21px,1.5vw,28px);line-height:1;color:#0f172a;white-space:nowrap;font-variant-numeric:tabular-nums}.kpi-card small{color:#94a3b8;font-size:11px}.trend-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}.trend-card{min-width:0}.card-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.card-heading h3{margin:2px 0 10px}.card-heading>strong{font-size:18px;font-variant-numeric:tabular-nums}.chart-shell{overflow-x:auto}svg{width:100%;min-width:430px;height:150px}.spend-line,.inquiry-line{fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}.spend-line{stroke:#2563eb}.inquiry-line{stroke:#059669}.chart-dates{display:flex;justify-content:space-between;color:#94a3b8;font-size:11px}.detail-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,.72fr);gap:14px;margin-top:14px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px 8px;border-bottom:1px solid #e2e8f0;text-align:right;white-space:nowrap}th:first-child,td:first-child{text-align:left}th{color:#64748b;font-weight:650}td{color:#334155;font-variant-numeric:tabular-nums}td strong,td small{display:block}td small{margin-top:3px;color:#94a3b8;font-size:11px}.scope-note{margin:12px 0 0;color:#64748b;font-size:11px}.source-list{display:grid;gap:2px}.source-list>div,.health-block dl>div{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #e2e8f0}.source-list span,.health-block dt{color:#64748b;font-size:13px}.source-list strong{font-size:22px}.health-block{margin-top:20px}.health-block h4{margin:0 0 4px}.health-block dl{margin:0}.health-block dd{margin:0;color:#334155;font-weight:650}.empty-state,.empty-cell{color:#94a3b8;text-align:center}.empty-cell{text-align:center!important}@media(max-width:1440px){.kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:900px){.dashboard-filters,.freshness-strip{align-items:stretch;flex-direction:column}.kpi-grid,.trend-grid,.detail-grid{grid-template-columns:1fr}}
</style>
