<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Run = {
  id:number; status:string; started_at:string|null; completed_at:string|null;
  requested_items:number; succeeded_items:number; failed_items:number; conflict_items:number
}
type Connection = {
  configured:boolean; url:string; connection_key:string; key_id:string;
  verify_tls:boolean; secret_set:boolean
}
type Health = { status:string|null; checked_at:string|null; data:Record<string, unknown>|null }
type EmailTemplate = {
  template_key:string; provider:'wordpress'|'andy_core'; label:string; status:'published';
  runtime_enabled:boolean; published_version:number; content_hash_sha256:string; published_at:string
}
type EmailTemplates = {
  status:'ready'|'error'|'unconfigured'; read_only:boolean; resource_contract_version:string;
  count:number; items:EmailTemplate[]; error_code?:string; message?:string
}
type ResourceKey = 'subscribers'|'affiliates'|'coupons'|'referrals'|'payouts'
type Permissions = { can_manage_connection:boolean; can_sync_subscribers:boolean; can_sync_kol:boolean; can_sync_payouts:boolean }

const props = defineProps<{
  connection:Connection
  health:Health
  runs:Record<ResourceKey, Run|null>
  emailTemplates:EmailTemplates
  permissions:Permissions
}>()

const connectionForm = useForm({
  site_url: props.connection.url,
  connection_key: props.connection.connection_key,
  key_id: props.connection.key_id,
  shared_secret: '',
  verify_tls: props.connection.verify_tls,
})
const healthForm = useForm({})
const syncForms = {
  subscribers: useForm({}),
  affiliates: useForm({}),
  coupons: useForm({}),
  referrals: useForm({}),
  payouts: useForm({}),
}
const resources: Array<{key:ResourceKey; label:string; path:string|null; enabled:boolean; note:string}> = [
  { key:'subscribers', label:'Subscribers', path:'/sync/wpapi/subscribers', enabled:props.permissions.can_sync_subscribers, note:'Marketing canonical subscriber truth' },
  { key:'affiliates', label:'Affiliates', path:'/sync/wpapi/affiliates', enabled:props.permissions.can_sync_kol, note:'KOL Affiliate mirror' },
  { key:'coupons', label:'Coupons', path:'/sync/wpapi/coupons', enabled:props.permissions.can_sync_kol, note:'KOL Affiliate Coupon mirror' },
  { key:'referrals', label:'Referrals', path:'/sync/wpapi/referrals', enabled:props.permissions.can_sync_kol, note:'KOL Referral / Commission truth' },
  { key:'payouts', label:'Payouts', path:'/sync/wpapi/payouts', enabled:props.permissions.can_sync_payouts, note:'KOL canonical Payout mirror' },
]

const saveConnection = () => connectionForm.put('/sync/wpapi/connection', { preserveScroll: true })
const testConnection = () => healthForm.post('/sync/wpapi/health', { preserveScroll: true })
const syncResource = (key:ResourceKey, path:string) => syncForms[key].post(path, { preserveScroll: true })
const isProcessing = (key:ResourceKey) => syncForms[key].processing
const runSummary = (key:ResourceKey) => {
  const run = props.runs[key]
  if (!run) return '尚未同步'
  return `最近：${run.status} · 成功 ${run.succeeded_items} · 失败 ${run.failed_items}`
}
</script>

<template>
  <Head title="WordPress Connector · BYL ERP" />
  <AuthenticatedLayout title="WordPress Connector" :breadcrumbs="['BYL ERP','同步','WordPress Connector']">
    <section class="mvp-page connector-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">SYNC / WORDPRESS CONNECTOR</p>
          <h2>WordPress Connector</h2>
          <p>集中管理 Andy Core 连接、健康状态、WordPress / AffiliateWP 导入与后续写回。</p>
        </div>
        <div class="mvp-form-actions page-header-actions">
          <Link class="mvp-button" href="/sync">返回同步</Link>
          <button class="mvp-button" :disabled="healthForm.processing || !props.connection.configured || !props.permissions.can_manage_connection" @click="testConnection">
            {{ healthForm.processing ? '测试中…' : '测试连接' }}
          </button>
        </div>
      </div>

      <section class="mvp-panel connector-panel">
        <div class="section-heading">
          <div><p class="mvp-kicker">CONNECTION</p><h3>连接设置</h3></div>
          <span class="status-pill" :class="props.connection.configured ? 'is-ok' : 'is-muted'">{{ props.connection.configured ? '已配置' : '未配置' }}</span>
        </div>
        <div class="form-grid">
          <label><span>Site URL</span><input v-model="connectionForm.site_url" type="url" :disabled="!props.permissions.can_manage_connection" placeholder="https://localdev.beyourlover.com" /></label>
          <label><span>Connection Key</span><input v-model="connectionForm.connection_key" type="text" :disabled="!props.permissions.can_manage_connection" /></label>
          <label><span>Key ID</span><input v-model="connectionForm.key_id" type="text" :disabled="!props.permissions.can_manage_connection" /></label>
          <label><span>Shared Secret</span><input v-model="connectionForm.shared_secret" type="password" autocomplete="new-password" :disabled="!props.permissions.can_manage_connection" :placeholder="props.connection.secret_set ? '已保存，留空保持不变' : '输入 Shared Secret'" /></label>
        </div>
        <label class="check-row"><input v-model="connectionForm.verify_tls" type="checkbox" :disabled="!props.permissions.can_manage_connection" /><span>验证 TLS 证书</span></label>
        <div class="form-footer">
          <p>Secret 保存后不会再次显示，也不会返回到前端。</p>
          <button class="mvp-button mvp-button-primary" :disabled="connectionForm.processing || !props.permissions.can_manage_connection" @click="saveConnection">{{ connectionForm.processing ? '保存中…' : '保存连接' }}</button>
        </div>
        <div v-if="Object.keys(connectionForm.errors).length" class="error-box"><p v-for="(message, key) in connectionForm.errors" :key="key">{{ message }}</p></div>
      </section>

      <section class="mvp-panel connector-panel">
        <div class="section-heading">
          <div><p class="mvp-kicker">HEALTH</p><h3>连接状态</h3></div>
          <span class="status-pill" :class="props.health.status === 'connected' ? 'is-ok' : 'is-muted'">{{ props.health.status === 'connected' ? 'Connected' : (props.health.status === 'error' ? 'Error' : 'Not tested') }}</span>
        </div>
        <dl class="health-grid">
          <div><dt>Site</dt><dd>{{ props.connection.url || '—' }}</dd></div>
          <div><dt>Contract</dt><dd>{{ props.health.data?.contract_version ?? '—' }}</dd></div>
          <div><dt>Andy Core</dt><dd>{{ props.health.data?.andy_core_version ?? '—' }}</dd></div>
          <div><dt>WordPress</dt><dd>{{ props.health.data?.wp_version ?? '—' }}</dd></div>
          <div><dt>WooCommerce</dt><dd>{{ props.health.data?.woocommerce_active === true ? 'Ready' : (props.health.data?.woocommerce_active === false ? 'Inactive' : '—') }}</dd></div>
          <div><dt>AffiliateWP</dt><dd>{{ props.health.data?.affiliatewp_active === true ? 'Ready' : (props.health.data?.affiliatewp_active === false ? 'Inactive' : '—') }}</dd></div>
          <div><dt>Writable</dt><dd>{{ props.health.data?.writable === true ? 'Yes' : (props.health.data?.writable === false ? 'No' : '—') }}</dd></div>
          <div><dt>Last check</dt><dd>{{ props.health.checked_at || '—' }}</dd></div>
        </dl>
        <p v-if="props.health.data?.message" class="error-box">{{ props.health.data.message }}</p>
      </section>

      <section class="mvp-panel connector-panel">
        <div class="section-heading">
          <div><p class="mvp-kicker">EMAIL OS / READ ONLY</p><h3>已发布邮件模板</h3></div>
          <span class="status-pill" :class="props.emailTemplates.status === 'ready' ? 'is-ok' : 'is-muted'">只读</span>
        </div>
        <p class="readonly-note">实时读取 Andy Core Email OS Published Snapshot 元数据。ERP 不保存模板正文，也不能编辑、发布、回滚或发送邮件。</p>
        <div class="email-template-summary">
          <div><span>Contract</span><strong>{{ props.emailTemplates.resource_contract_version }}</strong></div>
          <div><span>Published Native</span><strong>{{ props.emailTemplates.count }}</strong></div>
          <div><span>Source</span><strong>WordPress Email OS</strong></div>
        </div>
        <p v-if="props.emailTemplates.status === 'unconfigured'" class="empty-state">请先完成 WordPress Connector 配置。</p>
        <p v-else-if="props.emailTemplates.status === 'error'" class="error-box">{{ props.emailTemplates.message || 'Email OS Published Snapshot 暂不可读取。' }}</p>
        <div v-else-if="props.emailTemplates.items.length" class="email-template-table-wrap">
          <table class="email-template-table">
            <thead><tr><th>模板</th><th>Provider</th><th>版本</th><th>Runtime</th><th>发布时间</th><th>SHA-256</th></tr></thead>
            <tbody>
              <tr v-for="item in props.emailTemplates.items" :key="item.template_key">
                <td><strong>{{ item.label }}</strong><small>{{ item.template_key }}</small></td>
                <td>{{ item.provider === 'andy_core' ? 'Andy Core' : 'WordPress' }}</td>
                <td>v{{ item.published_version }}</td>
                <td><span class="status-pill" :class="item.runtime_enabled ? 'is-ok' : 'is-muted'">{{ item.runtime_enabled ? 'Enabled' : 'Disabled' }}</span></td>
                <td>{{ item.published_at }}</td>
                <td><code>{{ item.content_hash_sha256.slice(0, 12) }}…</code></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="empty-state">当前没有 Published Native 模板。Draft 不会显示在 ERP。</p>
      </section>
      <section class="mvp-panel connector-panel">
        <div class="section-heading"><div><p class="mvp-kicker">READ IMPORTS</p><h3>数据同步</h3></div></div>
        <div class="sync-list">
          <div v-for="resource in resources" :key="resource.key" class="sync-row">
            <div><strong>{{ resource.label }}</strong><span>{{ runSummary(resource.key) }}</span><small>{{ resource.note }}</small></div>
            <button class="mvp-button" :class="resource.enabled && resource.path ? 'mvp-button-primary' : ''" :disabled="!resource.enabled || !resource.path || isProcessing(resource.key) || !props.connection.configured" @click="resource.path && syncResource(resource.key, resource.path)">
              {{ isProcessing(resource.key) ? '同步中…' : '立即同步' }}
            </button>
          </div>
        </div>
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.connector-page{display:grid;gap:18px}.connector-panel{display:grid;gap:18px;padding:20px;border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-surface)}
.section-heading,.form-footer,.sync-row{display:flex;align-items:center;justify-content:space-between;gap:16px}.section-heading h3{margin:2px 0 0}.status-pill{padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}.is-ok{background:rgba(34,197,94,.12)}.is-muted{background:var(--byl-surface-muted);color:var(--byl-text-muted)}
.form-grid,.health-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.form-grid label{display:grid;gap:6px}.form-grid span,.form-footer p,.sync-row span,.sync-row small{font-size:13px;color:var(--byl-text-muted)}.form-grid input{width:100%;padding:10px 12px;border:1px solid var(--byl-border-subtle);border-radius:8px;background:var(--byl-surface)}
.check-row{display:flex;align-items:center;gap:8px}.health-grid{margin:0}.health-grid div{display:grid;gap:4px}.health-grid dt{font-size:12px;color:var(--byl-text-muted)}.health-grid dd{margin:0;font-weight:600}.error-box{margin:0;padding:10px 12px;border-radius:8px;background:rgba(239,68,68,.08);font-size:13px}.error-box p{margin:0}.sync-list{display:grid}.sync-row{padding:12px 0;border-top:1px solid var(--byl-border-subtle)}.sync-row:first-child{border-top:0}.sync-row div{display:grid;gap:3px}
.readonly-note,.empty-state{margin:0;font-size:13px;color:var(--byl-text-muted)}.email-template-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.email-template-summary div{display:grid;gap:3px;padding:12px;border:1px solid var(--byl-border-subtle);border-radius:8px;background:var(--byl-surface-muted)}.email-template-summary span{font-size:12px;color:var(--byl-text-muted)}.email-template-table-wrap{overflow:auto;border:1px solid var(--byl-border-subtle);border-radius:10px}.email-template-table{width:100%;min-width:760px;border-collapse:collapse}.email-template-table th,.email-template-table td{padding:10px 12px;border-bottom:1px solid var(--byl-border-subtle);text-align:left;font-size:13px;vertical-align:middle}.email-template-table th{font-size:12px;color:var(--byl-text-muted);background:var(--byl-surface-muted)}.email-template-table tbody tr:last-child td{border-bottom:0}.email-template-table td:first-child{display:grid;gap:3px}.email-template-table td:first-child small{color:var(--byl-text-muted)}.email-template-table code{font-size:12px}
@media(max-width:700px){.form-grid,.health-grid,.email-template-summary{grid-template-columns:1fr}.section-heading,.form-footer,.sync-row{align-items:flex-start;flex-direction:column}.sync-row .mvp-button{width:100%}}
</style>
