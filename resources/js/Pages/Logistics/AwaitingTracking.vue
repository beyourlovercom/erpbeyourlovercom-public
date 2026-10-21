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
type ColumnKey = 'order_number' | 'recipient' | 'country' | 'warehouse' | 'carrier' | 'shipped_at' | 'tracking'
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: '订单号' },
  { key: 'recipient', label: '收件人' }, { key: 'country', label: '国家' }, { key: 'warehouse', label: '仓库' },
  { key: 'carrier', label: '物流商 / 渠道' }, { key: 'shipped_at', label: '发货时间' }, { key: 'tracking', label: '追踪号' },
]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.awaiting-tracking.v1', definitions, requiredKeys: ['order_number'] })
const trackingInputs = ref<Record<number, string>>({})
const getTracking = (row: ShipmentRow) => trackingInputs.value[row.order_id] ?? ''
const setTracking = (row: ShipmentRow, v: string) => { trackingInputs.value[row.order_id] = v }

const saveTracking = (row: ShipmentRow) => {
  if (!props.canOperate) return
  const t = (trackingInputs.value[row.order_id] ?? '').trim()
  if (!t) return
  router.post(`/logistics/orders/${row.order_id}/update-tracking`, { tracking_number: t }, { preserveScroll: true })
}

const goToPage = (page: number) => router.get('/logistics/awaiting-tracking', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="已发待单号 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '已发待单号']">
    <section class="mvp-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics OS V1</p><h2>已发待单号</h2><p>已确认发货但追踪号尚未填入的物流单。<strong v-if="!props.canOperate"> 当前角色为只读模式。</strong></p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr>
        <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" />
      </tr></thead><tbody>
        <tr v-for="row in shipments.data" :key="row.shipment_id">
          <td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span></template><template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template><template v-else-if="column.key === 'country'">{{ row.country ?? '—' }}</template><template v-else-if="column.key === 'warehouse'">{{ row.warehouse_name ?? '—' }}</template><template v-else-if="column.key === 'carrier'">{{ [row.carrier, row.service].filter(Boolean).join(' / ') || '—' }}</template><template v-else-if="column.key === 'shipped_at'">{{ row.shipped_at ? row.shipped_at.slice(0, 10) : '—' }}</template><template v-else-if="column.key === 'tracking'"><input v-if="props.canOperate" class="tracking-input" :value="getTracking(row)" @input="setTracking(row, ($event.target as HTMLInputElement).value)" placeholder="填入追踪号" /><span v-else>待回填</span></template></td>
          <td class="mvp-actions"><button v-if="props.canOperate" class="mvp-button mvp-button-primary" type="button" @click="saveTracking(row)">保存</button><span v-else>只读</span></td>
        </tr>
        <tr v-if="shipments.data.length === 0"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无已发待单号订单</td></tr>
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
.tracking-input { border: 1px solid #cfdade; border-radius: 7px; padding: 6px 10px; background: #fff; color: #142126; width: 180px; }
</style>
