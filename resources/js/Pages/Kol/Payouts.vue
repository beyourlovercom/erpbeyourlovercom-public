<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type Eligible = { referral_id:number; affiliate_id:number; kol_id:number; amount:string; currency:string; order_number:string|null; source_order_number:string|null; order_reference:string; referral_date:string|null }
type RequestRow = { id:number; kol_id:number; affiliate_id:number; amount:string; currency:string; status:string; provider_payout_id:number|null; payout_recipient:string|null; can_approve?:boolean; can_complete?:boolean; paid_at:string|null; requested_by_user_id:number; approved_by_user_id:number|null; referral_ids:number[]; created_at:string|null }
const props = defineProps<{ connectionKey:string; eligible:Eligible[]; requests:RequestRow[]; canRequest:boolean; canApprove:boolean; canManage:boolean }>()
const selected = ref<number[]>([])
const createForm = useForm<{ referral_ids:number[]; payout_request?:string }>({ referral_ids: [] })
const evidence = reactive<Record<number,{transaction:string;paidAt:string}>>({})
const rejectReason = reactive<Record<number,string>>({})
const breadcrumbs = [{ label:'BYL ERP', href:'/dashboard' }, '网红', '结算工作流']
const selectedRows = computed(() => props.eligible.filter(row => selected.value.includes(row.referral_id)))
const selectedTotal = computed(() => selectedRows.value.reduce((sum,row) => sum + Number(row.amount), 0).toFixed(2))
type ColumnKey = 'order_number' | 'affiliate' | 'commission' | 'date'
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: 'ERP订单号' }, { key: 'affiliate', label: 'Affiliate / KOL' }, { key: 'commission', label: '佣金' }, { key: 'date', label: '时间' },
]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.kol.payouts.eligible-referrals.v1', definitions, requiredKeys: ['order_number'] })
const selectionValid = computed(() => selectedRows.value.length > 0 && new Set(selectedRows.value.map(r=>`${r.affiliate_id}|${r.kol_id}|${r.currency}`)).size === 1)
function submitRequest():void { if(!props.canRequest || !selectionValid.value || createForm.processing) return; createForm.referral_ids=[...selected.value].sort((a,b)=>a-b); createForm.post('/kol/payouts',{preserveScroll:true,onSuccess:()=>{selected.value=[]}}) }
function syncPayouts():void { if(props.canManage) router.post('/kol/payouts/sync',{}, { preserveScroll:true }) }
function approve(id:number):void { if(props.canApprove) router.post(`/kol/payouts/${id}/approve`,{}, { preserveScroll:true }) }
function reject(id:number):void { const reason=(rejectReason[id]||'').trim(); if(props.canApprove && reason) router.post(`/kol/payouts/${id}/reject`,{reason},{preserveScroll:true}) }
function complete(id:number):void { const e=evidence[id]; if(props.canManage && e?.transaction && e?.paidAt) router.post(`/kol/payouts/${id}/complete`,{paypal_transaction_id:e.transaction,paid_at:e.paidAt},{preserveScroll:true}) }
function ensureEvidence(id:number):{transaction:string;paidAt:string} { return evidence[id] ??= {transaction:'',paidAt:new Date().toISOString().slice(0,10)} }
function money(currency:string,amount:string):string { return `${currency} ${amount}` }
</script>

<template>
  <Head title="结算工作流 · BYL ERP" />
  <AuthenticatedLayout title="结算工作流" :breadcrumbs="breadcrumbs">
    <section class="mvp-page payout-page">
      <div class="mvp-page-heading payout-heading">
        <div><p class="mvp-kicker">KOL · Payout</p><h2>Commission Payout Workflow</h2><p>Referral 是佣金事实；ERP 先创建结算申请并审批，批准后才允许把人工 PayPal 付款证据写回 AffiliateWP。</p></div>
        <button v-if="canManage" type="button" @click="syncPayouts">同步 Provider Payout</button>
      </div>

      <div v-if="canRequest" class="mvp-card request-card">
        <div class="request-head"><div><strong>新建结算申请</strong><p>只能选择同一 KOL / Affiliate / Currency 的 eligible unpaid Referral。</p></div><div class="request-total"><span>{{ selected.length }} 条</span><strong>{{ selectedRows[0]?.currency || '—' }} {{ selectedTotal }}</strong></div></div>
        <div v-if="createForm.errors.payout_request" class="error">{{ createForm.errors.payout_request }}</div>
        <div class="eligible-wrap">
          <table><thead><tr><th></th><th>Referral</th><th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" /></tr></thead>
            <tbody><tr v-for="row in eligible" :key="row.referral_id"><td><input v-model="selected" type="checkbox" :value="row.referral_id"></td><td>#{{ row.referral_id }}</td><td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><strong>{{ row.order_number ?? '—' }}</strong></template><template v-else-if="column.key === 'affiliate'">Affiliate #{{ row.affiliate_id }} · KOL #{{ row.kol_id }}</template><template v-else-if="column.key === 'commission'">{{ money(row.currency,row.amount) }}</template><template v-else>{{ row.referral_date ? new Date(row.referral_date).toLocaleDateString() : '—' }}</template></td></tr></tbody>
          </table>
        </div>
        <div class="request-actions"><span v-if="selected.length && !selectionValid" class="error">选择项必须属于同一 Affiliate / KOL / Currency。</span><button type="button" :disabled="!selectionValid || createForm.processing" @click="submitRequest">提交审批</button></div>
      </div>

      <div class="mvp-card requests-card">
        <div class="section-title"><strong>结算申请</strong><span>{{ connectionKey }}</span></div>
        <div v-if="requests.length" class="requests-list">
          <article v-for="row in requests" :key="row.id" class="request-row">
            <div class="request-main"><div><strong>#{{ row.id }} · {{ row.status }}</strong><p>Affiliate #{{ row.affiliate_id }} · KOL #{{ row.kol_id }} · Referral {{ row.referral_ids.map(id=>`#${id}`).join(', ') }}</p></div><strong>{{ money(row.currency,row.amount) }}</strong></div>
            <div class="meta"><span>申请人 User #{{ row.requested_by_user_id }}</span><span v-if="row.approved_by_user_id">审批人 User #{{ row.approved_by_user_id }}</span><span v-if="row.provider_payout_id">Provider Payout #{{ row.provider_payout_id }}</span><span v-if="row.can_complete === true && row.payout_recipient">PayPal 收款：{{ row.payout_recipient }}</span></div>
            <div v-if="row.can_approve === true && row.status==='pending_approval'" class="approval-actions"><button type="button" @click="approve(row.id)">批准</button><input v-model="rejectReason[row.id]" placeholder="驳回原因"><button type="button" :disabled="!(rejectReason[row.id]||'').trim()" @click="reject(row.id)">驳回</button></div>
            <div v-if="row.can_complete === true && ['approved','provider_error','readback_mismatch'].includes(row.status)" class="complete-actions"><input v-model="ensureEvidence(row.id).transaction" autocomplete="off" placeholder="PayPal Transaction ID"><input v-model="ensureEvidence(row.id).paidAt" type="date"><button type="button" @click="complete(row.id)">{{ row.status==='approved' ? '确认已付款并写回' : '按原证据重试写回' }}</button></div>
          </article>
        </div>
        <div v-else class="empty">暂无结算申请。</div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.payout-page{display:grid;gap:14px}.payout-heading{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.payout-heading p{max-width:800px}.payout-heading button,.request-actions button,.approval-actions button,.complete-actions button{border:1px solid #cbd5e1;border-radius:8px;background:#fff;padding:9px 13px;cursor:pointer}.request-card,.requests-card{padding:16px}.request-head,.request-main,.section-title,.meta,.request-actions,.approval-actions,.complete-actions{display:flex;align-items:center;gap:12px}.request-head,.request-main,.section-title{justify-content:space-between}.request-head p,.request-main p{margin:4px 0 0;color:#64748b;font-size:12px}.request-total{text-align:right}.request-total span,.request-total strong{display:block}.eligible-wrap{overflow:auto;max-height:420px;margin-top:14px;border:1px solid #e2e8f0;border-radius:8px}.eligible-wrap table{width:100%;min-width:850px;border-collapse:collapse}.eligible-wrap th,.eligible-wrap td{padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:12px}.request-actions{justify-content:flex-end;margin-top:12px}.error{color:#991b1b;font-size:12px}.section-title span,.meta{color:#64748b;font-size:12px}.requests-list{display:grid;gap:10px;margin-top:12px}.request-row{border:1px solid #e2e8f0;border-radius:10px;padding:13px}.meta{margin-top:8px;flex-wrap:wrap}.approval-actions,.complete-actions{margin-top:10px;flex-wrap:wrap}.approval-actions input,.complete-actions input{border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;min-width:180px}.empty{padding:42px;text-align:center;color:#64748b}button:disabled{cursor:not-allowed;color:#94a3b8}@media(max-width:900px){.payout-heading,.request-head,.request-main{flex-direction:column;align-items:flex-start}.request-total{text-align:left}}
</style>
