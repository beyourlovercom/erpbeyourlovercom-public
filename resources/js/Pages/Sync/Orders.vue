<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type Order = {
  id: number; erp_order_id?: string | null; external_order_number?: string | null; recipient_name: string; status: string
  synced_at: string | null; synced_by: number | null; tracking_number: string | null
  shipment_status: string | null; carrier: string | null; source_type: string
  tracking_outbound_status: string | null; tracking_outbound_attempts: number | null
}
type SyncRun = {
  id: number; run_type: string; status: string; requested_items: number
  succeeded_items: number; retrying_items: number; failed_items: number
  conflict_items: number; skipped_items: number; started_at: string | null
}
type QueueSummary = { retry_due: number; retry_waiting: number; processing: number; failed: number; conflicts: number }

const props = withDefaults(defineProps<{
  orders: Order[]; filters: { status?: string; search?: string }
  syncRuns?: SyncRun[]; queueSummary?: QueueSummary
}>(), {
  syncRuns: () => [],
  queueSummary: () => ({ retry_due: 0, retry_waiting: 0, processing: 0, failed: 0, conflicts: 0 }),
})
const filters = reactive({ status: props.filters.status ?? '', search: props.filters.search ?? '' })
const selected = ref<number[]>([])
const submit = () => router.get('/sync/orders', filters, { preserveState: true, replace: true })
const update = (id: number, action: string) => router.post(`/sync/orders/${id}/${action}`, { sync_note: window.prompt('备注（可选）') ?? '' })
const pushTracking = (id: number) => router.post(`/sync/orders/${id}/tracking-outbound`)
const copy = async (id: number) => {
  const response = await fetch(`/sync/orders/${id}/payload`, { headers: { Accept: 'application/json' } })
  await navigator.clipboard.writeText(JSON.stringify(await response.json(), null, 2))
}
const selectable = (order: Order) => order.source_type === 'woocommerce' && order.shipment_status === 'shipped' && !!order.tracking_number
const toggleSelected = (id: number, checked: boolean) => {
  selected.value = checked ? [...new Set([...selected.value, id])] : selected.value.filter((value) => value !== id)
}
const batchSelected = () => router.post('/sync/orchestration/tracking/selected', { order_ids: selected.value })
const batchAllPending = () => router.post('/sync/orchestration/tracking/all-pending', { limit: 100 })
const retryPending = () => router.post('/sync/orchestration/retry', { limit: 100 })
const recoverStale = () => router.post('/sync/orchestration/recover-stale', { age_seconds: 300, limit: 100 })
const reconcileSelected = () => router.post('/sync/orchestration/reconcile', { order_ids: selected.value })
const outboundLabel = (order: Order) => {
  if (!order.tracking_outbound_status) return '未执行'
  if (order.tracking_outbound_status === 'pending') return (order.tracking_outbound_attempts ?? 0) > 0 ? '待重试' : '待执行'
  if (order.tracking_outbound_status === 'processing') return '处理中'
  if (order.tracking_outbound_status === 'completed') return '已完成'
  if (order.tracking_outbound_status === 'failed') return '失败'
  return order.tracking_outbound_status
}
type ColumnKey = 'order_number' | 'source' | 'recipient' | 'tracking' | 'outbound' | 'status' | 'synced_at'
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: 'ERP订单号' },
  { key: 'source', label: '来源' }, { key: 'recipient', label: '收件人' }, { key: 'tracking', label: '追踪号' },
  { key: 'outbound', label: 'Tracking 写回' }, { key: 'status', label: '状态' }, { key: 'synced_at', label: '完成时间' },
]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.sync.orders.v1', definitions, requiredKeys: ['order_number'] })
</script>
<template>
  <Head title="同步订单 · BYL ERP" />
  <AuthenticatedLayout title="同步订单" :breadcrumbs="['BYL ERP', '同步', '同步订单']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">Sync Orchestration</p>
          <h2>同步订单</h2>
          <p>统一处理 Tracking 写回、重试、冲突和远端核对；订单与 Shipment 仍是业务真相。</p>
        </div>
        <a class="mvp-button mvp-button-primary" href="/sync/woocommerce">WooCommerce 入站</a>
      </div>

      <div class="mvp-toolbar">
        <button class="mvp-button mvp-button-primary" type="button" :disabled="selected.length === 0" @click="batchSelected">批量写回所选</button>
        <button class="mvp-button" type="button" @click="batchAllPending">写回全部待同步</button>
        <button class="mvp-button" type="button" @click="retryPending">重试到期任务</button>
        <button class="mvp-button" type="button" @click="recoverStale">恢复超时任务</button>
        <button class="mvp-button" type="button" :disabled="selected.length === 0" @click="reconcileSelected">核对所选 Tracking</button>
        <span class="mvp-status">已选择 {{ selected.length }}</span>
      </div>
      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>到期重试</th><th>等待重试</th><th>处理中</th><th>失败</th><th>冲突</th></tr></thead>
          <tbody><tr>
            <td>{{ props.queueSummary.retry_due }}</td>
            <td>{{ props.queueSummary.retry_waiting }}</td>
            <td>{{ props.queueSummary.processing }}</td>
            <td>{{ props.queueSummary.failed }}</td>
            <td>{{ props.queueSummary.conflicts }}</td>
          </tr></tbody>
        </table>
      </div>

      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>Run</th><th>类型</th><th>状态</th><th>请求</th><th>成功</th><th>重试</th><th>冲突</th><th>失败</th><th>跳过</th></tr></thead>
          <tbody>
            <tr v-for="run in props.syncRuns" :key="run.id">
              <td><a :href="`/sync/runs/${run.id}`">#{{ run.id }}</a></td>
              <td>{{ run.run_type }}</td><td><span class="mvp-status">{{ run.status }}</span></td>
              <td>{{ run.requested_items }}</td><td>{{ run.succeeded_items }}</td><td>{{ run.retrying_items }}</td>
              <td>{{ run.conflict_items }}</td><td>{{ run.failed_items }}</td><td>{{ run.skipped_items }}</td>
            </tr>
            <tr v-if="props.syncRuns.length === 0"><td colspan="9" class="mvp-empty">暂无 Sync Run</td></tr>
          </tbody>
        </table>
      </div>
      <form class="mvp-toolbar" @submit.prevent="submit">
        <input v-model="filters.search" placeholder="搜索订单号或收件人" aria-label="搜索同步">
        <select v-model="filters.status" aria-label="同步状态">
          <option value="">全部状态</option><option value="pending">待同步</option><option value="completed">已完成</option>
          <option value="needs_confirmation">待确认</option><option value="returned">已退回</option><option value="failed">失败</option>
        </select>
        <button class="mvp-button mvp-button-primary">筛选</button>
      </form>

      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>选择</th><th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" /></tr></thead>
          <tbody>
            <tr v-for="order in props.orders" :key="order.id">
              <td><input v-if="selectable(order)" type="checkbox" :aria-label="`选择 ${order.erp_order_id ?? '—'}`" :checked="selected.includes(order.id)" @change="toggleSelected(order.id, ($event.target as HTMLInputElement).checked)"></td>
              <td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><strong>{{ order.erp_order_id ?? '—' }}</strong></template><template v-else-if="column.key === 'source'">{{ order.source_type }}</template><template v-else-if="column.key === 'recipient'">{{ order.recipient_name }}</template><template v-else-if="column.key === 'tracking'">{{ order.tracking_number ?? '—' }}</template><template v-else-if="column.key === 'outbound'"><span class="mvp-status">{{ order.source_type === 'woocommerce' ? outboundLabel(order) : '—' }}</span></template><template v-else-if="column.key === 'status'"><span class="mvp-status">{{ order.status }}</span></template><template v-else>{{ order.synced_at ?? '—' }}</template></td>
              <td class="mvp-actions">
                <button class="mvp-button" @click="copy(order.id)">复制网站 payload</button>
                <button v-if="order.source_type === 'woocommerce' && order.shipment_status === 'shipped' && order.tracking_number && order.status !== 'completed'" class="mvp-button mvp-button-primary" @click="pushTracking(order.id)">写回 Tracking</button>
                <button v-if="order.status !== 'completed'" class="mvp-button" @click="update(order.id, 'complete')">标记完成</button>
                <button v-if="order.status === 'completed'" class="mvp-button" @click="update(order.id, 'reopen')">重开</button>
                <button v-if="order.status !== 'returned'" class="mvp-button" @click="update(order.id, 'return')">退回更正</button>
              </td>
            </tr>
            <tr v-if="props.orders.length === 0"><td :colspan="visibleColumns.length + 3" class="mvp-empty">暂无同步记录</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
