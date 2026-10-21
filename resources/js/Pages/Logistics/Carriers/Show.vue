<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../../Composables/useAccountGridPreferences'

type CurrencyCost = { currency: string; total: number; average: number | null; shipments: number }
type Summary = {
  shipment_count: number
  chargeable_weight_kg: number | null
  chargeable_weight_coverage: number
  costs_by_currency: CurrencyCost[]
  this_month_count: number
  delivery_status_coverage: number
  in_transit_count: number
  delivered_count: number
  exception_count: number
  last_shipped_at: string | null
}
type Item = { sku: string | null; product_summary: string | null; product_url?: string | null; quantity: number }
type LedgerRow = {
  id: number; shipped_at: string | null; erp_order_id: string | null
  order_kind: string | null; order_url?: string | null; warehouse_name: string | null; destination_country: string | null; carrier: string | null
  service: string | null; service_display_name?: string | null; tracking_number: string | null; items: Item[]; chargeable_weight_kg: string | number | null
  actual_cost: string | number | null; currency: string | null; shipment_status: string | null; delivery_status: string | null
  handler_name: string | null; recipient_name: string | null; billing_document_number: string | null; provider_reference: string | null
  data_source: string | null; billed_at: string | null; raw_evidence: Record<string, unknown> | unknown[] | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

type CarrierServiceMaster = {
  id: number | null
  service_key: string
  service_value: string
  name_en: string | null
  name_zh: string | null
  display_name?: string
  is_active: boolean
  sort_order: number
}
type CarrierMaster = {
  id: number
  name: string
  name_en: string | null
  name_zh: string | null
  display_name: string
  is_active: boolean
  default_service_id: number | null
  services: CarrierServiceMaster[]
}
type ColumnKey = 'order_number' | 'shipped_at' | 'order_kind' | 'warehouse' | 'destination' | 'channel' | 'tracking' | 'items' | 'weight' | 'cost' | 'shipment_status' | 'delivery_status' | 'handler' | 'recipient' | 'billing' | 'source' | 'evidence'
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: 'ERP订单号' }, { key: 'shipped_at', label: '发货日期' }, { key: 'order_kind', label: '订单类型' }, { key: 'warehouse', label: '发货仓库' },
  { key: 'destination', label: '目的地' }, { key: 'channel', label: '运输 / 服务类型' }, { key: 'tracking', label: 'Tracking' }, { key: 'items', label: '产品/数量' },
  { key: 'weight', label: '计费重量' }, { key: 'cost', label: '物流费用' }, { key: 'shipment_status', label: 'Shipment状态' }, { key: 'delivery_status', label: '签收状态' },
  { key: 'handler', label: '发货人' }, { key: 'recipient', label: '收件人' }, { key: 'billing', label: '账单/单据' }, { key: 'source', label: '数据来源' }, { key: 'evidence', label: '原始凭证' },
]
const grid = reactive(useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.carriers.show.v1', definitions, requiredKeys: ['order_number'] }))

const props = defineProps<{ carrier: string; carrierDisplayName: string; carrierMaster: CarrierMaster; canEditCarrier: boolean; summary: Summary; shipments: Paginated<LedgerRow>; statusBar?: {key:string;label:string;url:string;count:number}[] }>()
const dateTime = (value: string | null) => value ? new Date(value).toLocaleString() : '—'
const money = (costs: CurrencyCost[], field: 'total' | 'average') => costs.length === 0 ? '—' : costs.map((row) => {
  const value = row[field]
  return `${row.currency === 'UNSPECIFIED' ? '未标币种' : row.currency} ${value === null ? '—' : value.toFixed(2)}`
}).join(' / ')
const rowMoney = (row: LedgerRow) => row.actual_cost === null ? '—' : `${row.currency || '未标币种'} ${Number(row.actual_cost).toFixed(2)}`
const orderKind = (value: string | null) => value === 'customer' ? '客户订单' : value === 'kol_fulfillment' ? '网红履约订单' : (value ?? '—')
const itemText = (items: Item[]) => items.length === 0 ? '—' : items.map((item) => `${item.sku || item.product_summary || '未命名'} × ${item.quantity}`).join('；')
const deliveryCount = (value: number) => props.summary.delivery_status_coverage === 0 ? '—' : String(value)
const goToPage = (page: number) => router.get(`/logistics/carriers/${encodeURIComponent(props.carrier)}`, { page }, { preserveState: true, replace: true })

const editorOpen = ref(false)
const editorSaving = ref(false)
const editorError = ref('')
const carrierForm = reactive<CarrierMaster>({ ...props.carrierMaster, services: props.carrierMaster.services.map(row => ({ ...row })) })
const defaultServiceKey = ref(props.carrierMaster.services.find(row => row.id === props.carrierMaster.default_service_id)?.service_key ?? '')
const syncCarrierForm = () => {
  Object.assign(carrierForm, { ...props.carrierMaster, services: props.carrierMaster.services.map(row => ({ ...row })) })
  defaultServiceKey.value = props.carrierMaster.services.find(row => row.id === props.carrierMaster.default_service_id)?.service_key ?? ''
}
const openEditor = () => { syncCarrierForm(); editorError.value = ''; editorOpen.value = true }
const closeEditor = () => { if (!editorSaving.value) editorOpen.value = false }
const addService = () => {
  const next = carrierForm.services.length + 1
  carrierForm.services.push({ id: null, service_key: `service_${next}`, service_value: '', name_en: '', name_zh: '', is_active: true, sort_order: next * 10 })
}
const setDefault = (row: CarrierServiceMaster) => {
  if (!row.is_active || !row.service_key.trim()) return
  defaultServiceKey.value = row.service_key.trim()
  carrierForm.default_service_id = row.id
}
const saveCarrier = () => {
  editorError.value = ''
  const defaultRow = carrierForm.services.find(row => row.service_key.trim() === defaultServiceKey.value)
  if (!defaultRow || !defaultRow.is_active) { editorError.value = '请选择一个已启用的默认运输 / 服务类型'; return }
  if (carrierForm.services.some(row => !row.service_key.trim() || !row.service_value.trim())) { editorError.value = '每个服务类型都必须填写内部标识与记录值'; return }
  editorSaving.value = true
  router.patch(`/logistics/carriers/${encodeURIComponent(props.carrier)}`, {
    name_zh: carrierForm.name_zh,
    name_en: carrierForm.name_en,
    is_active: carrierForm.is_active,
    default_service_id: carrierForm.default_service_id,
    default_service_key: defaultServiceKey.value,
    services: carrierForm.services.map(row => ({
      id: row.id,
      service_key: row.service_key,
      service_value: row.service_value,
      name_en: row.name_en,
      name_zh: row.name_zh,
      is_active: row.is_active,
      sort_order: row.sort_order,
    })),
  }, {
    preserveScroll: true,
    onSuccess: () => { editorOpen.value = false },
    onError: (errors) => { editorError.value = Object.values(errors)[0] as string || '保存失败，请检查配置' },
    onFinish: () => { editorSaving.value = false },
  })
}
</script>

<template>
  <Head :title="`${carrierDisplayName} · 承运商 · BYL ERP`" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '承运商', carrierDisplayName]">
    <section class="mvp-page carrier-detail-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading carrier-heading">
        <div><p class="mvp-kicker">Carrier Shipment Ledger</p><h2>{{ carrierDisplayName }}</h2><p>真实 Shipment 历史、计费重量、实际物流费用与 Provider raw evidence。</p></div>
        <div class="carrier-heading-actions"><button v-if="canEditCarrier" type="button" class="mvp-button carrier-edit-button" @click="openEditor">编辑承运商</button><Link class="mvp-button" href="/logistics/carriers">返回承运商</Link></div>
      </div>

      <div class="carrier-kpis">
        <div><span>总发货票数</span><strong>{{ summary.shipment_count }}</strong></div>
        <div><span>总计费重量</span><strong>{{ summary.chargeable_weight_kg === null ? '—' : `${summary.chargeable_weight_kg.toFixed(3)} kg` }}</strong></div>
        <div><span>总物流费用</span><strong>{{ money(summary.costs_by_currency, 'total') }}</strong></div>
        <div><span>平均每票费用</span><strong>{{ money(summary.costs_by_currency, 'average') }}</strong></div>
        <div><span>本月发货数</span><strong>{{ summary.this_month_count }}</strong></div>
        <div><span>运输中</span><strong>{{ deliveryCount(summary.in_transit_count) }}</strong></div>
        <div><span>已签收</span><strong>{{ deliveryCount(summary.delivered_count) }}</strong></div>
        <div><span>异常</span><strong>{{ deliveryCount(summary.exception_count) }}</strong></div>
        <div><span>最后一票发货</span><strong>{{ dateTime(summary.last_shipped_at) }}</strong></div>
      </div>
      <p v-if="summary.delivery_status_coverage === 0" class="carrier-evidence-note">暂无真实签收状态证据；运输中 / 已签收 / 异常不做推断。</p>

      <div class="mvp-table-wrap ledger-wrap">
        <table class="mvp-table ledger-table">
          <thead><tr><th v-for="column in grid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="grid.columns" :required-keys="['order_number']" :sync-state="grid.syncState" @toggle="grid.toggle" @start-drag="grid.startDrag" @drop="grid.drop" @reset="grid.reset" /></tr></thead>
          <tbody>
            <tr v-for="row in shipments.data" :key="row.id">
              <td v-for="column in grid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link"><strong>{{ row.erp_order_id ?? '—' }}</strong></Link><span v-else><strong>{{ row.erp_order_id ?? '—' }}</strong></span></template><template v-else-if="column.key === 'shipped_at'">{{ dateTime(row.shipped_at) }}</template><template v-else-if="column.key === 'order_kind'">{{ orderKind(row.order_kind) }}</template><template v-else-if="column.key === 'warehouse'">{{ row.warehouse_name ?? '—' }}</template><template v-else-if="column.key === 'destination'">{{ row.destination_country ?? '—' }}</template><template v-else-if="column.key === 'channel'"><span class="service-cell">{{ row.service_display_name ?? row.service ?? '—' }}</span></template><template v-else-if="column.key === 'tracking'">{{ row.tracking_number ?? '—' }}</template><template v-else-if="column.key === 'items'" class="items-cell"><template v-for="item in row.items" :key="item.product_url ?? item.sku"><Link v-if="item.product_url" :href="item.product_url" class="mvp-link">{{ item.sku || item.product_summary || '未命名' }}</Link><span v-else>{{ item.sku || item.product_summary || '未命名' }}</span> × {{ item.quantity }}；</template></template><template v-else-if="column.key === 'weight'">{{ row.chargeable_weight_kg === null ? '—' : `${Number(row.chargeable_weight_kg).toFixed(3)} kg` }}</template><template v-else-if="column.key === 'cost'">{{ rowMoney(row) }}</template><template v-else-if="column.key === 'shipment_status'">{{ row.shipment_status ?? '—' }}</template><template v-else-if="column.key === 'delivery_status'">{{ row.delivery_status ?? '—' }}</template><template v-else-if="column.key === 'handler'">{{ row.handler_name ?? '—' }}</template><template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template><template v-else-if="column.key === 'billing'">{{ row.billing_document_number ?? row.provider_reference ?? '—' }}</template><template v-else-if="column.key === 'source'">{{ row.data_source ?? '—' }}</template><template v-else><details v-if="row.raw_evidence"><summary>查看</summary><pre>{{ JSON.stringify(row.raw_evidence, null, 2) }}</pre></details><span v-else>—</span></template></td>
            </tr>
            <tr v-if="shipments.data.length === 0"><td :colspan="grid.visibleColumns.length" class="mvp-empty">暂无该承运商物流记录。</td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="shipments.last_page > 1" class="mvp-pagination"><button class="mvp-button" :disabled="shipments.current_page <= 1" @click="goToPage(shipments.current_page - 1)">上一页</button><span>第 {{ shipments.current_page }} / {{ shipments.last_page }} 页 · 共 {{ shipments.total }} 条</span><button class="mvp-button" :disabled="shipments.current_page >= shipments.last_page" @click="goToPage(shipments.current_page + 1)">下一页</button></div>
    </section>

    <div v-if="editorOpen" class="carrier-editor-backdrop" @click.self="closeEditor">
      <aside class="carrier-editor-panel" role="dialog" aria-modal="true" aria-label="编辑承运商">
        <header class="carrier-editor-header">
          <div><p class="mvp-kicker">CARRIER MASTER V2</p><h2>编辑承运商</h2><p>修改承运商基础信息及默认服务类型配置。</p></div>
          <button type="button" class="carrier-editor-close" @click="closeEditor">×</button>
        </header>

        <section class="carrier-editor-card">
          <h3>基础信息</h3>
          <div class="carrier-form-grid">
            <label>内部标识<input :value="carrierForm.name" disabled><small>系统内部唯一标识，不可修改</small></label>
            <label>是否启用<div class="toggle-line"><button type="button" class="switch" :class="{ on: carrierForm.is_active }" @click="carrierForm.is_active = !carrierForm.is_active"><span></span></button><strong>{{ carrierForm.is_active ? '已启用' : '已停用' }}</strong></div><small>停用后不再用于新订单发货，历史数据不受影响</small></label>
            <label>中文名称 *<input v-model="carrierForm.name_zh" placeholder="例如：速达"></label>
            <label>英文名称 *<input v-model="carrierForm.name_en" placeholder="例如：4PX"></label>
            <label class="span-2">前台显示名<input :value="[carrierForm.name_en, carrierForm.name_zh].filter(Boolean).join(' / ')" disabled><small>自动由中英文名称组合，用于下单、发货和承运商页面</small></label>
          </div>
        </section>

        <section class="carrier-editor-card">
          <h3>默认规则说明</h3>
          <div class="carrier-info-note">ⓘ 发货时先选择物流商，再自动带出默认运输 / 服务类型；若操作员未修改，系统将自动记录默认值。</div>
        </section>

        <section class="carrier-editor-card service-editor-card">
          <div class="service-editor-heading"><div><h3>运输 / 服务类型</h3><p>服务名称尽可能中英文并列；历史记录值保持兼容。</p></div><button type="button" class="mvp-button carrier-add-service" @click="addService">＋ 新增服务类型</button></div>
          <div class="service-editor-table-wrap">
            <table class="service-editor-table">
              <thead><tr><th>默认</th><th>中文名称</th><th>英文名称</th><th>内部 Service 值</th><th>记录值</th><th>排序</th><th>启用</th></tr></thead>
              <tbody>
                <tr v-for="row in carrierForm.services" :key="row.id ?? row.service_key">
                  <td><input type="radio" name="carrier-default-service" :checked="defaultServiceKey === row.service_key" :disabled="!row.is_active || !row.service_key.trim()" @change="setDefault(row)"></td>
                  <td><input v-model="row.name_zh" class="cell-input" placeholder="中文"></td>
                  <td><input v-model="row.name_en" class="cell-input" placeholder="English"></td>
                  <td><input v-model="row.service_key" class="cell-input service-key" :disabled="row.id !== null" placeholder="internal_key"></td>
                  <td><input v-model="row.service_value" class="cell-input" placeholder="Shipment service"></td>
                  <td><input v-model.number="row.sort_order" class="cell-input sort-input" type="number" min="0" max="9999"></td>
                  <td><button type="button" class="switch" :class="{ on: row.is_active }" @click="row.is_active = !row.is_active"><span></span></button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <div class="carrier-history-note">ⓘ <strong>历史兼容提示</strong>：历史 Shipment 原始 service 文本保留不改写；停用仅影响新发货。</div>
        <p v-if="editorError" class="carrier-editor-error">{{ editorError }}</p>
        <footer class="carrier-editor-actions"><button type="button" class="mvp-button" :disabled="editorSaving" @click="closeEditor">取消</button><button type="button" class="mvp-button carrier-save-button" :disabled="editorSaving" @click="saveCarrier">{{ editorSaving ? '保存中…' : '保存承运商' }}</button></footer>
      </aside>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.carrier-heading { align-items: flex-start; display: flex; justify-content: space-between; gap: 16px; }
.carrier-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 18px; }
.carrier-kpis > div { border: 1px solid var(--byl-color-border, #d8e0e3); border-radius: 9px; padding: 12px; background: var(--byl-color-surface, #fff); }
.carrier-kpis span { display: block; font-size: 12px; color: var(--byl-color-muted, #6b7f87); margin-bottom: 6px; }
.carrier-kpis strong { font-size: 15px; }
.carrier-evidence-note { margin: -6px 0 16px; color: var(--byl-color-muted, #6b7f87); font-size: 13px; }
.ledger-wrap { overflow-x: auto; }
.ledger-table { min-width: 1900px; }
.ledger-table small { display: block; opacity: .65; }
.items-cell { min-width: 210px; white-space: normal; }
details pre { max-width: 360px; max-height: 220px; overflow: auto; font-size: 11px; white-space: pre-wrap; }

.carrier-heading-actions { display:flex; gap:8px; align-items:center; }
.carrier-edit-button,.carrier-save-button,.carrier-add-service { background:#0d756e; color:#fff; border-color:#0d756e; }
.service-cell { white-space:normal; line-height:1.45; }
.carrier-editor-backdrop { position:fixed; inset:0; z-index:90; display:flex; justify-content:flex-end; background:rgba(19,38,42,.42); backdrop-filter:blur(2px); }
.carrier-editor-panel { width:min(760px,62vw); height:100%; overflow:auto; padding:28px; background:#f8fbfb; box-shadow:-16px 0 40px rgba(24,49,53,.18); }
.carrier-editor-header { display:flex; justify-content:space-between; gap:18px; align-items:flex-start; padding-bottom:18px; border-bottom:1px solid #dce6e7; }
.carrier-editor-header h2 { margin:4px 0 5px; font-size:25px; color:#173d3a; }
.carrier-editor-header p { margin:0; color:#73858a; }
.carrier-editor-close { width:42px; height:42px; border:0; border-radius:12px; background:#edf2f2; color:#50666a; font-size:28px; cursor:pointer; }
.carrier-editor-card { margin-top:16px; padding:18px; border:1px solid #dce5e6; border-radius:12px; background:#fff; }
.carrier-editor-card h3 { margin:0 0 14px; color:#173d3a; font-size:15px; }
.carrier-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
.carrier-form-grid label { display:grid; gap:6px; color:#415a60; font-size:12px; font-weight:700; }
.carrier-form-grid input,.cell-input { width:100%; box-sizing:border-box; border:1px solid #cbd9dc; border-radius:8px; background:#fff; padding:10px 11px; color:#17343a; }
.carrier-form-grid input:disabled,.cell-input:disabled { background:#f1f5f5; color:#718287; }
.carrier-form-grid small { color:#8a989c; font-weight:400; }
.span-2 { grid-column:1/-1; }
.toggle-line { min-height:40px; display:flex; align-items:center; gap:9px; }
.switch { width:42px; height:24px; padding:2px; border:0; border-radius:999px; background:#c8d3d4; cursor:pointer; transition:.18s ease; }
.switch span { display:block; width:20px; height:20px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.2); transition:.18s ease; }
.switch.on { background:#17867d; }
.switch.on span { transform:translateX(18px); }
.carrier-info-note,.carrier-history-note { padding:12px 14px; border-radius:9px; background:#eff8fa; color:#58737a; font-size:12px; line-height:1.6; }
.service-editor-heading { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
.service-editor-heading h3 { margin-bottom:4px; }
.service-editor-heading p { margin:0; color:#7a8b90; font-size:12px; }
.service-editor-table-wrap { margin-top:14px; overflow:auto; border:1px solid #dce5e6; border-radius:9px; }
.service-editor-table { width:100%; min-width:860px; border-collapse:collapse; font-size:11px; }
.service-editor-table th,.service-editor-table td { padding:8px; border-bottom:1px solid #e4ebec; border-right:1px solid #edf1f2; text-align:left; vertical-align:middle; }
.service-editor-table th { background:#f7f9f9; color:#4a6268; white-space:nowrap; }
.service-editor-table tbody tr:last-child td { border-bottom:0; }
.service-key { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
.sort-input { width:64px; }
.carrier-history-note { margin-top:16px; background:#f0f8fb; }
.carrier-editor-error { margin:12px 0 0; padding:10px 12px; border-radius:8px; background:#fff0ef; color:#a5433c; font-size:12px; }
.carrier-editor-actions { position:sticky; bottom:-28px; display:flex; justify-content:flex-end; gap:10px; margin:18px -28px -28px; padding:16px 28px; border-top:1px solid #dce5e6; background:rgba(255,255,255,.97); }
@media (max-width: 900px) {
  .carrier-editor-panel { width:100%; padding:18px; }
  .carrier-form-grid { grid-template-columns:1fr; }
  .span-2 { grid-column:auto; }
  .carrier-editor-actions { margin:18px -18px -18px; padding:14px 18px; }
}

</style>
