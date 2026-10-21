<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { reactive, ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type Row = { id:number; source_row_number:number; source_order_no:string|null; kol:{id:number;display_name:string;url:string}|null; carrier:string|null; tracking:string|null; tracking_raw:string|null; warehouse:string|null; classification:'ready'|'review'|'skip'; reason:string }
type Pagination = { current_page:number; last_page:number; per_page:number; total:number }
type Batch = { id:number; filename:string; checksum:string; sheet:string; reconciliation_batch_id:number|null; status:string; total_rows:number; ready_count:number; review_count:number; skip_count:number; rows:Row[]; pagination:Pagination }
type RescueGroup = { reason:string; rows:number; evidence:number }
type ReviewRescue = { rescueable_rows:number; candidate_evidence_count:number; existing_evidence_count:number; pending_evidence_count:number; manual_review_rows:number; groups:RescueGroup[] }
type AttemptRow = { id:number; order_id:number; order_url:string; erp_order_id:string|null; source_order_no:string|null; source_row_number:number; attempt_number:number; tracking_number:string|null; warehouse_code:string|null; warehouse_raw:string|null; warehouse_resolution:string; raw_agent:string|null; decision:string; reason:string }
type AttemptColumn = 'order_number' | 'source_order' | 'source_row' | 'attempt' | 'tracking' | 'warehouse' | 'agent' | 'status'
const attemptDefinitions: GridColumnDefinition<AttemptColumn>[] = [
  { key: 'order_number', label: 'ERP订单' }, { key: 'source_order', label: '历史订单号' }, { key: 'source_row', label: '源行' }, { key: 'attempt', label: 'Attempt' },
  { key: 'tracking', label: 'Tracking' }, { key: 'warehouse', label: 'Warehouse' }, { key: 'agent', label: '原始 Agent' }, { key: 'status', label: '状态' },
]
const attemptGrid = reactive(useAccountGridPreferences<AttemptColumn>({ namespace: 'grid.logistics.historical-import.attempts.v1', definitions: attemptDefinitions, requiredKeys: ['order_number'] }))
type AttemptPage = { data:AttemptRow[]; current_page:number; last_page:number; per_page:number; total:number; filters:{q:string;decision:string;warehouse:string} }
type ReviewRow = { id:number; source_row_number:number; source_order_no:string|null; reason:string; tracking_leg_count:number }
type ControlCenter = {
  summary:{ source_rows:number; evidences:number; historical_orders:number; original_attempts:number; rescue_orders:number; rescue_tracking_legs:number; permanent_review:number; historical_attempts_total:number; active_shipment_contamination:number };
  r5:{ batch_id:number|null; status:string; total:number; ready:number; review:number; applied:number; manifest_sha256:string|null; stored_manifest_sha256:string|null; fresh_sha_matches:boolean; global_apply_gate:boolean; can_apply:boolean; confirmation:string|null; warehouse:{exact:number;unresolved:number;master_missing:number}; unique_tracking:number; review_reasons:{reason:string;count:number}[] };
  attempts:AttemptPage; permanent_review_rows:ReviewRow[]; latest_backup:{id:number;uuid:string;state:string;created_at:string|null;checksum:string|null;size:number|null}|null
}
const props = defineProps<{ batch: Batch|null; commitEnabled:boolean; reviewRescue:ReviewRescue|null; rescueCommitEnabled:boolean; controlCenter:ControlCenter|null; canOperate:boolean; canViewOrders:boolean }>()
const batch = ref<Batch|null>(props.batch)
const reviewRescue = ref<ReviewRescue|null>(props.reviewRescue)
const file = ref<File|null>(null)
const busy = ref(false)
const error = ref('')
const r5Busy = ref(false)
const attemptQ = ref(props.controlCenter?.attempts.filters.q ?? '')
watch(() => props.batch, (value) => { batch.value = value })
watch(() => props.reviewRescue, (value) => { reviewRescue.value = value })
const choose = (event: Event) => { file.value = (event.target as HTMLInputElement).files?.[0] ?? null }
const preview = async () => {
  if (!file.value) return
  busy.value = true; error.value = ''
  const form = new FormData(); form.append('file', file.value)
  try { const data = (await axios.post('/logistics/historical-import/preview', form)).data; batch.value = data.batch; reviewRescue.value = data.reviewRescue }
  catch (e:any) { error.value = e.response?.data?.message ?? '预览失败' }
  finally { busy.value = false }
}
const commitEvidence = async () => {
  if (!batch.value || !props.commitEnabled || !props.canOperate) return
  busy.value = true; error.value = ''
  try { const data = (await axios.post(`/logistics/historical-import/${batch.value.id}/commit`)).data; batch.value = data.batch; reviewRescue.value = data.reviewRescue }
  catch (e:any) { error.value = e.response?.data?.message ?? '证据提交失败' }
  finally { busy.value = false }
}
const commitReviewRescue = async () => {
  if (!batch.value || !reviewRescue.value || !props.rescueCommitEnabled || !props.canOperate || reviewRescue.value.pending_evidence_count <= 0) return
  busy.value = true; error.value = ''
  try { const data = (await axios.post(`/logistics/historical-import/${batch.value.id}/review-rescue/commit`)).data; batch.value = data.batch; reviewRescue.value = data.reviewRescue }
  catch (e:any) { error.value = e.response?.data?.message ?? 'Review Rescue 提交失败' }
  finally { busy.value = false }
}
const goToPage = (page:number) => {
  if (!batch.value || page < 1 || page > batch.value.pagination.last_page) return
  router.get('/logistics/historical-import', { page }, { preserveState: true, replace: true, preserveScroll: true })
}
const label = (value:string) => ({ ready:'Ready · 可提交', review:'Review · 需复核', skip:'Skip · 跳过' }[value] ?? value)
const rescueReasonLabel = (value:string) => ({
  multiple_tracking_candidates:'Multi Tracking', suspicious_compound_tracking:'粘连单号', duplicate_source_order_no:'重复 Order',
  replacement_or_transfer_ambiguity:'转单 / 改号', carrier_requires_review:'Carrier 原样保留',
}[value] ?? value)

const freezeR5 = () => {
  if (!batch.value || !props.canOperate || r5Busy.value) return
  router.post(`/logistics/historical-import/${batch.value.id}/rescue-attempts/preview`, {}, {
    preserveScroll: true, onStart: () => { r5Busy.value = true }, onFinish: () => { r5Busy.value = false },
  })
}
const applyR5 = () => {
  const center = props.controlCenter
  if (!batch.value || !center?.r5.batch_id || !center.r5.can_apply || !center.r5.manifest_sha256 || !center.r5.confirmation || !props.canOperate || r5Busy.value) return
  const ok = window.confirm(`确认写入 ${center.r5.ready} 条 Historical ShipmentAttempts？系统会先自动创建数据库备份；不会创建 Active Shipment。`)
  if (!ok) return
  router.post(`/logistics/historical-import/${batch.value.id}/rescue-attempts/${center.r5.batch_id}/apply`, {
    ready_manifest_sha256: center.r5.manifest_sha256, confirmation: center.r5.confirmation,
  }, { preserveScroll: true, onStart: () => { r5Busy.value = true }, onFinish: () => { r5Busy.value = false } })
}
const applyAttemptFilters = () => {
  router.get('/logistics/historical-import', {
    page: batch.value?.pagination.current_page ?? 1, attempt_page: 1, attempt_q: attemptQ.value,
    attempt_decision: props.controlCenter?.attempts.filters.decision ?? 'all',
    attempt_warehouse: props.controlCenter?.attempts.filters.warehouse ?? 'all',
  }, { preserveState: true, replace: true, preserveScroll: true })
}
const setAttemptFilter = (key:'decision'|'warehouse', value:string) => {
  const filters = props.controlCenter?.attempts.filters
  router.get('/logistics/historical-import', {
    page: batch.value?.pagination.current_page ?? 1, attempt_page: 1, attempt_q: attemptQ.value,
    attempt_decision: key === 'decision' ? value : (filters?.decision ?? 'all'),
    attempt_warehouse: key === 'warehouse' ? value : (filters?.warehouse ?? 'all'),
  }, { preserveState: true, replace: true, preserveScroll: true })
}
const goAttemptPage = (page:number) => {
  const attempts = props.controlCenter?.attempts
  if (!attempts || page < 1 || page > attempts.last_page) return
  router.get('/logistics/historical-import', {
    page: batch.value?.pagination.current_page ?? 1, attempt_page: page, attempt_q: attemptQ.value,
    attempt_decision: attempts.filters.decision, attempt_warehouse: attempts.filters.warehouse,
  }, { preserveState: true, replace: true, preserveScroll: true })
}
const r5StatusLabel = (value:string) => ({ not_previewed:'待冻结 Preview', preview:'Preview 已冻结', applied:'已 Apply' }[value] ?? value)
</script>

<template>
  <Head title="历史物流控制台 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '历史物流控制台']">
    <section class="mvp-page historical-import-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">Historical Logistics · Owner UAT</p><h2>历史物流控制台</h2><p>先看真实总账和逐条 Preview，再由 Owner 在 ERP 页面批准写入；不再依赖聊天里的 pass。</p></div>
        <div v-if="canOperate" class="mvp-actions">
          <label class="file-control"><input type="file" accept=".xlsx" :disabled="busy" @change="choose"><span>{{ file?.name ?? '选择 XLSX' }}</span></label>
          <button class="mvp-button mvp-button-primary" :disabled="!file || busy" @click="preview">{{ busy ? '处理中…' : '生成预览' }}</button>
          <button v-if="batch" class="mvp-button" :disabled="!commitEnabled || busy || batch.status === 'committed'" @click="commitEvidence">{{ batch.status === 'committed' ? '证据已提交' : '提交 Ready 证据' }}</button>
        </div>
      </div>
      <p v-if="error" class="mvp-error" role="alert">{{ error }}</p>

      <section v-if="controlCenter" class="owner-control-center">
        <div class="owner-title"><div><small>Canonical Reconciliation</small><h3>Historical Logistics 总账</h3><p>所有数字均来自当前 ERP 数据库；Active Shipment contamination 必须保持 0。</p></div><span :class="['owner-health', controlCenter.summary.active_shipment_contamination === 0 ? 'ok' : 'bad']">{{ controlCenter.summary.active_shipment_contamination === 0 ? '运营隔离 PASS' : '发现污染' }}</span></div>
        <div class="owner-summary">
          <div><small>源记录</small><strong>{{ controlCenter.summary.source_rows }}</strong><span>Original XLSX rows</span></div>
          <div><small>Historical Evidence</small><strong>{{ controlCenter.summary.evidences }}</strong><span>已保存证据</span></div>
          <div><small>Historical Orders</small><strong>{{ controlCenter.summary.historical_orders }}</strong><span>exact historical refs</span></div>
          <div><small>Original Attempts</small><strong>{{ controlCenter.summary.original_attempts }}</strong><span>第一阶段已物化</span></div>
          <div><small>Rescue Orders</small><strong>{{ controlCenter.summary.rescue_orders }}</strong><span>R4 已物化</span></div>
          <div><small>Rescue Tracking Legs</small><strong>{{ controlCenter.summary.rescue_tracking_legs }}</strong><span>R5 候选</span></div>
          <div class="review"><small>Permanent Review</small><strong>{{ controlCenter.summary.permanent_review }}</strong><span>保持 fail-closed</span></div>
          <div :class="controlCenter.summary.active_shipment_contamination === 0 ? 'safe' : 'danger'"><small>Active Shipment contamination</small><strong>{{ controlCenter.summary.active_shipment_contamination }}</strong><span>历史数据不得进入运营 Shipment</span></div>
        </div>
        <section class="r5-owner-card">
          <div class="r5-head"><div><small>R5 · Rescue ShipmentAttempt Projection</small><h3>121 Orders → {{ controlCenter.r5.total }} Tracking Legs</h3><p>每个 tracking leg 单独保存为 Historical ShipmentAttempt；不创建 Active Shipment。</p></div><span :class="['r5-status', controlCenter.r5.status]">{{ r5StatusLabel(controlCenter.r5.status) }}</span></div>
          <div class="r5-kpis"><div class="safe"><small>Ready</small><strong>{{ controlCenter.r5.ready }}</strong></div><div class="review"><small>Review</small><strong>{{ controlCenter.r5.review }}</strong></div><div><small>Unique Tracking</small><strong>{{ controlCenter.r5.unique_tracking }}</strong></div><div><small>Warehouse exact</small><strong>{{ controlCenter.r5.warehouse.exact }}</strong></div><div class="review"><small>Warehouse unresolved</small><strong>{{ controlCenter.r5.warehouse.unresolved }}</strong></div><div><small>Applied</small><strong>{{ controlCenter.r5.applied }}</strong></div></div>
          <div class="r5-proof"><div><small>Manifest SHA-256</small><code>{{ controlCenter.r5.manifest_sha256 ?? '尚未生成' }}</code></div><div><small>Fresh reconciliation</small><strong>{{ controlCenter.r5.fresh_sha_matches ? 'MATCH' : 'DRIFT' }}</strong></div><div><small>Global Apply gate</small><strong>{{ controlCenter.r5.global_apply_gate ? 'ON' : 'OFF' }}</strong><span>页面批准仅单请求临时开启</span></div><div><small>最近 DB Backup</small><strong>{{ controlCenter.latest_backup ? `#${controlCenter.latest_backup.id} · ${controlCenter.latest_backup.state}` : '—' }}</strong><span v-if="controlCenter.latest_backup" :title="controlCenter.latest_backup.checksum ?? ''">{{ controlCenter.latest_backup.uuid }}</span></div></div>
          <div v-if="canOperate" class="r5-actions"><button v-if="controlCenter.r5.status === 'not_previewed'" class="mvp-button mvp-button-primary" :disabled="r5Busy || controlCenter.r5.ready <= 0" @click="freezeR5">{{ r5Busy ? '处理中…' : `冻结 ${controlCenter.r5.ready} 条 Preview` }}</button><button v-else-if="controlCenter.r5.status === 'preview'" class="mvp-button mvp-button-primary approve-button" :disabled="r5Busy || !controlCenter.r5.can_apply" @click="applyR5">{{ r5Busy ? '处理中…' : `批准并写入 ${controlCenter.r5.ready} 条` }}</button><span v-else class="r5-done">✓ {{ controlCenter.r5.applied }} 条 Historical ShipmentAttempts 已写入</span><small v-if="controlCenter.r5.status === 'preview'">点击批准后：自动创建紧邻 DB Backup → 重验 SHA → 单事务 Apply → gate 自动恢复 OFF。</small></div>
        </section>
        <section class="attempt-preview">
          <div class="attempt-head"><div><h3>R5 逐条 Preview</h3><p>可按 ERP订单号 / 历史订单号 / Tracking / Warehouse 搜索。</p></div><div class="attempt-filters"><input v-model="attemptQ" type="search" placeholder="搜索订单或 Tracking" @keyup.enter="applyAttemptFilters"><button class="mvp-button" @click="applyAttemptFilters">搜索</button><select :value="controlCenter.attempts.filters.decision" @change="setAttemptFilter('decision', ($event.target as HTMLSelectElement).value)"><option value="all">全部状态</option><option value="ready">Ready</option><option value="review">Review</option><option value="applied">Applied</option></select><select :value="controlCenter.attempts.filters.warehouse" @change="setAttemptFilter('warehouse', ($event.target as HTMLSelectElement).value)"><option value="all">全部仓库</option><option value="exact">Exact</option><option value="unresolved">Unresolved</option></select></div></div>
          <div v-if="controlCenter.r5.status === 'not_previewed'" class="preview-empty">点击“冻结 Preview”后，这里会显示全部 {{ controlCenter.r5.ready }} 条候选明细；在逐条检查前不会允许 Apply。</div>
          <div v-else class="mvp-table-wrap"><table class="mvp-table attempt-table"><thead><tr><th v-for="column in attemptGrid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="attemptGrid.columns" :required-keys="['order_number']" :sync-state="attemptGrid.syncState" @toggle="attemptGrid.toggle" @start-drag="attemptGrid.startDrag" @drop="attemptGrid.drop" @reset="attemptGrid.reset" /></tr></thead><tbody><tr v-for="row in controlCenter.attempts.data" :key="row.id"><td v-for="column in attemptGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="canViewOrders" :href="row.order_url" class="entity-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span></template><template v-else-if="column.key === 'source_order'">{{ row.source_order_no ?? '—' }}</template><template v-else-if="column.key === 'source_row'">#{{ row.source_row_number }}</template><template v-else-if="column.key === 'attempt'">#{{ row.attempt_number }}</template><template v-else-if="column.key === 'tracking'" class="tracking-cell">{{ row.tracking_number ?? '—' }}</template><template v-else-if="column.key === 'warehouse'"><strong v-if="row.warehouse_resolution === 'exact'">{{ row.warehouse_code }}</strong><span v-else class="unresolved">{{ row.warehouse_raw ?? '未匹配' }} / 未匹配</span></template><template v-else-if="column.key === 'agent'">{{ row.raw_agent ?? '—' }}</template><template v-else><span :class="['classification', row.decision === 'applied' ? 'ready' : row.decision]">{{ row.decision }}</span></template></td></tr><tr v-if="!controlCenter.attempts.data.length"><td :colspan="attemptGrid.visibleColumns.length + 1" class="mvp-empty">没有符合筛选条件的 R5 行。</td></tr></tbody></table></div>
          <div v-if="controlCenter.r5.status !== 'not_previewed' && controlCenter.attempts.last_page > 1" class="pagination-bar"><span>每页 {{ controlCenter.attempts.per_page }} 行 · {{ controlCenter.attempts.total }} 条</span><div><button class="mvp-button" :disabled="controlCenter.attempts.current_page <= 1" @click="goAttemptPage(controlCenter.attempts.current_page - 1)">上一页</button><span>第 {{ controlCenter.attempts.current_page }} / {{ controlCenter.attempts.last_page }} 页</span><button class="mvp-button" :disabled="controlCenter.attempts.current_page >= controlCenter.attempts.last_page" @click="goAttemptPage(controlCenter.attempts.current_page + 1)">下一页</button></div></div>
        </section>
        <details v-if="controlCenter.permanent_review_rows.length" class="permanent-review"><summary>Permanent Review · {{ controlCenter.permanent_review_rows.length }} 行（不会自动写入）</summary><div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th>源行</th><th>Order No.</th><th>Tracking Legs</th><th>原因</th></tr></thead><tbody><tr v-for="row in controlCenter.permanent_review_rows" :key="row.id"><td>#{{ row.source_row_number }}</td><td>{{ row.source_order_no ?? '—' }}</td><td>{{ row.tracking_leg_count }}</td><td>{{ row.reason }}</td></tr></tbody></table></div></details>
      </section>

      <template v-if="batch">
        <div class="source-section-title"><small>Source Evidence Import</small><h3>历史发货证据导入</h3><p>以下区域只管理原始证据导入与追溯；上方 Owner UAT 控制台负责后续 Projection / Apply。</p></div>
        <div class="source-card">
          <div><small>Source</small><strong>{{ batch.filename }}</strong><span :title="batch.checksum">SHA-256 {{ batch.checksum.slice(0, 16) }}…</span></div>
          <div><small>精确 KOL 图谱</small><strong>{{ batch.reconciliation_batch_id ? `Batch #${batch.reconciliation_batch_id}` : '未解析' }}</strong><span>未解析时绝不猜测 KOL</span></div>
          <div><small>提交影响</small><strong>仅历史证据</strong><span>Active Shipment 保持不变</span></div>
        </div>
        <div class="evidence-summary">
          <div><small>Rows</small><strong>{{ batch.total_rows }}</strong></div>
          <div class="ready"><small>Ready</small><strong>{{ batch.ready_count }}</strong></div>
          <div class="review"><small>Review</small><strong>{{ batch.review_count }}</strong></div>
          <div class="skip"><small>Skip</small><strong>{{ batch.skip_count }}</strong></div>
          <div><small>Commit gate</small><strong>{{ commitEnabled ? 'ON · 测试环境' : 'OFF · 默认' }}</strong></div>
        </div>

        <section v-if="reviewRescue && batch.review_count" class="rescue-panel">
          <div class="rescue-heading">
            <div><small>Historical Review Rescue · M1.1</small><h3>Review 多段物流证据救回</h3><p>不选择“主 Tracking”；保留同一源行内全部可解释的多段物流编号。不会创建或修改 Shipment。</p></div>
            <button v-if="canOperate" class="mvp-button mvp-button-primary" :disabled="!rescueCommitEnabled || busy || reviewRescue.pending_evidence_count <= 0" @click="commitReviewRescue">
              {{ reviewRescue.pending_evidence_count <= 0 ? 'Rescue 证据已提交' : `提交 ${reviewRescue.rescueable_rows} 行 Rescue 证据` }}
            </button>
          </div>
          <div class="rescue-summary">
            <div class="safe"><small>可安全救回</small><strong>{{ reviewRescue.rescueable_rows }} 行</strong><span>全部已有 exact KOL</span></div>
            <div><small>Tracking Evidence</small><strong>{{ reviewRescue.candidate_evidence_count }}</strong><span>多段证据分别保存</span></div>
            <div class="manual"><small>仍需人工</small><strong>{{ reviewRescue.manual_review_rows }} 行</strong><span>无 exact KOL 时绝不猜测</span></div>
            <div><small>Pending</small><strong>{{ reviewRescue.pending_evidence_count }}</strong><span>已存在 {{ reviewRescue.existing_evidence_count }}</span></div>
          </div>
          <div class="rescue-groups">
            <div v-for="group in reviewRescue.groups" :key="group.reason"><strong>{{ rescueReasonLabel(group.reason) }}</strong><span>{{ group.rows }} 行 → {{ group.evidence }} evidence</span></div>
          </div>
          <p class="rescue-gate">Rescue gate：{{ rescueCommitEnabled ? 'ON · 仅当前测试环境可提交' : 'OFF · 默认只读' }} · 只写 Historical Evidence，不触碰 Orders / Shipments / KOL Collaboration。</p>
        </section>

        <div class="mvp-table-wrap"><table class="mvp-table">
          <thead><tr><th>源行</th><th>Order No.</th><th>KOL</th><th>原始 Carrier</th><th>Tracking</th><th>仓库</th><th>分类</th><th>原因</th></tr></thead>
          <tbody>
            <tr v-for="row in batch.rows" :key="row.id">
              <td>{{ row.source_row_number }}</td><td>{{ row.source_order_no ?? '—' }}</td>
              <td><Link v-if="row.kol" class="entity-link" :href="row.kol.url">#{{ row.kol.id }} {{ row.kol.display_name }}</Link><span v-else>未解析</span></td>
              <td>{{ row.carrier ?? '—' }}</td><td :title="row.tracking_raw ?? ''">{{ row.tracking ?? '—' }}</td><td>{{ row.warehouse ?? '—' }}</td>
              <td><span :class="`classification ${row.classification}`">{{ label(row.classification) }}</span></td><td>{{ row.reason }}</td>
            </tr>
            <tr v-if="!batch.rows.length"><td colspan="8" class="mvp-empty">没有可展示的来源行。</td></tr>
          </tbody>
        </table></div>
        <div v-if="batch.pagination.last_page > 1" class="pagination-bar">
          <span>每页 {{ batch.pagination.per_page }} 行 · {{ batch.pagination.total }} 条</span>
          <div><button class="mvp-button" :disabled="batch.pagination.current_page <= 1" @click="goToPage(batch.pagination.current_page - 1)">上一页</button><span>第 {{ batch.pagination.current_page }} / {{ batch.pagination.last_page }} 页</span><button class="mvp-button" :disabled="batch.pagination.current_page >= batch.pagination.last_page" @click="goToPage(batch.pagination.current_page + 1)">下一页</button></div>
        </div>
      </template>
      <div v-else class="mvp-card empty-state"><h3>等待 XLSX 预览</h3><p>读取范围固定为 Status、Warehouse、Carrier、Tracking、Input Time、Order No.、SKU、Quantity；地址、邮箱、电话和社交帐号不会进入历史物流证据。</p></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.source-section-title{margin:24px 0 8px}.source-section-title small{color:#627780}.source-section-title h3{margin:3px 0;font-size:17px}.source-section-title p{margin:0;color:#71848b;font-size:12px}.file-control{display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid #cfdade;border-radius:7px;background:#fff;font-size:12px;cursor:pointer}.file-control input{max-width:170px;font-size:11px}.source-card{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;margin:18px 0 10px}.source-card>div,.evidence-summary>div{display:grid;gap:4px;padding:12px;border:1px solid #dce5e8;border-radius:8px;background:#fff}.source-card small,.evidence-summary small{color:#627780}.source-card span{font-size:11px;color:#71848b}.evidence-summary{display:grid;grid-template-columns:repeat(5,minmax(100px,1fr));gap:10px;margin-bottom:18px}.evidence-summary strong{font-size:17px}.ready strong{color:#166534}.review strong{color:#9a6700}.skip strong{color:#64748b}.rescue-panel{display:grid;gap:12px;margin:0 0 18px;padding:16px;border:1px solid #d8e4e7;border-radius:10px;background:#f8fbfb}.rescue-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.rescue-heading small{color:#627780}.rescue-heading h3{margin:4px 0;font-size:17px}.rescue-heading p,.rescue-gate{margin:0;color:#627780;font-size:12px}.rescue-summary{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px}.rescue-summary>div{display:grid;gap:3px;padding:11px;border:1px solid #dce5e8;border-radius:8px;background:#fff}.rescue-summary small,.rescue-summary span{color:#71848b;font-size:11px}.rescue-summary strong{font-size:17px}.rescue-summary .safe strong{color:#166534}.rescue-summary .manual strong{color:#9a6700}.rescue-groups{display:flex;flex-wrap:wrap;gap:8px}.rescue-groups>div{display:flex;gap:8px;padding:7px 9px;border:1px solid #dce5e8;border-radius:999px;background:#fff;font-size:11px}.rescue-groups span{color:#627780}.classification{font-size:11px;font-weight:700}.classification.ready{color:#166534}.classification.review{color:#9a6700}.classification.skip{color:#64748b}.entity-link{font-weight:600}.pagination-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:12px;font-size:12px;color:#627780}.pagination-bar>div{display:flex;align-items:center;gap:10px}.empty-state{margin-top:18px}@media(max-width:900px){.mvp-page-heading{display:grid;gap:14px}.mvp-actions{flex-wrap:wrap}.source-card,.evidence-summary,.rescue-summary{grid-template-columns:repeat(2,1fr)}.rescue-heading{display:grid}.pagination-bar{align-items:flex-start;flex-direction:column}}

.owner-control-center{display:grid;gap:14px;margin:18px 0 24px;padding:18px;border:1px solid #cddde1;border-radius:12px;background:#f7fafb}.owner-title,.r5-head,.attempt-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.owner-title small,.r5-head small{color:#627780}.owner-title h3,.r5-head h3,.attempt-head h3{margin:3px 0;font-size:18px}.owner-title p,.r5-head p,.attempt-head p{margin:0;color:#627780;font-size:12px}.owner-health,.r5-status{padding:5px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}.owner-health.ok,.r5-status.applied{background:#dcfce7;color:#166534}.owner-health.bad{background:#fee2e2;color:#991b1b}.r5-status.preview{background:#dbeafe;color:#1d4ed8}.r5-status.not_previewed{background:#fef3c7;color:#92400e}.owner-summary{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:9px}.owner-summary>div,.r5-kpis>div,.r5-proof>div{display:grid;gap:3px;padding:11px;border:1px solid #dce5e8;border-radius:8px;background:#fff}.owner-summary small,.owner-summary span,.r5-kpis small,.r5-proof small,.r5-proof span{font-size:11px;color:#71848b}.owner-summary strong,.r5-kpis strong{font-size:18px}.owner-summary .safe strong,.r5-kpis .safe strong{color:#166534}.owner-summary .review strong,.r5-kpis .review strong{color:#9a6700}.owner-summary .danger strong{color:#b91c1c}.r5-owner-card,.attempt-preview{display:grid;gap:12px;padding:15px;border:1px solid #d4e1e4;border-radius:10px;background:#fff}.r5-kpis{display:grid;grid-template-columns:repeat(6,minmax(100px,1fr));gap:8px}.r5-proof{display:grid;grid-template-columns:2fr 1fr 1fr 2fr;gap:8px}.r5-proof code{font-size:10px;word-break:break-all}.r5-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-top:2px}.r5-actions small{color:#627780}.approve-button{font-weight:800}.r5-done{font-weight:700;color:#166534}.attempt-filters{display:flex;gap:7px;flex-wrap:wrap}.attempt-filters input,.attempt-filters select{border:1px solid #cfdade;border-radius:7px;background:#fff;padding:7px 9px;font-size:12px}.attempt-filters input{min-width:220px}.preview-empty{padding:24px;border:1px dashed #cbd8dc;border-radius:8px;text-align:center;color:#627780;font-size:12px;background:#fafcfc}.tracking-cell{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px}.unresolved{color:#9a6700;font-weight:700}.permanent-review{border:1px solid #e6d8ae;border-radius:9px;background:#fffcf3}.permanent-review summary{cursor:pointer;padding:12px 14px;font-weight:700;color:#7c5c00}.permanent-review .mvp-table-wrap{margin:0 12px 12px}

@media(max-width:1100px){.owner-summary{grid-template-columns:repeat(2,1fr)}.r5-kpis{grid-template-columns:repeat(3,1fr)}.r5-proof{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.owner-summary,.r5-kpis,.r5-proof{grid-template-columns:1fr}.owner-title,.r5-head,.attempt-head{display:grid}.attempt-filters{display:grid}.attempt-filters input{min-width:0;width:100%}}
</style>
