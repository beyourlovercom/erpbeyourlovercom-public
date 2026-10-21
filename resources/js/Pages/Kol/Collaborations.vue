<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ProductRow = { id: number; product_id: number | null; sku: string; name: string | null; quantity: number }
type Collaboration = { id: number; kol_id: number; kol: { id: number; display_name: string } | null; title: string | null; post_url: string | null; cooperation_type: string | null; status: string; approval_status:string; agreement_amount: number | null; currency: string; agreed_at: string | null; planned_publish_at: string | null; fulfillment_required: boolean; owner: { id: number; name: string } | null; products: ProductRow[] }
const props = defineProps<{ collaborations: Collaboration[]; filters: { search?: string; status?: string; post_url_status?: string; approval_status?:string }; canOperate: boolean }>()
const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')
const postUrlStatus = ref(props.filters.post_url_status ?? '')
const approvalStatus = ref(props.filters.approval_status ?? '')
const apply = () => router.get('/kol/collaborations', { search: search.value || undefined, status: status.value || undefined, post_url_status: postUrlStatus.value || undefined, approval_status: approvalStatus.value || undefined }, { preserveState: true, replace: true })
const statusLabel = (v:string) => ({ draft:'草稿', confirmed:'已确认', in_progress:'进行中', completed:'已完成', cancelled:'已取消' }[v] ?? v)
const approvalLabel=(v:string)=>({not_required:'无需审批',pending:'待审批',approved:'已批准',rejected:'已驳回'}[v]??v)
const money = (v:number|null,c:string) => v === null ? '—' : `${c} ${new Intl.NumberFormat('en-US',{maximumFractionDigits:2}).format(v)}`
const breadcrumbs = [{label:'BYL ERP',href:'/dashboard'},{label:'KOL',href:'/kol'},{label:'合作记录'}]
</script>
<template>
  <Head title="KOL 合作记录 · BYL ERP" />
  <AuthenticatedLayout title="合作记录" :breadcrumbs="breadcrumbs">
    <section class="collab-page">
      <div class="collab-toolbar">
        <div><h2>正式合作记录</h2><p>Opportunity 确认后进入正式 Collaboration；寄样/发货仍走 canonical KOL Order。</p></div>
        <Link class="erp-btn erp-btn-secondary" href="/kol/library">红人库</Link>
      </div>
      <div class="collab-filters"><input v-model="search" class="erp-input" placeholder="搜索发帖 URL、历史标题或 KOL" @keyup.enter="apply"><select v-model="status" class="erp-select" @change="apply"><option value="">全部状态</option><option value="draft">草稿</option><option value="confirmed">已确认</option><option value="in_progress">进行中</option><option value="completed">已完成</option><option value="cancelled">已取消</option></select><select v-model="postUrlStatus" class="erp-select" @change="apply"><option value="">全部发帖 URL</option><option value="missing">待补发帖 URL</option><option value="present">已填写发帖 URL</option></select><select v-model="approvalStatus" class="erp-select" @change="apply"><option value="">全部审批状态</option><option value="pending">待审批</option><option value="approved">已批准</option><option value="rejected">已驳回</option><option value="not_required">无需审批</option></select><button class="erp-btn erp-btn-primary" @click="apply">筛选</button></div>
      <div class="erp-card collab-table-wrap">
        <table class="collab-table"><thead><tr><th>KOL</th><th>合作发帖 URL</th><th>产品</th><th>金额</th><th>状态</th><th>审批</th><th>寄样/发货</th><th>负责人</th></tr></thead><tbody>
          <tr v-for="item in collaborations" :key="item.id"><td><Link :href="`/kol/library/${item.kol_id}`">{{ item.kol?.display_name ?? `KOL #${item.kol_id}` }}</Link></td><td><a v-if="item.post_url" :href="item.post_url" target="_blank" rel="noopener noreferrer"><strong>{{ item.post_url }}</strong></a><strong v-else>待补发帖 URL</strong><small>{{ item.cooperation_type ?? '—' }}</small></td><td><span v-if="item.products.length">{{ item.products.map(p => `${p.sku} ×${p.quantity}`).join('、') }}</span><span v-else>—</span></td><td>{{ money(item.agreement_amount,item.currency) }}</td><td>{{ statusLabel(item.status) }}</td><td>{{ approvalLabel(item.approval_status) }}</td><td>{{ item.fulfillment_required ? '需要' : '不需要' }}</td><td>{{ item.owner?.name ?? '—' }}</td></tr>
          <tr v-if="collaborations.length===0"><td colspan="8" class="collab-empty">暂无正式合作记录</td></tr>
        </tbody></table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
<style scoped>
.collab-page{display:grid;gap:16px}.collab-toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.collab-toolbar h2{margin:0 0 6px;font-size:22px}.collab-toolbar p{margin:0;color:var(--erp-text-muted)}.collab-filters{display:flex;gap:10px;align-items:center}.collab-filters .erp-input{min-width:320px}.collab-table-wrap{overflow:auto}.collab-table{width:100%;border-collapse:collapse;min-width:900px}.collab-table th,.collab-table td{padding:12px 14px;border-bottom:1px solid var(--erp-border);text-align:left;vertical-align:top}.collab-table th{font-size:12px;color:var(--erp-text-muted)}.collab-table td small{display:block;margin-top:4px;color:var(--erp-text-muted)}.collab-empty{text-align:center!important;padding:32px!important;color:var(--erp-text-muted)}@media(max-width:800px){.collab-toolbar,.collab-filters{align-items:stretch;flex-direction:column}.collab-filters .erp-input{min-width:0;width:100%}}
</style>
