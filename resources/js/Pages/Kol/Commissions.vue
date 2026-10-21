<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ReferralRow = {
  id:number; referral_id:number; affiliate_id:number; order_reference:string
  kol:{id:number;display_name:string}|null
  order:{id:number;erp_order_id:string|null}|null
  commission_amount:string; currency:string; provider_status:string; context:string
  referral_date:string|null; payout_id:number|null
  affiliate_resolution_status:string; order_resolution_status:string; reconciliation_status:string
}
type StatusSummary = Record<string,{count:number;amount:string}>
type Summary = { referrals:number; commission_amount:string; currency:string|null; by_status:StatusSummary; affiliate_unresolved:number; order_unresolved:number }
const props = defineProps<{ canSync:boolean; canRequestPayouts:boolean; canManagePayouts:boolean; connectionKey:string; referrals:ReferralRow[]; summary:Summary }>()
const form = useForm<{ referral_sync?:string }>({})
const breadcrumbs = [{ label:'BYL ERP', href:'/dashboard' }, '网红', '佣金与结算']
const statusRows = computed(() => Object.entries(props.summary.by_status).sort((a,b) => b[1].count-a[1].count))
function syncNow():void { if(props.canSync && !form.processing) form.post('/kol/commissions/sync',{preserveScroll:true}) }
function time(v:string|null):string { if(!v) return '—'; const d=new Date(v); return Number.isNaN(d.getTime())?v:d.toLocaleString() }
function orderLabel(row:ReferralRow):string { return row.order?.erp_order_id || '—' }
</script>

<template>
  <Head title="佣金与结算 · BYL ERP" />
  <AuthenticatedLayout title="佣金与结算" :breadcrumbs="breadcrumbs">
    <section class="mvp-page commission-page">
      <div class="mvp-page-heading commission-heading">
        <div><p class="mvp-kicker">KOL · AffiliateWP</p><h2>Referral / Commission</h2><p>AffiliateWP Referral 是佣金事实；ERP 只投影到 canonical KOL 与 Order，不创建第二套订单或付款账本。</p></div>
        <div class="heading-actions"><Link v-if="canRequestPayouts || canManagePayouts" href="/kol/payouts">结算工作流</Link><button type="button" :disabled="!canSync || form.processing" :title="canSync ? '读取完整 AffiliateWP Referral 快照' : '需要 Affiliate 管理权限'" @click="syncNow">{{ form.processing ? '同步中…' : '同步 Referral' }}</button></div>
      </div>
      <div v-if="form.errors.referral_sync" class="mvp-card commission-error">{{ form.errors.referral_sync }}</div>
      <div class="summary-grid">
        <div class="mvp-card summary-card"><span>Referral</span><strong>{{ summary.referrals }}</strong><small>{{ connectionKey }}</small></div>
        <div class="mvp-card summary-card"><span>佣金总额</span><strong>{{ summary.currency || '—' }} {{ summary.commission_amount }}</strong><small>Provider amount 汇总</small></div>
        <div class="mvp-card summary-card"><span>未解析 KOL</span><strong>{{ summary.affiliate_unresolved }}</strong><small>仅 exact Affiliate→KOL</small></div>
        <div class="mvp-card summary-card"><span>未解析订单</span><strong>{{ summary.order_unresolved }}</strong><small>保留历史 Referral，不猜订单</small></div>
      </div>
      <div v-if="statusRows.length" class="mvp-card status-strip">
        <div v-for="[status,bucket] in statusRows" :key="status"><strong>{{ status }}</strong><span>{{ bucket.count }} 条 · {{ summary.currency || '' }} {{ bucket.amount }}</span></div>
      </div>
      <div v-if="referrals.length" class="mvp-card table-wrap">
        <table class="commission-table">
          <thead><tr><th>Referral</th><th>KOL / Affiliate</th><th>Canonical Order</th><th>佣金</th><th>Provider 状态</th><th>时间</th><th>Payout evidence</th><th>对账</th></tr></thead>
          <tbody><tr v-for="row in referrals" :key="row.id">
            <td><strong>#{{ row.referral_id }}</strong><small>{{ row.context }} · ref {{ row.order_reference }}</small></td>
            <td><strong v-if="row.kol">{{ row.kol.display_name }} · #{{ row.kol.id }}</strong><span v-else class="muted">未解析 KOL</span><small>Affiliate #{{ row.affiliate_id }}</small></td>
            <td><strong v-if="row.order">{{ orderLabel(row) }}</strong><span v-else class="muted">历史订单未解析</span><small v-if="!row.order">Woo ref {{ row.order_reference }}</small></td>
            <td><strong>{{ row.currency }} {{ row.commission_amount }}</strong></td>
            <td><strong>{{ row.provider_status }}</strong></td>
            <td>{{ time(row.referral_date) }}</td>
            <td>{{ row.payout_id ? `#${row.payout_id}` : '—' }}</td>
            <td><strong>{{ row.reconciliation_status }}</strong><small>{{ row.affiliate_resolution_status }} · {{ row.order_resolution_status }}</small></td>
          </tr></tbody>
        </table>
      </div>
      <div v-else class="mvp-card empty"><strong>暂无 Referral mirror</strong><p>Manager 可执行完整 Referral 同步；普通 KOL 用户仅查看自己范围内已解析记录。</p></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.commission-page{display:grid;gap:14px}.commission-heading{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.commission-heading p{max-width:760px}.heading-actions{display:flex;gap:8px;align-items:center}.heading-actions a{border:1px solid #cbd5e1;border-radius:8px;background:#fff;padding:9px 13px;text-decoration:none;color:#334155}.commission-heading button{border:1px solid #cbd5e1;border-radius:8px;background:#fff;padding:9px 13px;cursor:pointer}.commission-heading button:disabled{cursor:not-allowed;color:#94a3b8;background:#f8fafc}.commission-error{padding:13px 16px;color:#991b1b}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.summary-card{padding:15px 17px}.summary-card span,.summary-card small{display:block;color:#64748b;font-size:12px}.summary-card strong{display:block;margin:5px 0;font-size:22px;color:#0f172a}.status-strip{display:flex;gap:12px;flex-wrap:wrap;padding:12px 16px}.status-strip div{min-width:140px}.status-strip strong,.status-strip span{display:block}.status-strip span{font-size:12px;color:#64748b;margin-top:3px}.table-wrap{overflow-x:auto}.commission-table{width:100%;min-width:1220px;border-collapse:collapse}.commission-table th,.commission-table td{padding:12px 14px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.commission-table th{font-size:12px;color:#64748b}.commission-table td{font-size:13px;color:#334155}.commission-table small{display:block;margin-top:3px;color:#64748b;font-size:12px}.muted{color:#94a3b8}.empty{min-height:190px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#64748b}.empty strong{color:#0f172a}.empty p{margin:6px 0 0}@media(max-width:1050px){.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.commission-heading{flex-direction:column}}
</style>
