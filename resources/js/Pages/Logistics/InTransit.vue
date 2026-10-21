<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type ShipmentRow = {
  shipment_id: number
  order_id: number
  external_order_number: string | null
  erp_order_id: string | null
  recipient_name: string | null
  country: string | null
  warehouse_name: string | null
  carrier: string | null
  service: string | null
  tracking_number: string | null
  shipped_at: string | null
  logistics_note: string | null
  shipment_status: string
  order_url: string | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{ shipments: Paginated<ShipmentRow>; statusBar?: { key: string; label: string; url: string; count: number }[]; canOperate: boolean }>()
type ColumnKey = 'order_number' | 'recipient' | 'country' | 'warehouse' | 'carrier' | 'tracking' | 'shipped_at'
const definitions: GridColumnDefinition<ColumnKey>[] = [{ key: 'order_number', label: '订单号' }, { key: 'recipient', label: '收件人' }, { key: 'country', label: '国家' }, { key: 'warehouse', label: '仓库' }, { key: 'carrier', label: '物流商 / 渠道' }, { key: 'tracking', label: '追踪号' }, { key: 'shipped_at', label: '发货时间' }]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.in-transit.v1', definitions, requiredKeys: ['order_number'] })
const exceptionNotes = ref<Record<number, string>>({})
const exceptionOpen = ref<number | null>(null)
const copyState = ref<Record<number, 'success' | 'error'>>({})

const copyTracking = async (row: ShipmentRow) => {
  delete copyState.value[row.order_id]
  const text = `${[row.carrier, row.service].filter(Boolean).join(' ')} ${row.tracking_number ?? ''}`.trim()
  let ok = false
  if (window.isSecureContext && navigator.clipboard?.writeText) {
    try { await navigator.clipboard.writeText(text); ok = true } catch { /* fallback */ }
  }
  if (!ok) {
    const ta = document.createElement('textarea')
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.left = '-9999px'
    document.body.appendChild(ta); ta.select()
    try { ok = document.execCommand('copy') } catch { ok = false }
    document.body.removeChild(ta)
  }
  copyState.value[row.order_id] = ok ? 'success' : 'error'
  window.setTimeout(() => { delete copyState.value[row.order_id] }, 2500)
}

const submitException = (row: ShipmentRow) => {
  if (!props.canOperate) return
  const note = (exceptionNotes.value[row.order_id] ?? '').trim()
  if (!note) return
  router.post(`/logistics/orders/${row.order_id}/mark-exception`, { logistics_note: note }, { preserveScroll: true })
  exceptionOpen.value = null
}

const goToPage = (page: number) => router.get('/logistics/in-transit', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="运输中 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '运输中']">
    <section class="mvp-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics OS V1</p><h2>运输中</h2><p>已有追踪号、正在运输的物流单。<strong v-if="!props.canOperate"> 当前角色为只读模式。</strong></p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr>
        <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" />
      </tr></thead><tbody>
        <template v-for="row in shipments.data" :key="row.shipment_id">
          <tr>
            <td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span></template><template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template><template v-else-if="column.key === 'country'">{{ row.country ?? '—' }}</template><template v-else-if="column.key === 'warehouse'">{{ row.warehouse_name ?? '—' }}</template><template v-else-if="column.key === 'carrier'">{{ [row.carrier, row.service].filter(Boolean).join(' / ') || '—' }}</template><template v-else-if="column.key === 'tracking'">{{ row.tracking_number ?? '—' }}</template><template v-else>{{ row.shipped_at ? row.shipped_at.slice(0, 10) : '—' }}</template></td>
            <td class="mvp-actions">
              <button class="mvp-button" type="button" @click="copyTracking(row)">复制追踪</button>
              <button v-if="props.canOperate" class="mvp-button" type="button" @click="exceptionOpen = exceptionOpen === row.order_id ? null : row.order_id">标记异常</button>
              <span v-if="copyState[row.order_id] === 'success'" class="mvp-success">已复制</span>
              <span v-else-if="copyState[row.order_id] === 'error'" class="mvp-error">复制失败</span>
            </td>
          </tr>
          <tr v-if="props.canOperate && exceptionOpen === row.order_id">
            <td :colspan="visibleColumns.length + 2" class="exception-cell">
              <div class="exception-form">
                <label>异常原因（必填）<textarea v-model="exceptionNotes[row.order_id]" rows="2" placeholder="请简述异常情况" /></label>
                <div class="mvp-actions"><button class="mvp-button mvp-button-primary" @click="submitException(row)">确认标记异常</button><button class="mvp-button" @click="exceptionOpen = null">取消</button></div>
              </div>
            </td>
          </tr>
        </template>
        <tr v-if="shipments.data.length === 0"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无运输中物流单</td></tr>
      </tbody></table></div>
      <div v-if="shipments.last_page > 1" class="mvp-pagination">
        <button class="mvp-button" :disabled="shipments.current_page <= 1" @click="goToPage(shipments.current_page - 1)">上一页</button>
        <span>第 {{ shipments.current_page }} / {{ shipments.last_page }} 页 · 共 {{ shipments.total }} 条</span>
        <button class="mvp-button" :disabled="shipments.current_page >= shipments.last_page" @click="goToPage(shipments.current_page + 1)">下一页</button>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.exception-cell { background: #fff9eb; padding: 14px !important; }
.exception-form { display: grid; gap: 10px; }
.exception-form label { display: grid; gap: 5px; font-size: 12px; color: #5f737c; }
.exception-form textarea { width: 100%; border: 1px solid #cfdade; border-radius: 7px; padding: 8px 10px; background: #fff; color: #142126; }
</style>
