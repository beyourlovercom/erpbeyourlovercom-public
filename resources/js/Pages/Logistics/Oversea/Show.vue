<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { publishGlobalFeedback } from '../../../lib/globalFeedback'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../../Composables/useAccountGridPreferences'

type WarehouseOption = { key:string; id:number; code:string; label:string; carrier:string|null; url:string }
type Item = { sku:string|null; quantity:number }
type CarrierServiceOption = { id:number; key:string; value:string; label:string; name_en:string|null; name_zh:string|null }
type CarrierOption = { value:string; label:string; default_service_id:number|null; default_service_value:string|null; services:CarrierServiceOption[] }
type Row = {
  line:number; order_id:number; erp_order_id:string|null; order_url:string|null; recipient_name:string|null; country:string|null
  warehouse_id:number; warehouse_name:string|null; carrier:string|null; service:string|null; carrier_selection_source:string|null; tracking_number:string|null
  progress:string; note:string|null; review_status:'approved'|'pending'; shipment_status:string; items:Item[]
}
type PageRows = { data:Row[]; current_page:number; last_page:number; per_page:number; total:number; from:number|null; to:number|null; links?:{url:string|null;label:string;active:boolean}[] }
type ColumnKey = 'line'|'order'|'warehouse'|'carrier'|'service'|'tracking'|'note'|'recipient'|'country'|'items'
const columnDefinitions: GridColumnDefinition<ColumnKey>[] = [
  { key:'line', label:'#', defaultVisible:true },
  { key:'order', label:'订单号', defaultVisible:true },
  { key:'warehouse', label:'Stock 仓库', defaultVisible:true },
  { key:'carrier', label:'Carrier 承运商', defaultVisible:true },
  { key:'service', label:'运输 / 服务类型', defaultVisible:true },
  { key:'tracking', label:'追踪单号 Tracking No.', defaultVisible:true },
  { key:'note', label:'备注', defaultVisible:true },
  { key:'recipient', label:'收件人', defaultVisible:true },
  { key:'country', label:'国家', defaultVisible:true },
  { key:'items', label:'SKU / 数量', defaultVisible:true },
]
type ExportIssue = { order_id:number|null; order_number:string; reason:string }
type ExportPreflight = { total_count:number; valid_count:number; issue_count:number; issues:ExportIssue[]; token:string }
type TrackingImportRow = { row:number; order_id:number|null; order_number:string; tracking_number:string; carrier:string|null; shipped_at:string; shipped_time_source:'provider'|'apply'; provider_order_reference:string|null; provider_service:string|null; provider_status:string|null; status:'apply'|'noop'|'blocked'; message:string }
type TrackingImportPreview = { token:string; rows:TrackingImportRow[]; apply_count:number; noop_count:number; blocked_count:number }
const props = defineProps<{
  warehouseKey:string; warehouse:WarehouseOption|null; warehouseOptions:WarehouseOption[]; view:string
  rows:PageRows; viewCounts:Record<string,number>; canReview:boolean; canOperate:boolean; carrierOptions:CarrierOption[]; trackingImportPreview:TrackingImportPreview|null
}>()

const grid = reactive(useAccountGridPreferences<ColumnKey>({
  namespace:'grid.logistics.oversea.workbench.v1',
  definitions:columnDefinitions,
}))
const canEditRow = computed(() => props.canOperate || (props.canReview && props.view === 'review'))
const drafts = reactive<Record<number,{ note:string; carrier:string; service:string; tracking_number:string }>>({})
const trackingFileInput = ref<HTMLInputElement|null>(null)
const exportingOrders = ref(false)
const exportReview = ref<ExportPreflight|null>(null)
const trackingPreviewRows = computed(() => props.trackingImportPreview?.rows.slice(0, 20) ?? [])
const canUploadTrackingFile = computed(() => props.warehouseKey !== 'us-3sm')
const trackingFileHint = computed(() => props.warehouseKey === 'us-3sm'
  ? '世迈没有 Tracking 回传模板：仓库给出真实 Tracking 后，在订单行填写并点击“确认发货”。'
  : '上传当前仓库真实回传 XLSX；兼容旧 CSV。上传只生成预览，确认后才会标记已发货。')
const rowErrors = reactive<Record<number,string>>({})
const carrierOption = (name:string) => props.carrierOptions.find(row => row.value === name)
const serviceOptionsFor = (name:string) => carrierOption(name)?.services ?? []
const defaultServiceFor = (name:string, country?:string|null) => {
  const carrier = carrierOption(name)
  if (!carrier) return ''
  if (name === 'DHL' && String(country ?? '').toUpperCase() === 'DE') {
    return carrier.services.find(service => service.key === 'paket_domestic')?.value ?? carrier.default_service_value ?? ''
  }
  return carrier.default_service_value ?? ''
}
const draft = (row:Row) => drafts[row.order_id] ??= { note:row.note ?? '', carrier:row.carrier ?? '', service:row.service ?? defaultServiceFor(row.carrier ?? '', row.country), tracking_number:row.tracking_number ?? '' }
const viewTabs = [
  ['review','待审核'], ['pending','待发货'], ['shipped','已发货'], ['exceptions','异常订单'], ['all','全部订单'],
] as const


const pageTitle = computed(() => props.warehouse ? `海外仓发货 · ${props.warehouse.label}` : '海外仓发货 · 全部海外仓')
const basePath = computed(() => props.warehouse ? props.warehouse.url : '/logistics/oversea')
const switchView = (view:string) => router.visit(`${basePath.value}?view=${encodeURIComponent(view)}&page=1&per_page=${props.rows.per_page || 50}`, { preserveScroll:true, preserveState:true, only:['rows','view','viewCounts','warehouse','warehouseKey','warehouseOptions','canReview','canOperate','carrierOptions'] })
const switchWarehouse = (url:string) => router.visit(`${url}?view=${encodeURIComponent(props.view)}&page=1&per_page=${props.rows.per_page || 50}`, { preserveScroll:true, preserveState:true })
const saveDraft = (row:Row, payload:Record<string,string|number|null>) => router.patch(`/logistics/oversea/orders/${row.order_id}/draft`, payload, { preserveScroll:true, preserveState:true })
const changeWarehouse = (row:Row, warehouseId:number) => saveDraft(row, { warehouse_id:Number(warehouseId) })
const changeWarehouseFromEvent = (row:Row, event:Event) => changeWarehouse(row, Number((event.target as HTMLSelectElement).value))
const saveNote = (row:Row) => saveDraft(row, { note:draft(row).note.trim() || null })
const saveCarrier = (row:Row) => { const d=draft(row); d.service=defaultServiceFor(d.carrier, row.country); saveDraft(row, { carrier:d.carrier, service:d.service || null }) }
const saveService = (row:Row) => saveDraft(row, { carrier:draft(row).carrier, service:draft(row).service || null })
const saveTracking = (row:Row) => saveDraft(row, { tracking_number:draft(row).tracking_number.trim() || null })
const manualShipUsSm = (row:Row) => {
  const tracking = draft(row).tracking_number.trim()
  if (!tracking) { rowErrors[row.order_id] = '请先填写真实 Tracking'; return }
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/us-3sm/orders/${row.order_id}/manual-ship`, { tracking_number:tracking }, { preserveScroll:true })
}
// Read-only preflight must precede every browser download. The operator may
// continue with compliant orders and a complete exception sheet; invalid
// rows never become 'valid' provider records by clicking Continue.
const exportError = async (response:Response): Promise<string> => {
  try {
    const payload:unknown = await response.json()
    if (payload && typeof payload === 'object' && 'message' in payload && typeof payload.message === 'string') return payload.message
  } catch { /* non-JSON error responses get a stable, actionable fallback */ }
  return response.status === 403 ? '没有此仓库的下载权限。'
    : response.status === 401 || response.status === 419 ? '登录状态已失效，请重新登录。'
    : response.status === 409 ? '订单数据发生变化，请重新检查导出问题。'
    : response.status === 422 ? '导出校验未通过，请重新查看订单数据。'
    : '导出服务暂时不可用，请联系管理员查询日志。'
}
const downloadXlsx = async (accepted:ExportPreflight|null) => {
  const url = accepted
    ? `${basePath.value}/export?confirm=1&token=${encodeURIComponent(accepted.token)}`
    : `${basePath.value}/export`
  const response = await fetch(url, {
    credentials:'same-origin',
    headers:{ Accept:'application/json', 'X-Requested-With':'XMLHttpRequest' },
  })
  if (!response.ok) {
    const reason = await exportError(response)
    exportReview.value = null
    publishGlobalFeedback({
      state:response.status === 422 || response.status === 409 ? 'BLOCKED' : 'ERROR',
      title:'仓库下单表下载失败', message:reason,
      next:'请检查订单问题后重新下载；当前页面和订单数据未被更改。',
    })
    return
  }
  const contentType = (response.headers.get('content-type') || '').toLowerCase()
  if (!contentType.includes('spreadsheetml.sheet') && !contentType.includes('application/octet-stream')) {
    throw new Error('导出服务返回的不是 XLSX 文件，请检查登录状态。')
  }
  const contentDisposition = response.headers.get('content-disposition') || ''
  const suppliedName = contentDisposition.match(/filename\s*=\s*"?([^";]+)"?/i)?.[1] || ''
  const filename = /^[A-Za-z0-9._-]+\.xlsx$/i.test(suppliedName)
    ? suppliedName
    : `007-${props.warehouseKey}-orders.xlsx`
  const file = await response.blob()
  const objectUrl = URL.createObjectURL(file)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 30000)
  exportReview.value = null
  publishGlobalFeedback({
    state:'SUCCESS',
    title:accepted && accepted.issue_count ? '下单表及异常清单已下载' : '仓库下单表已开始下载',
    message:accepted && accepted.issue_count
      ? `可下单 ${accepted.valid_count} 笔，待修复 ${accepted.issue_count} 笔；异常订单未提交至仓库下单表。`
      : filename,
  })
}
const runExport = async (accepted:ExportPreflight|null = null) => {
  if (exportingOrders.value || !props.warehouse) return
  exportingOrders.value = true
  publishGlobalFeedback({
    state:'RUNNING', title:accepted ? '正在生成确认后的下单表' : '正在检查仓库下单表',
    message:props.warehouse.label,
  })
  try {
    if (accepted) {
      await downloadXlsx(accepted)
      return
    }
    exportReview.value = null
    const response = await fetch(`${basePath.value}/export/preview`, {
      credentials:'same-origin',
      headers:{ Accept:'application/json', 'X-Requested-With':'XMLHttpRequest' },
    })
    if (!response.ok) {
      publishGlobalFeedback({ state:'ERROR', title:'仓库下单表检查失败',
        message:await exportError(response), next:'请检查登录权限或联系管理员。' })
      return
    }
    const plan = await response.json() as ExportPreflight
    if (plan.issue_count > 0) {
      exportReview.value = plan
      publishGlobalFeedback({
        state:'BLOCKED', title:`发现 ${plan.issue_count} 笔问题订单`,
        message:`可导出 ${plan.valid_count} 笔；查看页面中的问题列表后自行决定。`,
        next:'可返回修改，也可下载合规订单及独立异常清单。',
      })
      return
    }
    await downloadXlsx(null)
  } catch (error) {
    publishGlobalFeedback({
      state:'ERROR', title:'仓库下单表下载失败',
      message:error instanceof Error && error.message.includes('XLSX') ? error.message : '网络或系统错误，未生成仓库下单表。',
      next:'无需离开当前页面，请检查网络后重试。',
    })
  } finally {
    exportingOrders.value = false
  }
}
const downloadOutboundFile = () => runExport()
const confirmReviewedExport = () => { if (exportReview.value) void runExport(exportReview.value) }
const cancelReviewedExport = () => { exportReview.value = null; publishGlobalFeedback({
  state:'INFO', title:'已返回订单修改', message:'未导出文件；可修正问题订单后重新检查。',
}) }
const chooseTrackingFile = () => trackingFileInput.value?.click()
const previewTrackingFile = (event:Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const data = new FormData()
  data.append('file', file)
  router.post(`${basePath.value}/tracking-import/preview`, data, { forceFormData:true, preserveScroll:true, onFinish:() => { input.value = '' } })
}
const applyTrackingPreview = () => {
  if (!props.trackingImportPreview) return
  router.post(`${basePath.value}/tracking-import/apply`, { token:props.trackingImportPreview.token }, { preserveScroll:true })
}
const trackingStatusText = (status:TrackingImportRow['status']) => status === 'apply' ? '可应用' : (status === 'noop' ? '重复跳过' : '阻断')
const approve = (row:Row) => {
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/orders/${row.order_id}/approve`, { note:draft(row).note.trim() || null }, { preserveScroll:true, preserveState:true })
}
const reopenReview = (row:Row) => router.post(`/logistics/oversea/orders/${row.order_id}/reopen-review`, {}, { preserveScroll:true })
const reject = (row:Row) => {
  const note = draft(row).note.trim()
  if (!note) { rowErrors[row.order_id] = '请先填写驳回原因'; return }
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/orders/${row.order_id}/reject`, { note }, { preserveScroll:true, preserveState:true })
}
const itemText = (row:Row) => row.items.map(item => `${item.sku ?? '无SKU'} × ${item.quantity}`).join(' / ') || '—'
const pageUrl = (page:number, perPage:number = props.rows.per_page || 50) => `${basePath.value}?view=${encodeURIComponent(props.view)}&page=${page}&per_page=${perPage}`
const goPage = (page:number) => {
  const next = Math.max(1, Math.min(props.rows.last_page || 1, page))
  router.visit(pageUrl(next), { preserveScroll:true, preserveState:true })
}
const changePerPage = (event:Event) => {
  const perPage = Number((event.target as HTMLSelectElement).value)
  router.visit(pageUrl(1, perPage), { preserveScroll:true, preserveState:true })
}
const jumpPage = ref('')
const submitJump = () => {
  const page = Number(jumpPage.value)
  if (!Number.isInteger(page)) return
  goPage(page)
}
const compactPages = computed<(number|string)[]>(() => {
  const last = props.rows.last_page || 1
  if (last <= 7) return Array.from({ length:last }, (_, index) => index + 1)
  const pages = new Set<number>([1, last])
  for (let page = props.rows.current_page - 2; page <= props.rows.current_page + 2; page++) {
    if (page > 1 && page < last) pages.add(page)
  }
  const ordered = [...pages].sort((a,b) => a-b)
  const result:(number|string)[] = []
  ordered.forEach((page, index) => {
    const previous = ordered[index - 1]
    if (index > 0 && previous !== undefined && page - previous > 1) result.push(`ellipsis-${previous}-${page}`)
    result.push(page)
  })
  return result
})
</script>

<template>
  <Head :title="`${pageTitle} · BYL ERP`" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','海外仓发货', warehouse?.label ?? '全部海外仓']">
    <section class="oversea-page">
      <div class="oversea-workbench">
        <aside class="warehouse-rail" aria-label="海外仓切换">
          <p class="warehouse-rail-title">海外仓</p>
          <button type="button" class="warehouse-link" :class="{ active: warehouseKey === 'all' }" @click="switchWarehouse('/logistics/oversea')">全部海外仓</button>
          <button v-for="option in warehouseOptions" :key="option.key" type="button" class="warehouse-link" :class="{ active: warehouseKey === option.key }" @click="switchWarehouse(option.url)">{{ option.label }}</button>
        </aside>
        <main class="oversea-main">
      <header class="oversea-heading">
        <div><h1>{{ pageTitle }}</h1><p>审核 Vita 分仓结果；通过后进入真实下单，驳回则退回运营重新分配。</p></div>
        <div class="oversea-file-actions" v-if="warehouse && view === 'pending'">
          <a class="file-button file-link" :href="`${basePath}/export`" :aria-busy="exportingOrders" @click.prevent="downloadOutboundFile">⇩ {{ exportingOrders ? '正在检查/生成…' : '下载仓库下单表' }}</a>
          <input ref="trackingFileInput" class="hidden-file" type="file" accept=".xlsx,.csv,.txt" @change="previewTrackingFile" />
          <button v-if="canOperate && canUploadTrackingFile" type="button" class="file-button primary" @click="chooseTrackingFile">⇧ 上传物流回传表</button>
        </div>
      </header>
      <section v-if="warehouse && view === 'pending' && exportReview" class="export-review-panel" aria-label="仓库导出问题检查">
        <div class="export-review-heading">
          <div>
            <h2>下单表检查完成：发现 {{ exportReview.issue_count }} 笔问题订单</h2>
            <p>本次共 {{ exportReview.total_count }} 笔，{{ exportReview.valid_count }} 笔符合仓库模板。问题订单不会写入正式仓库下单表，也不会被自动修改。</p>
          </div>
          <span class="export-review-count">{{ exportReview.issue_count }} 笔待处理</span>
        </div>
        <div class="export-review-list">
          <table><thead><tr><th>ERP 订单号</th><th>问题原因</th><th>操作</th></tr></thead>
          <tbody><tr v-for="(issue,index) in exportReview.issues" :key="`${index}-${issue.order_id}`">
            <td>{{ issue.order_number }}</td><td>{{ issue.reason }}</td>
            <td><a v-if="issue.order_id" :href="`/orders/${issue.order_id}`">查看订单</a><span v-else>—</span></td>
          </tr></tbody></table>
        </div>
        <p class="export-review-notice">选择“继续下载”：Excel 第一工作表仅含 {{ exportReview.valid_count }} 笔合规下单数据；第二工作表“异常订单-请勿提交仓库”保留全部 {{ exportReview.issue_count }} 笔异常及原因。请勿将异常表当作下单数据上传仓库。</p>
        <div class="export-review-actions">
          <button type="button" class="file-button" @click="cancelReviewedExport">返回修改</button>
          <button type="button" class="file-button primary" :disabled="exportingOrders" @click="confirmReviewedExport">仍然下载（{{ exportReview.valid_count }} 笔下单 + {{ exportReview.issue_count }} 笔异常）</button>
        </div>
      </section>
      <div v-if="warehouse && view === 'pending'" class="oversea-file-flow">
        <span>① 审核通过</span><b>→</b><span>② 下载仓库下单表</span><b>→</b><span>③ 上传仓库 / WMS</span><b>→</b><span>④ 仓库回传 Tracking</span><b>→</b><span>⑤ 上传 ERP 并确认发货</span>
        <small>{{ trackingFileHint }}</small>
      </div>

      <section v-if="warehouse && view === 'pending' && trackingImportPreview" class="tracking-preview">
        <div class="tracking-preview-head">
          <div>
            <strong>单号表预览</strong>
            <span>可应用 {{ trackingImportPreview.apply_count }} · 重复跳过 {{ trackingImportPreview.noop_count }} · 阻断 {{ trackingImportPreview.blocked_count }}</span>
          </div>
          <button type="button" class="file-button primary" :disabled="trackingImportPreview.blocked_count > 0 || trackingImportPreview.apply_count === 0" @click="applyTrackingPreview">确认导入并标记已发货</button>
        </div>
        <p class="tracking-hint">Tracking 是发货事实主键。仓库状态如“处理中 / Pending”不会阻止发货；有可靠发货时间则沿用，没有则以本次确认时间记为 shipped_at。阻断项必须修正后重新上传。</p>
        <div class="tracking-preview-table-wrap">
          <table class="tracking-preview-table">
            <thead><tr><th>行</th><th>订单号</th><th>Tracking</th><th>Carrier</th><th>仓库服务</th><th>仓库状态</th><th>发货时间</th><th>结果</th><th>说明</th></tr></thead>
            <tbody><tr v-for="row in trackingPreviewRows" :key="`${row.row}-${row.order_number}`"><td>{{ row.row }}</td><td>{{ row.order_number }}</td><td>{{ row.tracking_number }}</td><td>{{ row.carrier ?? '—' }}</td><td>{{ row.provider_service ?? '—' }}</td><td>{{ row.provider_status ?? '—' }}</td><td>{{ row.shipped_at }} <small>{{ row.shipped_time_source === 'provider' ? '仓库时间' : 'ERP确认时间' }}</small></td><td><span class="tracking-status" :class="row.status">{{ trackingStatusText(row.status) }}</span></td><td>{{ row.message || '—' }}</td></tr></tbody>
          </table>
        </div>
        <small v-if="trackingImportPreview.rows.length > trackingPreviewRows.length">仅展示前 {{ trackingPreviewRows.length }} 行；应用时仍会校验整份文件。</small>
      </section>

      <nav class="oversea-tabs" aria-label="海外仓状态视图">
        <button v-for="([key,label]) in viewTabs" :key="key" type="button" class="oversea-tab" :class="{ active:view === key }" @click="switchView(key)">
          {{ label }} <span>{{ viewCounts[key] ?? 0 }}</span>
        </button>
      </nav>

      <div class="oversea-table-wrap">
        <table class="oversea-table">
          <thead><tr>
            <th class="decision-col sticky-decision">审核</th>
            <th v-for="column in grid.visibleColumns" :key="column.key" :class="{ 'line-col':column.key === 'line' }">{{ column.label }}</th>
            <ColumnSettingsHeader :columns="grid.columns" :sync-state="grid.syncState" @toggle="grid.toggle" @start-drag="grid.startDrag" @drop="grid.drop" @reset="grid.reset" />
          </tr></thead>
          <tbody>
            <tr v-for="row in rows.data" :key="row.order_id">
              <td class="decision-cell sticky-decision">
                <template v-if="canReview && view === 'review'">
                  <button type="button" class="decision approve" @mousedown.prevent @click="approve(row)">通过</button>
                  <button type="button" class="decision reject" title="如需驳回，请先填写备注原因" @mousedown.prevent @click="reject(row)">驳回</button>
                </template>
                <div v-else-if="row.review_status === 'approved'" class="decision-approved"><span class="decision-state approved">已通过</span><button v-if="canOperate" type="button" class="reopen-review" @click="reopenReview(row)">重新审核</button></div>
                <span v-else class="decision-state">待审核</span>
              </td>
              <td v-for="column in grid.visibleColumns" :key="column.key" :class="{ 'line-col':column.key === 'line', 'tracking-cell':column.key === 'tracking', 'items-cell':column.key === 'items' }">
                <template v-if="column.key === 'line'">{{ row.line }}</template>
                <template v-else-if="column.key === 'order'"><a class="order-link" :href="row.order_url ?? `/orders/${row.order_id}`">{{ row.erp_order_id ?? '—' }}</a></template>
                <template v-else-if="column.key === 'warehouse'"><select :value="row.warehouse_id" :disabled="!canReview || view !== 'review'" class="cell-select warehouse" @change="changeWarehouseFromEvent(row, $event)"><option v-for="option in warehouseOptions" :key="option.id" :value="option.id">{{ option.label }}</option></select></template>
                <template v-else-if="column.key === 'carrier'"><select v-model="draft(row).carrier" :disabled="!canOperate || row.shipment_status === 'shipped'" class="cell-select carrier" @change="saveCarrier(row)"><option v-for="carrier in carrierOptions" :key="carrier.value" :value="carrier.value">{{ carrier.label }}</option></select></template>
                <template v-else-if="column.key === 'service'"><select v-model="draft(row).service" :disabled="!canOperate || row.shipment_status === 'shipped' || !draft(row).carrier" class="cell-select service" @change="saveService(row)"><option value="">请选择服务类型</option><option v-for="service in serviceOptionsFor(draft(row).carrier)" :key="service.id" :value="service.value">{{ service.label }}</option></select></template>
                <template v-else-if="column.key === 'tracking'"><div class="tracking-entry"><input v-model="draft(row).tracking_number" :disabled="!canOperate || row.shipment_status === 'shipped'" class="tracking-input" placeholder="填写发货单号" @blur="saveTracking(row)" /><button v-if="canOperate && warehouseKey === 'us-3sm' && view === 'pending' && row.review_status === 'approved' && row.shipment_status === 'pending'" type="button" class="manual-ship-button" @mousedown.prevent @click="manualShipUsSm(row)">确认发货</button></div></template>
                <template v-else-if="column.key === 'note'"><div class="note-cell"><input v-model="draft(row).note" :disabled="!canEditRow" class="note-input" :class="{ error: !!rowErrors[row.order_id] }" placeholder="等海运 / 等空运 / 缺货 / 太贵 / 送不到…" @input="rowErrors[row.order_id] = ''" @blur="view === 'review' ? undefined : saveNote(row)" /><small v-if="rowErrors[row.order_id]" class="note-error">{{ rowErrors[row.order_id] }}</small></div></template>
                <template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template>
                <template v-else-if="column.key === 'country'">{{ row.country ?? '—' }}</template>
                <template v-else>{{ itemText(row) }}</template>
              </td>
              <td class="settings-spacer" aria-hidden="true"></td>
            </tr>
            <tr v-if="!rows.data.length"><td :colspan="grid.visibleColumns.length + 2" class="empty-cell">当前视图暂无订单</td></tr>
          </tbody>
        </table>
      </div>

      <footer class="oversea-footer">
        <div class="pagination-summary">
          <span>共 {{ rows.total }} 条</span>
          <span>第 {{ rows.current_page }} / {{ rows.last_page }} 页</span>
          <span v-if="rows.from !== null && rows.to !== null">{{ rows.from }}–{{ rows.to }} / {{ rows.total }}</span>
        </div>
        <div class="pagination-toolbar">
          <label class="page-size">每页
            <select :value="rows.per_page" @change="changePerPage">
              <option :value="20">20</option><option :value="50">50</option><option :value="100">100</option>
            </select> 条
          </label>
          <div class="pagination" v-if="rows.last_page > 1">
            <button type="button" :disabled="rows.current_page <= 1" @click="goPage(1)">首页</button>
            <button type="button" :disabled="rows.current_page <= 1" @click="goPage(rows.current_page - 1)">上一页</button>
            <template v-for="item in compactPages" :key="item">
              <button v-if="typeof item === 'number'" type="button" :class="{ active:item === rows.current_page }" @click="goPage(item)">{{ item }}</button>
              <span v-else class="page-ellipsis">…</span>
            </template>
            <button type="button" :disabled="rows.current_page >= rows.last_page" @click="goPage(rows.current_page + 1)">下一页</button>
            <button type="button" :disabled="rows.current_page >= rows.last_page" @click="goPage(rows.last_page)">末页</button>
          </div>
          <label class="page-jump">跳至第
            <input v-model="jumpPage" inputmode="numeric" aria-label="跳转页码" @keyup.enter="submitJump" />
            页 <button type="button" @click="submitJump">跳转</button>
          </label>
        </div>
      </footer>
        </main>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.export-review-panel{margin:0 0 14px;padding:14px;border:1px solid #e2c288;border-radius:10px;background:#fffbf1;color:#334155}
.export-review-heading{display:flex;justify-content:space-between;gap:14px;align-items:start}
.export-review-heading h2{margin:0;font-size:17px;color:#854d0e}
.export-review-heading p{margin:5px 0;font-size:13px}
.export-review-count{white-space:nowrap;font-size:12px;color:#92400e;font-weight:800}
.export-review-list{max-height:245px;overflow:auto;border:1px solid #eadab6;border-radius:6px;background:#fff}
.export-review-list table{width:100%;border-collapse:collapse;font-size:12px}
.export-review-list th,.export-review-list td{padding:7px 10px;border-bottom:1px solid #f0e5cf;text-align:left}
.export-review-list th{position:sticky;top:0;background:#fff9e9}
.export-review-list a{color:#0f766e}
.export-review-notice{font-size:12px;margin:10px 0;color:#854d0e}
.export-review-actions{display:flex;justify-content:flex-end;gap:8px}

.oversea-page{padding:18px 22px 28px 8px;min-width:0}.oversea-workbench{display:grid;grid-template-columns:124px minmax(0,1fr);gap:10px;align-items:start}.oversea-main{min-width:0}.warehouse-rail{position:sticky;top:12px;border:1px solid #dfe6ea;border-radius:8px;background:#fff;padding:6px 5px}.warehouse-rail-title{margin:2px 6px 5px;color:#64748b;font-size:11px;font-weight:800;letter-spacing:.06em}.warehouse-link{width:100%;border:0;background:transparent;border-radius:6px;padding:7px 6px;text-align:left;color:#475569;font-size:12px;font-weight:700;white-space:nowrap}.warehouse-link:hover{background:#f1f5f9}.warehouse-link.active{background:#e7f6f3;color:#0f766e}.oversea-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:12px}.oversea-heading h1{margin:0;color:#132238;font-size:28px;line-height:1.2}.oversea-heading p{margin:6px 0 0;color:#64748b;font-size:14px}
.oversea-file-actions{display:flex;gap:10px}.hidden-file{display:none}.file-button{height:40px;padding:0 16px;border:1px solid #0f8f82;border-radius:8px;background:white;color:#0d796f;font-weight:700}.file-button.primary{background:#0f8f82;color:white}.file-link{display:inline-flex;align-items:center;text-decoration:none}.file-button:disabled{opacity:.45;cursor:not-allowed}.oversea-file-flow{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:-2px 0 12px;padding:9px 11px;border:1px solid #dbe7e5;border-radius:8px;background:#f5fbfa;color:#395d59;font-size:12px}.oversea-file-flow b{color:#94a3b8}.oversea-file-flow small{flex-basis:100%;color:#64748b}
.tracking-preview{margin:0 0 14px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.tracking-preview-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.tracking-preview-head>div{display:flex;flex-direction:column;gap:3px}.tracking-preview-head strong{color:#0f172a}.tracking-preview-head span,.tracking-hint,.tracking-preview small{color:#64748b;font-size:12px}.tracking-hint{margin:8px 0}.tracking-preview-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff}.tracking-preview-table{width:100%;min-width:980px;border-collapse:collapse;font-size:12px}.tracking-preview-table th,.tracking-preview-table td{padding:7px 8px;border-bottom:1px solid #edf2f7;text-align:left;white-space:nowrap}.tracking-preview-table th{background:#f8fafc;color:#475569}.tracking-status{font-weight:800}.tracking-status.apply{color:#047857}.tracking-status.noop{color:#64748b}.tracking-status.blocked{color:#b91c1c}
.oversea-tabs{display:flex;gap:6px;align-items:center;margin:0 0 12px;border-bottom:1px solid #e2e8f0;overflow-x:auto}.oversea-tab{border:0;background:transparent;padding:9px 14px 8px;color:#536174;font-weight:700;white-space:nowrap;border-bottom:2px solid transparent}.oversea-tab span{margin-left:4px;color:#94a3b8;font-weight:600}.oversea-tab.active{color:#0f766e;border-bottom-color:#0f766e}.oversea-tab.active span{color:#0f766e}
.oversea-table-wrap{overflow:auto;border:1px solid #dfe6ea;border-radius:10px;background:white}.oversea-table{width:max-content;min-width:1760px;border-collapse:separate;border-spacing:0;font-size:13px}.oversea-table th{padding:7px 8px;background:#f8fafc;color:#425466;font-weight:700;text-align:left;border-right:1px solid #e5eaee;border-bottom:1px solid #dfe6ea;white-space:nowrap}.oversea-table td{padding:3px 7px;border-right:1px solid #edf1f4;border-bottom:1px solid #e7ecef;vertical-align:middle;white-space:nowrap;background:#fff}.oversea-table tr:last-child td{border-bottom:0}.decision-col{width:118px;min-width:118px}.sticky-decision{position:sticky;left:0;z-index:4;background:#fff!important;box-shadow:6px 0 10px rgba(15,23,42,.06)}th.sticky-decision{z-index:6;background:#f8fafc!important}.line-col{width:44px;text-align:center!important;color:#64748b}.decision-cell{white-space:nowrap}.settings-spacer{width:46px;min-width:46px}.decision{border:1px solid transparent;border-radius:6px;padding:5px 9px;font-weight:700;margin-right:4px}.decision.approve{background:#0ca678;color:white}.decision.reject{background:white;color:#d33;border-color:#efb6b6}.decision-state{font-weight:700;color:#a16207}.decision-state.approved{color:#087f5b}.decision-approved{display:flex;flex-direction:column;align-items:flex-start;gap:2px}.reopen-review{border:1px solid #99cfc7;background:#fff;color:#0f766e;border-radius:6px;padding:2px 7px;font-size:11px;font-weight:700}
.cell-select,.note-input{width:100%;min-height:30px;border:1px solid #d7e0e5;border-radius:6px;background:white;color:#334155;padding:3px 7px}.cell-select:disabled,.note-input:disabled{background:#f8fafc;color:#64748b}.cell-select.warehouse{min-width:135px}.carrier-cell{display:flex;flex-direction:column;gap:3px;min-width:150px}.carrier-cell small{color:#94a3b8;font-size:11px}.cell-select.carrier{min-width:145px}.tracking-cell{min-width:240px}.tracking-entry{display:flex;align-items:center;gap:6px}.tracking-input{width:100%;min-width:220px;min-height:30px;border:1px solid #d7e0e5;border-radius:6px;background:#fff;color:#334155;padding:3px 7px}.tracking-input:disabled{background:#f8fafc;color:#64748b}.manual-ship-button{min-height:30px;white-space:nowrap;border:1px solid #0f766e;border-radius:6px;background:#0f766e;color:#fff;font-weight:700;padding:3px 9px;cursor:pointer}.note-cell{display:flex;flex-direction:column;gap:4px}.note-input{min-width:260px}.note-input.error{border-color:#dc2626}.note-error{color:#dc2626;font-size:11px}.order-link{display:inline-block;min-width:180px;color:#0f766e;font-weight:700;text-decoration:none;white-space:nowrap}.order-link:hover{text-decoration:underline}.items-cell{min-width:180px;color:#475569}.empty-cell{text-align:center!important;color:#94a3b8;padding:30px!important}
.oversea-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;max-width:100%;padding:14px 2px;color:#64748b}.pagination-summary{display:flex;gap:10px;flex-wrap:wrap;font-size:12px}.pagination-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;min-width:0;max-width:100%}.page-size,.page-jump{display:flex;align-items:center;gap:5px;white-space:nowrap;font-size:12px}.page-size select,.page-jump input{height:32px;border:1px solid #d7e0e5;border-radius:6px;background:#fff;color:#475569;padding:0 7px}.page-jump input{width:58px}.page-jump button,.pagination button{border:1px solid #d7e0e5;background:white;border-radius:6px;min-width:32px;height:32px;padding:0 8px;color:#475569;white-space:nowrap}.pagination{display:flex;align-items:center;gap:5px;flex-wrap:wrap;min-width:0}.pagination button:disabled,.page-jump button:disabled{opacity:.45;cursor:not-allowed}.pagination button.active{background:#0f766e;border-color:#0f766e;color:white}.page-ellipsis{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:32px;color:#94a3b8}
@media (max-width:900px){.oversea-workbench{grid-template-columns:1fr}.warehouse-rail{position:static;display:flex;gap:6px;overflow-x:auto}.warehouse-rail-title{display:none}.warehouse-link{width:auto;flex:0 0 auto}.oversea-heading{flex-direction:column}.oversea-file-actions{align-self:stretch}.file-button{flex:1}.oversea-page{padding:14px}}
</style>
