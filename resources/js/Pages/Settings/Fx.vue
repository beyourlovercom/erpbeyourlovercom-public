<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type FxSource = 'manual' | 'ecb' | 'fixed' | 'unavailable'
type FxRow = {
  currency:string; label:string; ecbRate:number|null; manualRate:number|null; automatic:boolean
  effectiveRate:number|null; source:FxSource; manualEditable:boolean
}
type FxReference = {
  source:string; available:boolean; referenceDate:string|null; stale:boolean
  refreshHours:number; policy:string
}
const props = defineProps<{ rows:FxRow[]; reference:FxReference }>()
const editableRows = props.rows.filter((row) => row.manualEditable)
const form = useForm<{ rates:Record<string,string>; automatic:Record<string,boolean> }>({
  rates: Object.fromEntries(editableRows.map((row) => [row.currency, row.manualRate === null ? '' : String(row.manualRate)])),
  automatic: Object.fromEntries(editableRows.map((row) => [row.currency, row.automatic])),
})
const selectedCurrency = ref(editableRows[0]?.currency || 'USD')
const amount = ref('1000')
const validManualRate = (row:FxRow):number|null => {
  if (!row.manualEditable) return row.effectiveRate
  const manual = Number(form.rates[row.currency])
  return form.rates[row.currency]?.trim() && Number.isFinite(manual) && manual > 0 ? manual : null
}
const liveRate = (row:FxRow):number|null => {
  if (!row.manualEditable) return row.effectiveRate
  return form.automatic[row.currency] ? row.ecbRate : validManualRate(row)
}
const liveSource = (row:FxRow):FxSource => {
  if (!row.manualEditable) return 'fixed'
  if (form.automatic[row.currency]) return row.ecbRate === null ? 'unavailable' : 'ecb'
  return validManualRate(row) === null ? 'unavailable' : 'manual'
}
const selectedRow = computed(() => props.rows.find((row) => row.currency === selectedCurrency.value) || props.rows[0])
const result = computed(() => {
  const rate = selectedRow.value ? liveRate(selectedRow.value) : null
  const value = Number(amount.value)
  return rate !== null && Number.isFinite(value) ? value * rate : null
})
const money = (value:number|null) => value === null ? '—' : new Intl.NumberFormat('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
const rateText = (value:number|null) => value === null ? '—' : Number(value).toFixed(4)
const sourceText = (source:FxSource) => ({ manual:'手动', ecb:'自动', fixed:'固定', unavailable:'不可用' }[source])
const submit = () => form.put('/settings/fx', { preserveScroll: true })
const toggleAutomatic = (currency:string) => { form.automatic[currency] = !form.automatic[currency] }
</script>

<template>
  <Head title="汇率计算 · BYL ERP" />
  <AuthenticatedLayout title="汇率计算" :breadcrumbs="['BYL ERP','设置','汇率计算']">
    <section class="mvp-page fx-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">FX SETTINGS</p><h2>汇率计算</h2><p>自动参考 + 手动汇率配置 · 统一换算到人民币 CNY。</p></div>
        <button class="mvp-button mvp-button-primary" type="button" :disabled="form.processing" @click="submit">保存全部汇率</button>
      </div>

      <div class="fx-status mvp-card">
        <div><span>自动来源</span><strong>{{ reference.source }}</strong></div>
        <div><span>参考日期</span><strong>{{ reference.referenceDate || '暂不可用' }}</strong></div>
        <div><span>缓存状态</span><strong :class="reference.stale ? 'warning' : 'ok'">{{ reference.stale ? '已过期' : (reference.available ? '新鲜' : '不可用') }}</strong></div>
        <div><span>刷新策略</span><strong>{{ reference.refreshHours }} 小时</strong></div>
        <div><span>当前口径</span><strong>按币种切换</strong></div>
      </div>

      <div class="fx-top-grid">
        <article class="mvp-card calculator-card">
          <h3>快速换算</h3>
          <div class="calc-inputs">
            <label>来源币种<select v-model="selectedCurrency" class="erp-input"><option v-for="row in editableRows" :key="row.currency" :value="row.currency">{{ row.currency }} · {{ row.label }}</option></select></label>
            <label>金额<input v-model="amount" class="erp-input" inputmode="decimal"></label>
          </div>
          <div class="calc-result"><small>≈</small><strong>¥ {{ money(result) }}</strong><span>人民币 CNY</span></div>
          <p v-if="selectedRow">有效汇率：1 {{ selectedRow.currency }} = {{ rateText(liveRate(selectedRow)) }} CNY · 来源：{{ sourceText(liveSource(selectedRow)) }}</p>
        </article>

        <article class="mvp-card reference-card">
          <h3>ECB 自动参考汇率</h3>
          <div class="mini-table"><div class="mini-head"><span>币种</span><span>ECB → CNY</span><span>状态</span></div><div v-for="row in rows" :key="row.currency" class="mini-row"><strong>{{ row.currency }}</strong><span>{{ rateText(row.ecbRate) }}</span><span>{{ row.ecbRate === null ? '不可用' : (reference.stale ? '缓存' : '正常') }}</span></div></div>
        </article>
      </div>

      <article class="mvp-card manual-card">
        <div class="card-heading"><div><h3>手动汇率设置</h3><p>手动汇率会一直保留；通过右侧开关选择当前使用 ECB 自动汇率或手动汇率。</p></div><span v-if="form.recentlySuccessful" class="saved">已保存</span></div>
        <div class="fx-table-wrap"><table class="fx-table"><thead><tr><th>币种</th><th>ECB 参考</th><th>手动汇率（CNY）</th><th>当前有效汇率</th><th>来源</th><th>切换自动</th></tr></thead><tbody>
          <tr v-for="row in rows" :key="row.currency">
            <td><strong>{{ row.currency }}</strong><small>{{ row.label }}</small></td><td>{{ rateText(row.ecbRate) }}</td>
            <td><input v-if="row.manualEditable" v-model="form.rates[row.currency]" class="erp-input rate-input" inputmode="decimal" placeholder="填写备用手动汇率"><span v-else>1.0000</span><small v-if="row.manualEditable && form.errors[`rates.${row.currency}`]" class="error">{{ form.errors[`rates.${row.currency}`] }}</small></td>
            <td><strong>{{ rateText(liveRate(row)) }}</strong></td><td><span class="source-badge" :data-source="liveSource(row)">{{ sourceText(liveSource(row)) }}</span></td>
            <td>
              <button v-if="row.manualEditable" type="button" class="fx-mode-control" role="switch" :aria-checked="form.automatic[row.currency]" :aria-label="`${row.currency} 切换自动汇率`" @click="toggleAutomatic(row.currency)">
                <span class="toggle-track" :class="{ on: form.automatic[row.currency] }"><span class="toggle-knob" /></span>
                <span class="mode-label" :class="{ automatic: form.automatic[row.currency] }">{{ form.automatic[row.currency] ? '自动汇率' : '手动汇率' }}</span>
              </button>
              <span v-else>—</span>
            </td>
          </tr>
        </tbody></table></div>
        <p class="save-note">切换到自动汇率不会清空已填写的手动汇率；点击“保存全部汇率”后正式生效。</p>
      </article>

      <article class="fx-policy">
        <h3>财务使用边界</h3>
        <p>• 本页汇率只用于管理换算、预算和参考展示，不会回写历史订单、成本或利润。</p>
        <p>• 历史财务事实仍使用交易/过账时保存的 FX snapshot，不会随本页当前汇率变化。</p>
        <p>• 每个币种可独立选择自动或手动；自动模式使用 ECB，已填写的手动值会保留供随时切换。</p>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.fx-page{display:grid;gap:16px}.fx-status{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:18px}.fx-status div{display:grid;gap:5px}.fx-status span,.fx-table small{color:var(--muted,#667085);font-size:12px}.fx-status strong{font-size:16px}.ok{color:#26734d}.warning{color:#a15c00}.fx-top-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}.calculator-card h3,.reference-card h3,.manual-card h3{margin-top:0}.calc-inputs{display:grid;grid-template-columns:180px 1fr;gap:14px}.calc-inputs label{display:grid;gap:7px;font-size:13px;color:#667085}.calc-result{display:flex;align-items:baseline;gap:12px;margin:28px 0 8px}.calc-result strong{font-size:34px}.calc-result span{color:#667085}.calculator-card>p{color:#667085}.mini-table{display:grid}.mini-head,.mini-row{display:grid;grid-template-columns:1fr 1.4fr 1fr;gap:12px;padding:9px 0;border-bottom:1px solid var(--border,#e4e7ec)}.mini-head{font-size:12px;color:#667085;font-weight:700}.card-heading{display:flex;justify-content:space-between;gap:16px}.card-heading p{margin:6px 0 16px;color:#667085}.saved{color:#26734d;font-weight:700}.fx-table-wrap{overflow:auto}.fx-table{width:100%;border-collapse:collapse}.fx-table th,.fx-table td{text-align:left;padding:12px 10px;border-top:1px solid var(--border,#e4e7ec);vertical-align:middle}.fx-table th{background:#f8fafc;font-size:12px;color:#475467}.fx-table td:first-child{display:grid;gap:2px}.rate-input{min-width:165px}.source-badge{display:inline-flex;padding:3px 9px;border-radius:999px;background:#f2f4f7;font-size:12px}.source-badge[data-source="manual"]{background:#eef2ff;color:#3f51b5}.source-badge[data-source="ecb"]{color:#475467}.fx-mode-control{display:inline-flex;align-items:center;gap:10px;border:0;background:transparent;padding:2px 0;cursor:pointer}.toggle-track{position:relative;width:44px;height:24px;border-radius:999px;background:#d0d5dd;transition:background .18s ease;flex:0 0 auto}.toggle-track.on{background:#16a34a}.toggle-knob{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(16,24,40,.18);transition:transform .18s ease}.toggle-track.on .toggle-knob{transform:translateX(20px)}.mode-label{display:inline-flex;padding:4px 9px;border-radius:999px;background:#f2f4f7;color:#667085;font-size:12px;font-weight:700;white-space:nowrap}.mode-label.automatic{background:#ecfdf3;color:#15803d}.fx-mode-control:focus-visible{outline:2px solid #7f56d9;outline-offset:3px;border-radius:8px}.error{display:block!important;color:#b42318!important;margin-top:4px}.save-note{font-size:12px;color:#667085;margin:12px 0 0}.fx-policy{padding:18px 22px;border:1px solid #ead8a6;border-radius:12px;background:#fff9ed}.fx-policy h3{margin:0 0 8px;color:#59451d}.fx-policy p{margin:5px 0;color:#66512b}@media(max-width:900px){.fx-status{grid-template-columns:repeat(2,1fr)}.fx-top-grid{grid-template-columns:1fr}.calc-inputs{grid-template-columns:1fr}}
</style>
