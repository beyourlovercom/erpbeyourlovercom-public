<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type Order = { id: number; erp_order_id: string | null; external_order_number: string; internal_order_number: string; source_label: string; order_kind?: string; order_date: string; order_status_label: string; address?: { recipient_name: string; country: string }; owner?: { name: string }; owner_label?: string | null }
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }
type SavedView = { id: number; name: string; filters_json: Record<string, string>; is_pinned: boolean; created_by: number }
type ColumnKey = 'order_number' | 'source' | 'date' | 'recipient' | 'country' | 'status' | 'owner'

const props = defineProps<{
  orderKind: string
  orderScope: string
  orders: Paginated<Order>
  filters: Record<string, string>
  sourceTypes: { value: string; label: string }[]
  savedViews: SavedView[]
  canExport: boolean
}>()

const filters = reactive({
  search: props.filters.search ?? '',
  source_type: props.filters.source_type ?? '',
  order_status: props.filters.order_status ?? '',
  view: props.filters.view ?? 'all',
})
const showNewView = ref(false)
const newViewName = ref('')

const basePath = computed(() => props.orderScope === 'external_warehouse' ? '/orders/procurement' : (props.orderKind === 'all' ? '/orders' : (props.orderKind === 'kol_fulfillment' ? '/kol/orders' : '/customers/orders')))
const heading = computed(() => props.orderScope === 'external_warehouse' ? '外采订单' : (props.orderKind === 'all' ? '全部订单' : (props.orderKind === 'kol_fulfillment' ? '网红履约订单' : '客户订单')))
const systemViews = [
  { key: 'all', label: '全部订单' },
  { key: 'pending', label: '待发订单' },
  { key: 'exception', label: '异常订单' },
  { key: 'in_transit', label: '运输中' },
  { key: 'shipped', label: '已发货' },
  { key: 'return', label: '退货单' },
  { key: 'loss', label: '亏本单' },
  { key: 'overweight', label: '超重单' },
  { key: 'stockout', label: '缺货单' },
]

const submit = () => router.get(basePath.value, filters, { preserveState: true, replace: true })
const applySystemView = (view: string) => {
  filters.search = ''
  filters.source_type = ''
  filters.order_status = ''
  filters.view = view
  submit()
}
const applySavedView = (saved: SavedView) => {
  filters.search = saved.filters_json.search ?? ''
  filters.source_type = saved.filters_json.source_type ?? ''
  filters.order_status = saved.filters_json.order_status ?? ''
  filters.view = saved.filters_json.view ?? 'all'
  submit()
}
const saveCurrentView = () => {
  const name = newViewName.value.trim()
  if (!name) return
  router.post('/orders/views', {
    name,
    filters_json: { ...filters },
    is_pinned: true,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      newViewName.value = ''
      showNewView.value = false
    },
  })
}
const deleteSavedView = (saved: SavedView) => router.delete(`/orders/views/${saved.id}`, { preserveScroll: true })
const exportUrl = () => `${basePath.value}/export?${new globalThis.URLSearchParams(filters).toString()}`
const goToPage = (page: number) => router.get(basePath.value, { ...filters, page }, { preserveState: true, replace: true })
const orderPath = (order: Order) => order.order_kind === 'kol_fulfillment' ? `/kol/orders/${order.id}` : `/customers/orders/${order.id}`
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: 'ERP订单号' },
  { key: 'source', label: '来源' }, { key: 'date', label: '订单日期' }, { key: 'recipient', label: '收件人' },
  { key: 'country', label: '国家' }, { key: 'status', label: '状态' }, { key: 'owner', label: '负责人' },
]
const { columns, visibleColumns, toggle, startDrag, drop, reset, syncState } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.orders.index.v1', definitions, requiredKeys: ['order_number'] })
</script>

<template>
  <Head :title="`${heading} · BYL ERP`" />
  <AuthenticatedLayout :title="heading" :breadcrumbs="['BYL ERP', heading]">
    <section class="mvp-page orders-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">订单核心</p><h2>{{ heading }}</h2><p>统一维护 Website、KOL 和 Manual Other 订单。</p></div>
        <div class="mvp-actions page-header-actions">
          <a v-if="canExport" class="mvp-button" :href="exportUrl()">导出 CSV</a>
          <template v-if="orderKind === 'all' && orderScope !== 'external_warehouse'">
            <Link class="mvp-button" href="/customers/orders/create">新建客户订单</Link>
            <Link class="mvp-button mvp-button-primary" href="/kol/orders/create">新建网红订单</Link>
          </template>
          <Link v-else-if="orderKind !== 'all'" class="mvp-button mvp-button-primary" :href="`${basePath}/create`">新建订单</Link>
        </div>
      </div>

      <div class="order-views-bar" aria-label="订单视图">
        <button v-for="view in systemViews" :key="view.key" type="button" class="view-tab" :class="{ active: filters.view === view.key }" @click="applySystemView(view.key)">{{ view.label }}</button>
        <span v-if="savedViews.length" class="views-divider" aria-hidden="true"></span>
        <div v-for="saved in savedViews" :key="saved.id" class="saved-view-chip">
          <button type="button" class="view-tab saved" @click="applySavedView(saved)">{{ saved.name }}</button>
          <button type="button" class="saved-view-delete" :aria-label="`删除视图 ${saved.name}`" @click="deleteSavedView(saved)">×</button>
        </div>
        <button type="button" class="view-tab add-view" @click="showNewView = !showNewView">＋ 新建视图</button>
      </div>

      <form v-if="showNewView" class="new-view-panel" @submit.prevent="saveCurrentView">
        <label>视图名称<input v-model="newViewName" maxlength="100" placeholder="例如：美国待发"></label>
        <span>保存当前筛选条件，不复制订单。</span>
        <div class="mvp-actions"><button class="mvp-button mvp-button-primary" type="submit" :disabled="!newViewName.trim()">保存视图</button><button class="mvp-button" type="button" @click="showNewView = false">取消</button></div>
      </form>

      <form class="mvp-toolbar" @submit.prevent="submit">
        <input v-model="filters.search" aria-label="搜索订单" placeholder="ERP订单号 / 来源单号 / 收件人；数据库ID请用 dbid:7008">
        <select v-model="filters.source_type" aria-label="来源"><option value="">全部来源</option><option v-for="source in sourceTypes" :key="source.value" :value="source.value">{{ source.label }}</option></select>
        <select v-model="filters.order_status" aria-label="状态"><option value="">全部状态</option><option value="pending_shipment">待发货</option><option value="shipped">已发货</option><option value="completed">已完结</option><option value="completed_delivered">完结&送达</option><option value="cancelled">已取消</option></select>
        <button class="mvp-button" type="submit">筛选</button>
      </form>

      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" /></tr></thead><tbody><tr v-for="order in orders.data" :key="order.id"><td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link :href="orderPath(order)">{{ order.erp_order_id ?? '—' }}</Link></template><template v-else-if="column.key === 'source'">{{ order.source_label }}</template><template v-else-if="column.key === 'date'">{{ order.order_date }}</template><template v-else-if="column.key === 'recipient'">{{ order.address?.recipient_name ?? '—' }}</template><template v-else-if="column.key === 'country'">{{ order.address?.country ?? '—' }}</template><template v-else-if="column.key === 'status'"><span class="mvp-status">{{ order.order_status_label }}</span></template><template v-else>{{ order.owner_label ?? '—' }}</template></td><td><Link :href="orderPath(order)">查看</Link></td></tr><tr v-if="orders.data.length === 0"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无符合条件的订单</td></tr></tbody></table></div>
      <div v-if="orders.last_page > 1" class="mvp-pagination"><button class="mvp-button" type="button" :disabled="orders.current_page <= 1" @click="goToPage(orders.current_page - 1)">上一页</button><span>第 {{ orders.current_page }} / {{ orders.last_page }} 页 · 共 {{ orders.total }} 条</span><button class="mvp-button" type="button" :disabled="orders.current_page >= orders.last_page" @click="goToPage(orders.current_page + 1)">下一页</button></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.order-views-bar{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:0 0 14px}.view-tab{border:1px solid var(--byl-color-border,#d9e1e5);background:var(--byl-color-surface,#fff);border-radius:999px;padding:7px 12px;cursor:pointer;font-size:13px}.view-tab.active{background:var(--byl-status-bg);color:var(--byl-status-ink);font-weight:700}.view-tab.saved{border-radius:999px 0 0 999px}.saved-view-chip{display:inline-flex}.saved-view-delete{border:1px solid var(--byl-color-border,#d9e1e5);border-left:0;background:var(--byl-color-surface,#fff);border-radius:0 999px 999px 0;padding:0 9px;cursor:pointer}.views-divider{width:1px;height:24px;background:var(--byl-color-border,#d9e1e5);margin:0 2px}.add-view{border-style:dashed}.new-view-panel{display:flex;align-items:end;gap:14px;flex-wrap:wrap;padding:12px 14px;margin-bottom:14px;border:1px solid var(--byl-color-border,#d9e1e5);border-radius:10px;background:var(--byl-color-surface,#fff)}.new-view-panel label{display:flex;flex-direction:column;gap:5px;font-size:12px}.new-view-panel input{min-width:240px;padding:8px 10px;border:1px solid var(--byl-color-border,#d9e1e5);border-radius:8px}.new-view-panel>span{font-size:12px;opacity:.7;padding-bottom:9px}
</style>
