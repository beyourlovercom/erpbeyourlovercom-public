<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../../Components/Logistics/StatusBar.vue'

type CurrencyCost = { currency: string; total: number; average: number | null; shipments: number }
type Carrier = { id: number; name: string; display_name: string; is_active: boolean; shipment_count: number; chargeable_weight_kg: number | null; chargeable_weight_coverage: number; costs_by_currency: CurrencyCost[]; this_month_count: number; last_shipped_at: string | null }
const props = defineProps<{ carriers: Carrier[]; canOperate: boolean; statusBar?: {key:string;label:string;url:string;count:number}[] }>()
const createOpen = ref(false)
const form = useForm({ name: '' })
const createCarrier = () => form.post('/logistics/carriers', { preserveScroll: true, onSuccess: () => { form.reset(); createOpen.value = false } })
const money = (costs: CurrencyCost[]) => costs.length === 0 ? '—' : costs.map((row) => `${row.currency === 'UNSPECIFIED' ? '未标币种' : row.currency} ${row.total.toFixed(2)}`).join(' / ')
const dateTime = (value: string | null) => value ? new Date(value).toLocaleString() : '—'
</script>

<template>
  <Head title="承运商 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '承运商']">
    <section class="mvp-page carrier-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">Carrier Master</p><h2>承运商</h2><p>Carrier Master 是海外仓发货的承运商事实源；Shipment 仅补充真实发货、重量与费用统计。</p></div>
        <div class="mvp-actions"><button v-if="props.canOperate" class="mvp-button mvp-button-primary" type="button" @click="createOpen = !createOpen">＋ 新增承运商</button></div>
      </div>

      <form v-if="props.canOperate && createOpen" class="mvp-card carrier-create" @submit.prevent="createCarrier">
        <label>承运商名称<input v-model="form.name" maxlength="128" required placeholder="例如 UniUni"></label>
        <div class="mvp-actions"><button class="mvp-button mvp-button-primary" :disabled="form.processing">新增</button><button class="mvp-button" type="button" @click="createOpen = false; form.reset()">取消</button></div>
        <p v-if="form.errors.name" class="mvp-error">{{ form.errors.name }}</p>
      </form>

      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>承运商</th><th>总发货票数</th><th>总计费重量</th><th>总物流费用</th><th>本月发货</th><th>最后发货</th></tr></thead>
          <tbody>
            <tr v-for="carrier in props.carriers" :key="carrier.id">
              <td><Link class="carrier-link" :href="`/logistics/carriers/${encodeURIComponent(carrier.name)}`">{{ carrier.display_name }}</Link></td>
              <td>{{ carrier.shipment_count }}</td><td>{{ carrier.chargeable_weight_kg === null ? '—' : `${carrier.chargeable_weight_kg.toFixed(3)} kg` }}</td><td>{{ money(carrier.costs_by_currency) }}</td><td>{{ carrier.this_month_count }}</td><td>{{ dateTime(carrier.last_shipped_at) }}</td>
            </tr>
            <tr v-if="props.carriers.length === 0"><td colspan="6" class="mvp-empty">暂无承运商主数据。</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.carrier-link { font-weight: 700; text-decoration: none; }
.carrier-link:hover { text-decoration: underline; }
.carrier-create { display: grid; grid-template-columns: minmax(220px, 420px) auto; align-items: end; gap: 16px; }
.carrier-create label { display: grid; gap: 7px; font-weight: 600; }
.carrier-create input { width: 100%; }
@media (max-width: 640px) { .carrier-create { grid-template-columns: 1fr; } }
</style>
