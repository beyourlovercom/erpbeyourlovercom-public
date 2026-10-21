<script setup lang="ts">
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'

export type CouponRow = {
  id: number; coupon_id: number | null; code: string; normalized_code: string
  discount_type: string | null; amount: string | null; provider_status: string | null
  date_expires: string | null; affiliate_id: number | null
  kol: { id: number; display_name: string } | null
  origin: string; sync_status: string; reconciliation_status: string; provisioning_status: string
  last_error_code: string | null; last_error_message: string | null; last_successful_sync_at: string | null
}
export type CouponCandidate = { kol_id: number; display_name: string; affiliate_id: number; provider_status: string | null }

const props = defineProps<{ canManageAffiliates: boolean; coupons: CouponRow[]; couponCandidates: CouponCandidate[] }>()
const syncForm = useForm<{ coupon_sync?: string }>({})
const form = useForm<{ kol_id: number | null; code: string; discount_type: string; amount: string; expiry_date: string; coupon_provision?: string }>({
  kol_id: null, code: '', discount_type: 'percent', amount: '', expiry_date: '',
})
const selected = computed(() => props.couponCandidates.find((row) => row.kol_id === form.kol_id) ?? null)
const canSubmit = computed(() => props.canManageAffiliates && !!form.kol_id && form.code.trim() !== '' && form.amount !== '' && !form.processing)

function syncCoupons(): void {
  if (!props.canManageAffiliates || syncForm.processing) return
  syncForm.post('/kol/affiliatewp/coupons/sync', { preserveScroll: true })
}
function submit(): void {
  if (!canSubmit.value) return
  form.post('/kol/affiliatewp/coupons/provision', {
    preserveScroll: true,
    onSuccess: () => form.reset('code', 'amount', 'expiry_date'),
  })
}
function time(value: string | null): string {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString()
}
</script>

<template>
  <div v-if="syncForm.errors.coupon_sync || form.errors.coupon_provision" class="mvp-card coupon-error">
    {{ syncForm.errors.coupon_sync || form.errors.coupon_provision }}
  </div>

  <div class="mvp-card coupon-create">
    <div class="coupon-create-heading">
      <div><strong>新建 Affiliate Coupon</strong><p>创建前同时检查 ERP mirror 与实时 WooCommerce；冲突只阻止，不覆盖。</p></div>
      <button type="button" :disabled="!canManageAffiliates || syncForm.processing" :title="canManageAffiliates ? '同步全部 WooCommerce Coupon' : '需要 Affiliate 管理权限'" @click="syncCoupons">
        {{ syncForm.processing ? '同步中…' : '同步 Coupon' }}
      </button>
    </div>
    <div class="coupon-form-grid">
      <label><span>KOL / Affiliate</span><select v-model.number="form.kol_id" :disabled="!canManageAffiliates"><option :value="null" disabled>选择已绑定 KOL</option><option v-for="candidate in couponCandidates" :key="candidate.kol_id" :value="candidate.kol_id">{{ candidate.display_name }} · Affiliate #{{ candidate.affiliate_id }}</option></select></label>
      <label><span>Coupon Code</span><input v-model="form.code" maxlength="255" placeholder="例如 ANDY15" :disabled="!canManageAffiliates" /></label>
      <label><span>折扣类型</span><select v-model="form.discount_type" :disabled="!canManageAffiliates"><option value="percent">百分比</option><option value="fixed_cart">固定购物车金额</option><option value="fixed_product">固定产品金额</option></select></label>
      <label><span>折扣金额</span><input v-model="form.amount" inputmode="decimal" placeholder="15 或 10.5" :disabled="!canManageAffiliates" /></label>
      <label><span>到期日</span><input v-model="form.expiry_date" type="date" :disabled="!canManageAffiliates" /></label>
      <div class="coupon-submit"><small v-if="selected">目标 Affiliate #{{ selected.affiliate_id }} · {{ selected.provider_status || 'provider status —' }}</small><button type="button" :disabled="!canSubmit" :title="canManageAffiliates ? '实时查重后创建并验证 Coupon + Affiliate binding' : '需要 Affiliate 管理权限'" @click="submit">{{ form.processing ? '创建中…' : '创建 Coupon' }}</button></div>
    </div>
  </div>

  <div v-if="coupons.length" class="mvp-card coupon-table-wrap">
    <table class="coupon-table">
      <thead><tr><th>Coupon</th><th>Coupon ID</th><th>KOL / Affiliate</th><th>折扣</th><th>到期</th><th>Provider</th><th>Provision</th><th>同步</th></tr></thead>
      <tbody><tr v-for="coupon in coupons" :key="coupon.id">
        <td><strong>{{ coupon.code || '（空 Coupon Code）' }}</strong><small>{{ coupon.normalized_code || 'provider invalid code' }}</small></td>
        <td>{{ coupon.coupon_id ?? '—' }}</td>
        <td><strong v-if="coupon.kol">{{ coupon.kol.display_name }} · #{{ coupon.kol.id }}</strong><span v-else class="coupon-muted">非 Affiliate / 未绑定 KOL</span><small>Affiliate #{{ coupon.affiliate_id ?? '—' }}</small></td>
        <td><strong>{{ coupon.amount ?? '—' }}</strong><small>{{ coupon.discount_type || '—' }}</small></td>
        <td>{{ time(coupon.date_expires) }}</td>
        <td>{{ coupon.provider_status || '—' }}</td>
        <td><strong>{{ coupon.provisioning_status }}</strong><small v-if="coupon.last_error_code" class="coupon-error-text">{{ coupon.last_error_code }} · {{ coupon.last_error_message || '—' }}</small></td>
        <td><strong>{{ coupon.reconciliation_status }}</strong><small>{{ coupon.sync_status }} · {{ time(coupon.last_successful_sync_at) }}</small></td>
      </tr></tbody>
    </table>
  </div>
  <div v-else class="mvp-card coupon-empty"><strong>暂无 Coupon mirror</strong><p>点击“同步 Coupon”读取全部 WooCommerce Coupon，包括非 Affiliate Coupon。</p></div>
</template>

<style scoped>
.coupon-error { padding: 14px 18px; color: #991b1b; }
.coupon-create { padding: 0; }
.coupon-create-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:16px 18px; border-bottom:1px solid #e2e8f0; }
.coupon-create-heading p { margin:4px 0 0; color:#64748b; font-size:13px; }
.coupon-create-heading button,.coupon-submit button { border:1px solid #cbd5e1; border-radius:8px; padding:9px 12px; background:#fff; cursor:pointer; }
.coupon-create-heading button:disabled,.coupon-submit button:disabled { color:#94a3b8; background:#f8fafc; cursor:not-allowed; }
.coupon-form-grid { display:grid; grid-template-columns:repeat(5,minmax(140px,1fr)) minmax(160px,auto); gap:12px; align-items:end; padding:16px 18px; }
.coupon-form-grid label span { display:block; margin-bottom:5px; color:#475569; font-size:12px; font-weight:600; }
.coupon-form-grid input,.coupon-form-grid select { width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:9px 10px; background:#fff; }
.coupon-submit { display:flex; flex-direction:column; gap:5px; align-items:stretch; }
.coupon-submit small,.coupon-table small { display:block; color:#64748b; font-size:12px; margin-top:3px; }
.coupon-table-wrap { overflow-x:auto; }
.coupon-table { width:100%; min-width:1180px; border-collapse:collapse; }
.coupon-table th,.coupon-table td { padding:12px 14px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; }
.coupon-table th { color:#64748b; font-size:12px; }.coupon-table td { color:#334155; font-size:13px; }
.coupon-muted { color:#94a3b8; font-size:12px; }.coupon-error-text { color:#991b1b !important; }
.coupon-empty { display:flex; min-height:180px; flex-direction:column; align-items:center; justify-content:center; gap:8px; padding:28px; color:#64748b; text-align:center; }
.coupon-empty strong { color:#0f172a; }.coupon-empty p { margin:0; }
@media (max-width:1100px){.coupon-form-grid{grid-template-columns:repeat(2,minmax(180px,1fr));}}
</style>
