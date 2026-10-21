<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Item = { sku:string|null; quantity:number }
type Row = {
  line:number; order_id:number; erp_order_id:string|null; provider_reference:string|null; order_url:string
  recipient_name:string|null; phone:string|null; country:string|null; state:string|null; city:string|null; postal_code:string|null
  carrier:string|null; service:string|null; tracking_number:string|null; shipment_status:string|null; shipped_at:string|null
  review_status:'approved'|'pending'; export_blockers:string[]; items:Item[]
}
type PageRows = { data:Row[]; current_page:number; last_page:number; total:number }
type PreviewRow = { row:number; order_id:number|null; order_number:string; tracking_number:string; carrier:string; provider_status:string|null; status:string; message:string }
type Preview = { token:string; rows:PreviewRow[]; apply_count:number; noop_count:number; blocked_count:number }
type ProviderStatus = Record<'4px'|'weship',{export_ready:boolean;return_ready:boolean;label:string;note?:string;reason?:string}>

const props = defineProps<{
  view:string; rows:PageRows; viewCounts:Record<string,number>; canOperate:boolean
  trackingImportProvider:'4px'|'weship'|'generic'|null; trackingImportPreview:Preview|null; providerStatus:ProviderStatus
}>()

const uploadInput = ref<HTMLInputElement|null>(null)
const selectedProvider = ref<'4px'|'weship'|'generic'>('4px')
const tabs = [
  ['review','待审核'], ['pending','待发货'], ['today-4px','今日速达'], ['today-weship','今日纬狮'],
  ['exceptions','异常订单'], ['today','今日订单'], ['4px','速达订单'], ['weship','纬狮订单'],
] as const
const previewRows = computed(() => props.trackingImportPreview?.rows.slice(0, 30) ?? [])
const itemText = (row:Row) => row.items.map(item => (item.sku ?? '无SKU') + ' × ' + item.quantity).join(' / ') || '—'
const switchView = (view:string) => router.visit('/logistics/china?view=' + view, { preserveScroll:true, preserveState:true })
const approve = (row:Row) => router.post('/logistics/china/orders/' + row.order_id + '/approve', {}, { preserveScroll:true, preserveState:true })
const reopen = (row:Row) => router.post('/logistics/china/orders/' + row.order_id + '/reopen-review', {}, { preserveScroll:true, preserveState:true })
const chooseUpload = (provider:'4px'|'weship'|'generic') => { selectedProvider.value=provider; uploadInput.value?.click() }
const previewFile = (event:Event) => {
  const input=event.target as HTMLInputElement
  const file=input.files?.[0]; if(!file) return
  const data=new FormData(); data.append('file',file)
  const url=selectedProvider.value==='generic' ? '/logistics/china/pending-batch/tracking-import/preview' : '/logistics/china/' + selectedProvider.value + '/tracking-import/preview'
  router.post(url, data, { forceFormData:true, preserveScroll:true, onFinish:()=>{ input.value='' } })
}
const applyPreview = () => {
  if(!props.trackingImportPreview || !props.trackingImportProvider) return
  const url=props.trackingImportProvider==='generic' ? '/logistics/china/pending-batch/tracking-import/apply' : '/logistics/china/' + props.trackingImportProvider + '/tracking-import/apply'
  router.post(url, { token:props.trackingImportPreview.token }, { preserveScroll:true })
}
const statusLabel = (status:string) => status==='apply' ? '可应用' : status==='noop' ? '重复跳过' : status==='applied' ? '已应用' : '阻断'
const previewLabel = computed(() => props.trackingImportProvider==='generic' ? '待发货批量登记' : props.trackingImportProvider ? props.providerStatus[props.trackingImportProvider].label : '')
</script>

<template>
  <Head title="国内发货 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','国内发货']">
    <section class="domestic-page">
      <header class="domestic-head">
        <div>
          <p class="eyebrow">FACTORY · 中国工厂</p>
          <h1>国内发货</h1>
          <p>Vita 分仓 → Liang / Kaven 审核 → 下载承运商文件 → 回传 Tracking → Preview → Apply。单个异常订单不会阻断其他正常订单。</p>
        </div>
        <a class="secondary" href="/logistics/wait-stock">待分配 →</a>
      </header>

      <section class="continuity">
        <strong>生产连续性</strong>
        <span>正常订单继续发货；缺电话 / 单号冲突 / Tracking 冲突只阻断对应订单，不阻断整批。</span>
      </section>

      <nav class="tabs">
        <button v-for="([key,label]) in tabs" :key="key" type="button" :class="{active:view===key}" @click="switchView(key)">
          {{ label }} <span>{{ viewCounts[key] ?? 0 }}</span>
        </button>
      </nav>

      <section v-if="view==='pending'" class="pending-batch-card">
        <div>
          <strong>待发货 · 批量登记</strong>
          <small>ERP 通用 XLSX，可混合承运商。下载后填写 Tracking 再上传；仅用于 ERP 发货登记，不替代承运商自己的下单模板。</small>
        </div>
        <div class="actions">
          <a class="primary" href="/logistics/china/pending-batch/export">下载待发货批量表</a>
          <button v-if="canOperate" type="button" class="secondary" @click="chooseUpload('generic')">上传批量单号表</button>
        </div>
      </section>

      <section v-if="view==='today' || view==='today-4px' || view==='today-weship'" class="provider-grid" :class="{single:view==='today-4px' || view==='today-weship'}">
        <article v-if="view!=='today-weship'" class="provider-card">
          <div><strong>速达 / 4PX</strong><small>真实 24 列模板 · XLSX 下载 · XLS/XLSX 回传</small></div>
          <div class="actions">
            <a class="primary" href="/logistics/china/export/4px">下载速达下单表</a>
            <button v-if="canOperate" type="button" class="secondary" @click="chooseUpload('4px')">上传速达单号表</button>
          </div>
        </article>
        <article v-if="view!=='today-4px'" class="provider-card">
          <div><strong>纬狮 / Weship</strong><small>{{ providerStatus.weship.export_ready ? (providerStatus.weship.note ?? '下单导出可用') : providerStatus.weship.reason }}</small></div>
          <div class="actions">
            <a v-if="providerStatus.weship.export_ready" class="primary" href="/logistics/china/export/weship">下载纬狮下单表</a>
            <button v-if="canOperate && providerStatus.weship.return_ready" type="button" class="secondary" @click="chooseUpload('weship')">上传纬狮单号表</button>
          </div>
        </article>
        <input ref="uploadInput" class="hidden" type="file" accept=".xls,.xlsx" @change="previewFile" />
      </section>

      <section v-if="trackingImportPreview && trackingImportProvider" class="preview">
        <div class="preview-head">
          <div><strong>{{ previewLabel }} · 回传预览</strong><span>可应用 {{ trackingImportPreview.apply_count }} · 重复 {{ trackingImportPreview.noop_count }} · 阻断 {{ trackingImportPreview.blocked_count }}</span></div>
          <button v-if="canOperate && trackingImportPreview.apply_count>0" class="primary" type="button" @click="applyPreview">应用可安全行并标记发货</button>
        </div>
        <p>即使存在阻断行，也只跳过阻断行；可安全行仍会逐单提交 Shipment，并逐单尝试 Woo Tracking 同步。</p>
        <div class="table-wrap">
          <table><thead><tr><th>行</th><th>订单号</th><th>Tracking</th><th>Carrier</th><th>Provider 状态</th><th>结果</th><th>说明</th></tr></thead>
          <tbody><tr v-for="row in previewRows" :key="row.row + '-' + row.order_number">
            <td>{{ row.row }}</td><td>{{ row.order_number }}</td><td>{{ row.tracking_number || '—' }}</td><td>{{ row.carrier }}</td><td>{{ row.provider_status ?? '—' }}</td>
            <td><b :class="'status-' + row.status">{{ statusLabel(row.status) }}</b></td><td>{{ row.message || '—' }}</td>
          </tr></tbody></table>
        </div>
      </section>

      <div class="table-wrap orders">
        <table>
          <thead><tr><th>#</th><th>审核</th><th>ERP 订单</th><th>导出状态</th><th>承运商单号</th><th>Carrier</th><th>服务</th><th>Tracking</th><th>收件人</th><th>电话</th><th>国家/地区</th><th>SKU / 数量</th></tr></thead>
          <tbody>
            <tr v-for="row in rows.data" :key="row.order_id">
              <td>{{ row.line }}</td>
              <td>
                <button v-if="canOperate && row.review_status==='pending' && row.shipment_status==='pending'" class="approve" type="button" @click="approve(row)">通过</button>
                <div v-else-if="row.review_status==='approved'" class="approved">已通过 <button v-if="canOperate && row.shipment_status==='pending'" type="button" @click="reopen(row)">重新审核</button></div>
                <span v-else>—</span>
              </td>
              <td><a :href="row.order_url">{{ row.erp_order_id ?? '—' }}</a></td>
              <td><span v-if="row.export_blockers.length" class="danger">{{ row.export_blockers.join(' / ') }}</span><span v-else class="ready">可导出</span></td>
              <td>{{ row.provider_reference ?? '—' }}</td><td>{{ row.carrier ?? '—' }}</td><td>{{ row.service ?? '—' }}</td><td>{{ row.tracking_number ?? '—' }}</td>
              <td>{{ row.recipient_name ?? '—' }}</td><td :class="{danger:!row.phone}">{{ row.phone || '缺电话' }}</td>
              <td>{{ [row.country,row.state,row.city].filter(Boolean).join(' / ') || '—' }}</td><td>{{ itemText(row) }}</td>
            </tr>
            <tr v-if="!rows.data.length"><td colspan="12" class="empty">当前视图暂无订单</td></tr>
          </tbody>
        </table>
      </div>
      <footer>共 {{ rows.total }} 条</footer>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.domestic-page{padding:20px;min-width:0;color:#111827}.domestic-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.eyebrow{margin:0;color:#0f766e;font-size:11px;font-weight:800;letter-spacing:.08em}.domestic-head h1{margin:4px 0;font-size:30px}.domestic-head p{margin:0;color:#64748b;font-size:13px}.continuity{display:flex;gap:12px;align-items:center;margin:14px 0;padding:10px 12px;border:1px solid #b7ded8;border-radius:9px;background:#f2fbf9;color:#315f59;font-size:12px}.continuity strong{color:#0f766e}.tabs{display:flex;gap:5px;overflow-x:auto;border-bottom:1px solid #e2e8f0;margin-bottom:12px}.tabs button{border:0;background:transparent;padding:9px 12px;color:#536174;font-weight:700;white-space:nowrap;border-bottom:2px solid transparent}.tabs button.active{color:#0f766e;border-bottom-color:#0f766e}.tabs span{color:#94a3b8;margin-left:3px}.pending-batch-card{display:flex;justify-content:space-between;gap:12px;margin-bottom:12px;padding:12px;border:1px solid #b7ded8;border-radius:10px;background:#f2fbf9}.pending-batch-card>div:first-child{display:flex;flex-direction:column;gap:4px}.pending-batch-card small{color:#64748b;max-width:760px}
.provider-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}.provider-grid.single{grid-template-columns:1fr}.provider-card{display:flex;justify-content:space-between;gap:10px;padding:12px;border:1px solid #dbe4e8;border-radius:10px;background:#fff}.provider-card>div:first-child{display:flex;flex-direction:column;gap:4px}.provider-card small{color:#64748b}.actions{display:flex;gap:7px;align-items:center}.primary,.secondary,.approve,.approved button{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 11px;border-radius:7px;font-size:12px;font-weight:800;text-decoration:none}.primary,.approve{border:1px solid #0f8f82;background:#0f8f82;color:#fff}.secondary,.approved button{border:1px solid #b8c6cc;background:#fff;color:#334155}.hidden{display:none}.preview{margin-bottom:12px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.preview-head{display:flex;justify-content:space-between;gap:12px}.preview-head>div{display:flex;flex-direction:column}.preview p{color:#64748b;font-size:12px}.table-wrap{overflow:auto;border:1px solid #dfe6ea;border-radius:10px;background:#fff}.table-wrap table{width:max-content;min-width:100%;border-collapse:collapse;font-size:12px}.table-wrap th,.table-wrap td{padding:8px;border-bottom:1px solid #e9eef1;text-align:left;white-space:nowrap}.table-wrap th{background:#f8fafc;color:#475569}.orders table{min-width:1500px}.orders a{color:#0f766e;font-weight:800;text-decoration:none}.approved{display:flex;gap:6px;align-items:center;color:#047857;font-weight:800}.approved button{min-height:26px;padding:0 7px;font-size:10px}.danger{color:#b91c1c;font-weight:800}.ready{color:#047857;font-weight:800}.empty{text-align:center!important;padding:30px!important;color:#94a3b8}.status-apply{color:#047857}.status-noop{color:#64748b}.status-blocked{color:#b91c1c}footer{padding:10px 2px;color:#64748b;font-size:12px}
@media(max-width:800px){.domestic-page{padding:14px}.domestic-head{flex-direction:column}.pending-batch-card{flex-direction:column}.provider-grid{grid-template-columns:1fr}.provider-card{flex-direction:column}.continuity{align-items:flex-start;flex-direction:column}.preview-head{flex-direction:column}}
</style>
