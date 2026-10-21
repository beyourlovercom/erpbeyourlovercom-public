<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import CouponPanel, { type CouponCandidate, type CouponRow } from './CouponPanel.vue'

type AffiliateRow = {
  id: number
  affiliate_id: number | null
  wordpress_user_id: number | null
  wordpress_user_email: string | null
  display_name: string | null
  email: string | null
  provider_status: string | null
  kol: { id: number; display_name: string } | null
  origin: string
  sync_status: string
  reconciliation_status: string
  provisioning_status: string
  last_error_code: string | null
  last_error_message: string | null
  last_successful_sync_at: string | null
}

type ProvisioningCandidate = {
  kol_id: number
  display_name: string
  contact_email: string | null
  approval_status: string
  affiliate_id: number | null
  wordpress_user_id: number | null
  provider_status: string | null
  provisioning_status: string
  last_error_code: string | null
  last_error_message: string | null
  can_provision: boolean
  disabled_reason: string | null
}

type Option = { value: number; label: string }

const props = defineProps<{
  canManageApprovals: boolean
  canManageAffiliates: boolean
  affiliates: AffiliateRow[]
  coupons: CouponRow[]
  provisioningCandidates: ProvisioningCandidate[]
  couponCandidates: CouponCandidate[]
  kolOptions: Option[]
  ownerOptions: Option[]
}>()

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '网红', 'AffiliateWP']
const syncForm = useForm<{ affiliatewp_sync?: string }>({})
const bindForm = useForm<{ account_id: number | null; kol_id: number | null; affiliatewp_binding?: string }>({ account_id: null, kol_id: null })
const convertForm = useForm<{ account_id: number | null; owner_id: number | null; display_name: string; affiliatewp_binding?: string }>({ account_id: null, owner_id: null, display_name: '' })
const provisionForm = useForm<{ kol_id: number | null; affiliatewp_provision?: string }>({ kol_id: null })
const activeMode = ref<'influencer' | 'coupon'>('influencer')
const actionAccountId = ref<number | null>(null)
const actionMode = ref<'bind' | 'convert' | null>(null)

const selectedAffiliate = computed(() => props.affiliates.find((row) => row.id === actionAccountId.value) ?? null)

function syncNow(): void {
  if (!props.canManageAffiliates || syncForm.processing) return
  syncForm.post('/kol/affiliatewp/sync', { preserveScroll: true })
}

function openBind(affiliate: AffiliateRow): void {
  if (!props.canManageAffiliates || affiliate.kol) return
  actionAccountId.value = affiliate.id
  actionMode.value = 'bind'
  bindForm.account_id = affiliate.id
  bindForm.kol_id = null
}

function openConvert(affiliate: AffiliateRow): void {
  if (!props.canManageAffiliates || affiliate.kol) return
  actionAccountId.value = affiliate.id
  actionMode.value = 'convert'
  convertForm.account_id = affiliate.id
  convertForm.owner_id = null
  convertForm.display_name = affiliate.display_name || ''
}

function closeAction(): void {
  actionAccountId.value = null
  actionMode.value = null
}

function submitBind(): void {
  if (!bindForm.account_id || !bindForm.kol_id || bindForm.processing) return
  bindForm.post('/kol/affiliatewp/bind', { preserveScroll: true, onSuccess: closeAction })
}

function submitConvert(): void {
  if (!convertForm.account_id || !convertForm.owner_id || convertForm.processing) return
  convertForm.post('/kol/affiliatewp/convert', { preserveScroll: true, onSuccess: closeAction })
}

function provision(candidate: ProvisioningCandidate): void {
  if (!candidate.can_provision || provisionForm.processing) return
  provisionForm.kol_id = candidate.kol_id
  provisionForm.post('/kol/affiliatewp/provision', { preserveScroll: true })
}

function provisionLabel(candidate: ProvisioningCandidate): string {
  if (candidate.provisioning_status === 'active') return '已激活'
  if (candidate.provisioning_status === 'in_progress') return '处理中…'
  if (['error', 'conflict', 'readback_mismatch'].includes(candidate.provisioning_status)) return '安全重试'
  if (candidate.affiliate_id && candidate.provider_status) return '已有 Affiliate'
  return 'Provision'
}

function formatSyncTime(value: string | null): string {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString()
}
</script>

<template>
  <Head title="AffiliateWP · BYL ERP" />
  <AuthenticatedLayout title="AffiliateWP" :breadcrumbs="breadcrumbs">
    <section class="mvp-page affiliatewp-page">
      <div class="mvp-page-heading affiliatewp-heading">
        <div>
          <p class="mvp-kicker">KOL · AffiliateWP</p>
          <h2>AffiliateWP</h2>
          <p>统一操作 AffiliateWP Influencer 与 WooCommerce Coupon，并保持 Provider truth 可核验。</p>
        </div>
        <div class="page-header-actions">
        <Link v-if="canManageAffiliates" class="affiliatewp-sync" href="/kol/affiliatewp/reconciliation">真实数据核对</Link>
        <button v-if="activeMode === 'influencer'" class="affiliatewp-sync" type="button" :disabled="!canManageAffiliates || syncForm.processing" :title="canManageAffiliates ? '从 WordPress / AffiliateWP 读取完整 Influencer 快照' : '需要 Affiliate 管理权限'" @click="syncNow">
          {{ syncForm.processing ? '同步中…' : '立即同步' }}
        </button>
        </div>
      </div>

      <div class="affiliatewp-tabs" role="tablist" aria-label="AffiliateWP workspace modes">
        <button class="affiliatewp-tab" :class="{ 'is-active': activeMode === 'influencer' }" type="button" role="tab" :aria-selected="activeMode === 'influencer'" @click="activeMode = 'influencer'">Influencer</button>
        <button class="affiliatewp-tab" :class="{ 'is-active': activeMode === 'coupon' }" type="button" role="tab" :aria-selected="activeMode === 'coupon'" @click="activeMode = 'coupon'">Coupon</button>
      </div>

      <div class="mvp-card affiliatewp-capabilities">
        <span>审批权限：{{ canManageApprovals ? '可管理' : '只读' }}</span>
        <span>Affiliate 管理：{{ canManageAffiliates ? '可管理' : '只读' }}</span>
      </div>

      <CouponPanel v-if="activeMode === 'coupon'" :can-manage-affiliates="canManageAffiliates" :coupons="coupons" :coupon-candidates="couponCandidates" />

      <template v-if="activeMode === 'influencer'">
      <div v-if="syncForm.errors.affiliatewp_sync || bindForm.errors.affiliatewp_binding || convertForm.errors.affiliatewp_binding || provisionForm.errors.affiliatewp_provision" class="mvp-card affiliatewp-error">
        {{ syncForm.errors.affiliatewp_sync || bindForm.errors.affiliatewp_binding || convertForm.errors.affiliatewp_binding || provisionForm.errors.affiliatewp_provision }}
      </div>

      <div class="mvp-card affiliatewp-provisioning" data-testid="provisioning-panel">
        <div class="affiliatewp-provisioning-heading">
          <div>
            <strong>Approved KOL Provisioning</strong>
            <p>只对已批准、未归档的 ERP KOL 提供入口。Provider 失败、冲突或 read-back mismatch 会保留明确状态并复用稳定 idempotency key。</p>
          </div>
        </div>
        <div v-if="provisioningCandidates.length" class="affiliatewp-provisioning-table-wrap">
          <table class="affiliatewp-provisioning-table">
            <thead><tr><th>KOL</th><th>审批</th><th>Affiliate / WP User</th><th>Provider</th><th>Provision 状态</th><th>状态反馈</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="candidate in provisioningCandidates" :key="candidate.kol_id">
                <td><strong>{{ candidate.display_name }}</strong><small>#{{ candidate.kol_id }} · {{ candidate.contact_email || '—' }}</small></td>
                <td>{{ candidate.approval_status }}</td>
                <td><strong>{{ candidate.affiliate_id ?? '—' }}</strong><small>WP User: {{ candidate.wordpress_user_id ?? '—' }}</small></td>
                <td>{{ candidate.provider_status || '—' }}</td>
                <td><strong>{{ candidate.provisioning_status }}</strong></td>
                <td><strong v-if="candidate.last_error_code" class="affiliatewp-status-error">{{ candidate.last_error_code }}</strong><small>{{ candidate.last_error_message || candidate.disabled_reason || '—' }}</small></td>
                <td class="affiliatewp-actions">
                  <button type="button" :disabled="!candidate.can_provision || provisionForm.processing" :title="candidate.disabled_reason || (['error', 'conflict', 'readback_mismatch'].includes(candidate.provisioning_status) ? '使用原 idempotency key 安全重试' : '为已批准 KOL 创建 WordPress User + active Affiliate')" @click="provision(candidate)">
                    {{ provisionForm.processing && provisionForm.kol_id === candidate.kol_id ? '提交中…' : provisionLabel(candidate) }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="affiliatewp-provisioning-empty">当前作用域没有已批准且未归档的 KOL。</p>
      </div>

      <div v-if="affiliates.length" class="mvp-card affiliatewp-table-wrap">
        <table class="affiliatewp-table">
          <thead><tr><th>Influencer</th><th>Affiliate ID</th><th>WordPress User</th><th>ERP KOL</th><th>Provider 状态</th><th>Provision 状态</th><th>同步状态</th><th>最近成功同步</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="affiliate in affiliates" :key="affiliate.id">
              <td><strong>{{ affiliate.display_name || affiliate.email || '未命名 Affiliate' }}</strong><small>{{ affiliate.email || '—' }}</small></td>
              <td>{{ affiliate.affiliate_id ?? '—' }}</td>
              <td><strong>{{ affiliate.wordpress_user_id ?? '—' }}</strong><small>{{ affiliate.wordpress_user_email || '—' }}</small></td>
              <td><span v-if="affiliate.kol">{{ affiliate.kol.display_name }} · #{{ affiliate.kol.id }}</span><span v-else class="affiliatewp-muted">未绑定</span></td>
              <td>{{ affiliate.provider_status || '—' }}</td>
              <td><strong>{{ affiliate.provisioning_status }}</strong><small v-if="affiliate.last_error_code" class="affiliatewp-status-error">{{ affiliate.last_error_code }} · {{ affiliate.last_error_message || '—' }}</small></td>
              <td><strong>{{ affiliate.reconciliation_status }}</strong><small>{{ affiliate.sync_status }}</small></td>
              <td>{{ formatSyncTime(affiliate.last_successful_sync_at) }}</td>
              <td class="affiliatewp-actions">
                <span v-if="affiliate.kol" class="affiliatewp-muted">已绑定</span>
                <template v-else>
                  <button type="button" :disabled="!canManageAffiliates" :title="canManageAffiliates ? '绑定到现有 KOL' : '需要 Affiliate 管理权限'" @click="openBind(affiliate)">绑定现有 KOL</button>
                  <button type="button" :disabled="!canManageAffiliates" :title="canManageAffiliates ? '转为新 KOL' : '需要 Affiliate 管理权限'" @click="openConvert(affiliate)">转为新 KOL</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="selectedAffiliate && actionMode === 'bind'" class="mvp-card affiliatewp-action-panel" data-testid="bind-panel">
        <div><strong>绑定现有 KOL</strong><p>Affiliate #{{ selectedAffiliate.affiliate_id }} · {{ selectedAffiliate.display_name || selectedAffiliate.email || '未命名' }}</p></div>
        <select v-model.number="bindForm.kol_id" aria-label="选择现有 KOL"><option :value="null" disabled>选择 KOL</option><option v-for="option in kolOptions" :key="option.value" :value="option.value">{{ option.label }} · #{{ option.value }}</option></select>
        <div class="affiliatewp-action-buttons"><button type="button" @click="closeAction">取消</button><button type="button" :disabled="!bindForm.kol_id || bindForm.processing" @click="submitBind">确认绑定</button></div>
      </div>

      <div v-if="selectedAffiliate && actionMode === 'convert'" class="mvp-card affiliatewp-action-panel" data-testid="convert-panel">
        <div><strong>转为新 KOL</strong><p>仅在 ERP 创建 KOL 并绑定；不会改变 AffiliateWP 当前 Provider 状态。</p></div>
        <select v-model.number="convertForm.owner_id" aria-label="选择 KOL Owner"><option :value="null" disabled>选择 Owner</option><option v-for="option in ownerOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select>
        <input v-model="convertForm.display_name" type="text" maxlength="255" aria-label="KOL 名称" placeholder="KOL 名称（默认使用 Affiliate 名称）" />
        <div class="affiliatewp-action-buttons"><button type="button" @click="closeAction">取消</button><button type="button" :disabled="!convertForm.owner_id || convertForm.processing" @click="submitConvert">确认转换</button></div>
      </div>

      <div v-if="!affiliates.length" class="mvp-card affiliatewp-empty">
        <strong>暂无 AffiliateWP Influencer 同步数据</strong>
        <p>当前没有 provider mirror 数据。拥有 Affiliate 管理权限的用户可以点击“立即同步”读取完整 AffiliateWP Influencer 快照。</p>
      </div>
      </template>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.affiliatewp-page { display: flex; flex-direction: column; gap: 16px; }
.affiliatewp-heading { align-items: flex-start; gap: 16px; }
.affiliatewp-sync { min-width: 104px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 14px; background: #fff; color: #0f172a; cursor: pointer; }
.affiliatewp-sync:disabled { background: #f8fafc; color: #94a3b8; cursor: not-allowed; }
.affiliatewp-tabs { display: flex; gap: 8px; border-bottom: 1px solid #e2e8f0; }
.affiliatewp-tab { border: 0; border-bottom: 2px solid transparent; padding: 10px 14px; background: transparent; color: #64748b; font-weight: 600; }
.affiliatewp-tab.is-active { border-bottom-color: currentColor; color: #0f172a; }
.affiliatewp-tab:disabled { color: #94a3b8; cursor: not-allowed; }
.affiliatewp-capabilities { display: flex; flex-wrap: wrap; gap: 12px 24px; padding: 14px 18px; color: #475569; font-size: 13px; }
.affiliatewp-error { padding: 14px 18px; color: #991b1b; }
.affiliatewp-provisioning { padding: 0; overflow: hidden; }
.affiliatewp-provisioning-heading { padding: 16px 18px; border-bottom: 1px solid #e2e8f0; }
.affiliatewp-provisioning-heading p { margin: 5px 0 0; color: #64748b; font-size: 13px; line-height: 1.5; }
.affiliatewp-provisioning-table-wrap { overflow-x: auto; }
.affiliatewp-provisioning-table { width: 100%; min-width: 980px; border-collapse: collapse; }
.affiliatewp-provisioning-table th, .affiliatewp-provisioning-table td { padding: 11px 14px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
.affiliatewp-provisioning-table th { color: #64748b; font-size: 12px; }
.affiliatewp-provisioning-table td { color: #334155; font-size: 13px; }
.affiliatewp-provisioning-table strong, .affiliatewp-provisioning-table small { display: block; }
.affiliatewp-provisioning-table small { margin-top: 3px; color: #64748b; font-size: 12px; }
.affiliatewp-provisioning-empty { margin: 0; padding: 18px; color: #64748b; font-size: 13px; }
.affiliatewp-status-error { color: #991b1b; }
.affiliatewp-table-wrap { overflow-x: auto; }
.affiliatewp-table { width: 100%; border-collapse: collapse; min-width: 1240px; }
.affiliatewp-table th, .affiliatewp-table td { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
.affiliatewp-table th { color: #64748b; font-size: 12px; font-weight: 700; }
.affiliatewp-table td { color: #334155; font-size: 13px; }
.affiliatewp-table strong, .affiliatewp-table small { display: block; }
.affiliatewp-table small, .affiliatewp-muted { margin-top: 3px; color: #94a3b8; font-size: 12px; }
.affiliatewp-actions { min-width: 180px; }
.affiliatewp-actions button { margin: 0 8px 6px 0; border: 1px solid #cbd5e1; border-radius: 7px; padding: 6px 9px; background: #fff; color: #334155; cursor: pointer; font-size: 12px; }
.affiliatewp-actions button:disabled { background: #f8fafc; color: #94a3b8; cursor: not-allowed; }
.affiliatewp-action-panel { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(220px, 320px) minmax(180px, 280px); align-items: end; gap: 14px; padding: 18px; }
.affiliatewp-action-panel p { margin: 4px 0 0; color: #64748b; font-size: 13px; }
.affiliatewp-action-panel select, .affiliatewp-action-panel input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 10px; background: #fff; }
.affiliatewp-action-buttons { display: flex; justify-content: flex-end; gap: 8px; }
.affiliatewp-action-buttons button { border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 12px; background: #fff; cursor: pointer; }
.affiliatewp-action-buttons button:disabled { color: #94a3b8; cursor: not-allowed; }
.affiliatewp-empty { display: flex; min-height: 180px; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 28px; text-align: center; color: #64748b; }
.affiliatewp-empty strong { color: #0f172a; }
.affiliatewp-empty p { max-width: 720px; margin: 0; line-height: 1.6; }
@media (max-width: 900px) { .affiliatewp-action-panel { grid-template-columns: 1fr; } .affiliatewp-action-buttons { justify-content: flex-start; } }
</style>
