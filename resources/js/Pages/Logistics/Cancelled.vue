<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type Item = { sku: string | null; product_summary: string | null; product_url: string | null; quantity: number }
type Row = { order_url: string | null; erp_order_id: string | null; order_date: string | null; recipient_name: string | null; country: string | null; items: Item[] }
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{ orders: Paginated<Row>; statusBar: { key: string; label: string; url: string; count: number }[] }>()
type ColumnKey = 'order_number' | 'order_date' | 'recipient' | 'country' | 'items'
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: '订单号' },
  { key: 'order_date', label: '订单日期' },
  { key: 'recipient', label: '收件人' },
  { key: 'country', label: '国家' },
  { key: 'items', label: '商品 / SKU' },
]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.cancelled.v2', definitions, requiredKeys: ['order_number'] })
const goToPage = (page: number) => router.get('/logistics/cancelled', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="已取消 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '已取消']">
    <section class="mvp-page">
      <StatusBar :statuses="statusBar" />
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">Logistics OS V1</p>
          <h2>已取消</h2>
          <p>已取消订单归档。订单取消状态不等同于退款结果；退款请以财务 / 支付记录为准。</p>
        </div>
      </div>
      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr>
        <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th>
        <th>操作</th>
        <ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" />
      </tr></thead><tbody>
        <tr v-for="row in orders.data" :key="row.erp_order_id ?? row.order_url ?? 'cancelled-row'">
          <td v-for="column in visibleColumns" :key="column.key">
            <template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span></template>
            <template v-else-if="column.key === 'order_date'">{{ row.order_date ?? '—' }}</template>
            <template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template>
            <template v-else-if="column.key === 'country'">{{ row.country ?? '—' }}</template>
            <template v-else><span v-for="(item, index) in row.items" :key="`${item.product_url ?? item.sku ?? 'item'}-${index}`"><Link v-if="item.product_url" :href="item.product_url" class="mvp-link">{{ item.sku ?? item.product_summary ?? '—' }}</Link><span v-else>{{ item.sku ?? item.product_summary ?? '—' }}</span><small> × {{ item.quantity }}</small><template v-if="index < row.items.length - 1"> · </template></span></template>
          </td>
          <td><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">查看订单</Link><span v-else>—</span></td>
          <td aria-hidden="true"></td>
        </tr>
        <tr v-if="orders.data.length === 0"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无已取消订单</td></tr>
      </tbody></table></div>
      <div v-if="orders.last_page > 1" class="mvp-pagination">
        <button class="mvp-button" type="button" :disabled="orders.current_page <= 1" @click="goToPage(orders.current_page - 1)">上一页</button>
        <span>第 {{ orders.current_page }} / {{ orders.last_page }} 页 · 共 {{ orders.total }} 条</span>
        <button class="mvp-button" type="button" :disabled="orders.current_page >= orders.last_page" @click="goToPage(orders.current_page + 1)">下一页</button>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
