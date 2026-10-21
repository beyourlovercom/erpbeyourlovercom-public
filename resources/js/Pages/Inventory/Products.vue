<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Row = {
  id:number
  warehouse:{slug:string;public_slug:string;name:string}
  product:{id:number;sku:string;name:string}
  inbound_at:string|null
  stay_days:number|null
  location:string|null
  quantity_on_hand:number
  quantity_reserved:number
  quantity_available:number
  stock_status:'out_of_stock'|'low_stock'|'in_stock'
  stock_status_label:string
  standard_qoh:number|null
  expected_restock_qty:number
  replenishment:{
    policy_status:'unconfigured'|'no_demand'|'reorder'|'healthy'
    policy_label:string
    shipped_90d_qty:number
    monthly_average:number
    daily_average:number
    days_of_cover:number|null
    safety_stock_qty:number|null
    reorder_point_qty:number|null
    recommended_restock_qty:number|null
  }
}
type W = {slug:string;public_slug:string;name:string}
const props = defineProps<{
  rows:{data:Row[];links:any[];current_page:number;last_page:number}
  warehouses:W[]
  filters:{search?:string;warehouse?:string;stock_status?:string}
}>()

const search = ref(props.filters.search || '')
const warehouse = ref(props.filters.warehouse || '')
const stockStatus = ref(props.filters.stock_status || '')
const apply = () => router.get('/inventory/products', {
  search: search.value,
  warehouse: warehouse.value,
  stock_status: stockStatus.value,
}, { preserveState:true, replace:true })
const breadcrumbs = [{label:'BYL ERP',href:'/dashboard'},{label:'库存',href:'/inventory'},'产品库存']
</script>

<template>
  <Head title="产品库存 · BYL ERP"/>
  <AuthenticatedLayout title="产品库存" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">产品 × 仓库</p><h2>产品对应仓库清单</h2><p>统一统计产品在各仓库的库存、上架时间与停留时间。</p></div>
        <Link class="mvp-button" href="/inventory/list">仓库清单</Link>
      </div>
      <div class="mvp-card filters">
        <input v-model="search" placeholder="搜索 SKU / 产品名称" @keyup.enter="apply">
        <select v-model="warehouse" @change="apply">
          <option value="">全部仓库</option>
          <option v-for="w in warehouses" :key="w.public_slug||w.slug" :value="w.public_slug||w.slug">{{w.name}}</option>
        </select>
        <select v-model="stockStatus" aria-label="库存状态" @change="apply">
          <option value="">全部库存状态</option>
          <option value="alert">库存预警（没货 + 紧张）</option>
          <option value="out_of_stock">没货</option>
          <option value="low_stock">库存紧张</option>
          <option value="in_stock">有货</option>
        </select>
        <button class="mvp-button" @click="apply">筛选</button>
      </div>
      <div class="mvp-card table-wrap">
        <table>
          <thead><tr><th>所属仓库</th><th>SKU</th><th>产品</th><th>上架时间</th><th>停留时间</th><th>Location</th><th>当前库存</th><th>预留</th><th>可用库存</th><th>状态</th><th>90天发货</th><th>月均 / 日均</th><th>可覆盖</th><th>安全库存</th><th>补货点</th><th>建议补货</th><th>Standard QoH</th><th>预计到货</th></tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id">
              <td><Link :href="`/inventory/${r.warehouse.public_slug||r.warehouse.slug}`">{{r.warehouse.name}}</Link></td>
              <td><strong>{{r.product.sku||'—'}}</strong></td><td>{{r.product.name}}</td><td>{{r.inbound_at||'—'}}</td>
              <td>{{r.stay_days===null?'—':`${r.stay_days} 天`}}</td><td>{{r.location||'—'}}</td>
              <td>{{r.quantity_on_hand}}</td><td>{{r.quantity_reserved}}</td><td><strong>{{r.quantity_available}}</strong></td>
              <td><span class="stock-badge" :class="`stock-${r.stock_status}`">{{r.stock_status_label}}</span></td>
              <td>{{r.replenishment.shipped_90d_qty}}</td><td>月 {{r.replenishment.monthly_average}} / 日 {{r.replenishment.daily_average}}</td>
              <td>{{r.replenishment.days_of_cover===null?'—':`${r.replenishment.days_of_cover} 天`}}</td><td>{{r.replenishment.safety_stock_qty??'—'}}</td>
              <td>{{r.replenishment.reorder_point_qty??'—'}}</td>
              <td><span class="replenishment-badge" :class="`replenishment-${r.replenishment.policy_status}`">{{r.replenishment.recommended_restock_qty===null?r.replenishment.policy_label:`${r.replenishment.policy_label} · ${r.replenishment.recommended_restock_qty}`}}</span></td>
              <td>{{r.standard_qoh??'—'}}</td><td>{{r.expected_restock_qty}}</td>
            </tr>
            <tr v-if="!rows.data.length"><td colspan="18">暂无库存数据</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.filters{display:flex;gap:10px;flex-wrap:wrap}.filters input,.filters select{padding:9px;border:1px solid #dbe2e8;border-radius:8px}.filters input{min-width:280px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap}th{font-size:12px;color:#64748b}.stock-badge{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}.stock-out_of_stock{background:#fee2e2;color:#991b1b}.stock-low_stock{background:#ffedd5;color:#9a3412}.stock-in_stock{background:#dcfce7;color:#166534}.replenishment-badge{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}.replenishment-reorder{background:#fee2e2;color:#991b1b}.replenishment-healthy{background:#dcfce7;color:#166534}.replenishment-no_demand,.replenishment-unconfigured{background:#f1f5f9;color:#475569}@media(max-width:700px){.filters>*{min-width:0!important;flex:1 1 100%}}
</style>
