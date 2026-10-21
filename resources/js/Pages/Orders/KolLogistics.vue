<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Row = {
  id: number; erp_order_id: string | null; external_order_number: string
  order_date: string | null; owner: string | null
  shipment_status: string; warehouse: string | null
  carrier: string | null; tracking_number: string | null; shipped_at: string | null
}
type Paginated = { data: Row[]; current_page: number; last_page: number; total: number }
const props = defineProps<{
  orders: Paginated
  filters: { search: string; status: string }
  isManager: boolean
}>()
const filters = reactive({ search: props.filters.search, status: props.filters.status })
const statuses: Record<string, string> = {
  pending: '待发货', shipped: '已发货', in_transit: '运输中',
  exception: '异常', completed: '已完结', cancelled: '已取消',
}
const search = () => router.get('/kol/logistics', filters, { preserveState: true, replace: true })
const page = (number: number) => router.get('/kol/logistics', { ...filters, page: number }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="网红物流进度 · BYL ERP" />
  <AuthenticatedLayout title="网红物流进度" :breadcrumbs="['BYL ERP', '网红', '物流进度']">
    <section class="mvp-page kol-logistics">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">KOL · 只读</p>
          <h2>物流进度</h2>
          <p>{{ isManager ? '查看团队 KOL 订单的履约状态' : '仅查看自己负责的 KOL 订单物流状态' }}</p>
        </div>
        <Link class="mvp-button" href="/kol/orders">返回网红订单</Link>
      </div>
      <form class="mvp-toolbar" @submit.prevent="search">
        <input v-model="filters.search" aria-label="搜索网红订单" placeholder="ERP 订单号 / KOL 业务单号 / dbid:7008" maxlength="120" />
        <select v-model="filters.status" aria-label="物流状态">
          <option value="all">全部状态</option>
          <option value="pending">待发货</option>
          <option value="shipped">已发货</option>
          <option value="exception">异常</option>
          <option value="completed">已完结</option>
        </select>
        <button type="submit" class="mvp-button mvp-button-primary">查询</button>
      </form>
      <p class="scope-notice">仅显示有权限的 KOL 订单。不提供跨部门导出、发货或修改物流操作。</p>

      <div class="rows">
        <article v-for="order in orders.data" :key="order.id" class="shipment-card">
          <div class="card-title">
            <Link :href="`/kol/orders/${order.id}`">{{ order.erp_order_id || 'ERP 编号待生成' }}</Link>
            <span class="mvp-status">{{ statuses[order.shipment_status] ?? order.shipment_status }}</span>
          </div>
          <div class="meta"><span>业务单号：{{ order.external_order_number }}</span><span>订单日期：{{ order.order_date || '—' }}</span></div>
          <div class="details">
            <span>仓库：{{ order.warehouse || (order.shipment_status === 'pending' ? '待分配' : '未记录') }}</span>
            <span>承运商：{{ order.carrier || '待确认' }}</span>
            <span>单号：{{ order.tracking_number || '未填写' }}</span>
            <span v-if="isManager">负责人：{{ order.owner || '—' }}</span>
          </div>
          <div class="card-foot"><span>发货时间：{{ order.shipped_at || (order.shipment_status === 'pending' ? '尚未发货' : '未记录') }}</span><Link :href="`/kol/orders/${order.id}`">查看订单</Link></div>
        </article>
        <div v-if="orders.data.length === 0" class="mvp-empty">当前权限与筛选范围内没有物流记录</div>
      </div>
      <nav v-if="orders.last_page > 1" class="mvp-pagination" aria-label="物流订单分页">
        <button class="mvp-button" type="button" :disabled="orders.current_page <= 1" @click="page(orders.current_page - 1)">上一页</button>
        <span>第 {{ orders.current_page }} / {{ orders.last_page }} 页 · 共 {{ orders.total }} 条</span>
        <button class="mvp-button" type="button" :disabled="orders.current_page >= orders.last_page" @click="page(orders.current_page + 1)">下一页</button>
      </nav>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.kol-logistics{max-width:1160px;margin:auto}.scope-notice{font-size:12px;color:var(--byl-text-muted);margin:0 0 14px}
.rows{display:grid;gap:12px}.shipment-card{padding:16px;border:1px solid var(--byl-border-subtle);border-radius:12px;background:var(--byl-surface)}
.card-title,.card-foot{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.card-title a{font-weight:700;color:var(--byl-text);overflow-wrap:anywhere}
.meta,.details{display:flex;gap:8px 24px;flex-wrap:wrap;font-size:13px;margin:10px 0;color:var(--byl-text-muted)}
.details{color:var(--byl-text)}.card-foot{border-top:1px solid var(--byl-border-subtle);padding-top:10px;font-size:13px}
.meta span,.details span,.card-foot span{min-width:0;overflow-wrap:anywhere}
.kol-logistics .mvp-toolbar{display:flex;flex-wrap:wrap;max-width:100%}
.kol-logistics .mvp-toolbar input{flex:1 1 240px;min-width:0;max-width:100%}
@media(max-width:640px){.shipment-card{padding:12px}.details{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:9px}.meta{display:grid;gap:5px}.card-title{align-items:flex-start}.kol-logistics .mvp-toolbar input{flex-basis:100%}}
</style>
