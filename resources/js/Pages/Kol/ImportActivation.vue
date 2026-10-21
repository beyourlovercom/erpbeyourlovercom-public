<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import { useAccountGridPreferences, type GridColumnDefinition, type GridPreferenceValue } from '../../Composables/useAccountGridPreferences'

type Summary = { candidate_count: number; ready_count: number; blocked_count: number; existing_count: number; skipped_count: number }
type Owner = { id: number; name: string; email: string }
type PlanItem = {
  id: number; cluster_key: string; display_name: string; primary_email: string | null; profile_urls: string[]
  preview_status: string; readiness_status: string; block_reasons: string[]; effective_owner: Owner | null
  canonical_proposal: Record<string, any>; social_proposal: Array<Record<string, any>>; exact_identity: Record<string, any>
  existing_guard: Record<string, any>; source_owner_evidence: Record<string, any>; affiliate_evidence: Record<string, any>
  history_summary: Record<string, any>; created_kol: { id: number; display_name: string } | null; applied_at: string | null
}
type Plan = Summary & { id: number; status: string; plan_version: number; plan_checksum: string; generated_at: string | null; applied_at: string | null; blocker_summary: Record<string, number>; items: PlanItem[] }
type Preview = { id: number; reconciliation_batch_id: number; source_filename: string | null; source_checksum: string | null; source_rows: number; candidate_count: number; unresolved_source_rows: number; created_at: string | null }
type Workspace = { previews: Preview[]; preview: Preview | null; plan: Plan | null; live_summary: Summary; blocker_summary: Record<string, number>; import_enabled: boolean; environment: string }
type ColumnKey = 'owner' | 'source' | 'canonical' | 'affiliate' | 'existing_guard' | 'status'

const props = defineProps<{ workspace: Workspace; gridPreferenceNamespace: string; gridPreference: GridPreferenceValue<ColumnKey> | null }>()
const busy = ref(false)
const search = ref('')
const statusFilter = ref('')
const blockerFilter = ref('')
const expandedId = ref<number | null>(null)
const columnSettingsOpen = ref(false)
const summary = computed(() => props.workspace.plan ?? props.workspace.live_summary)
const plan = computed(() => props.workspace.plan)
const preview = computed(() => props.workspace.preview)

const defaultColumns: GridColumnDefinition<ColumnKey>[] = [
  { key: 'owner', label: 'ERP Owner' },
  { key: 'source', label: '来源证据' },
  { key: 'canonical', label: '将写入的 Canonical 字段' },
  { key: 'affiliate', label: 'Affiliate 证据' },
  { key: 'existing_guard', label: '身份重检' },
  { key: 'status', label: '导入状态' },
]
const { columns: columnPreferences, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({
  namespace: props.gridPreferenceNamespace,
  definitions: defaultColumns,
  serverValue: props.gridPreference,
})

const reasonLabel = (reason: string) => ({
  owner_unresolved: 'Owner 未映射', owner_decision_required: '多 Owner 需裁决', identity_conflict: '身份冲突',
  missing_display_name: '缺少名称', social_identity_limit: '社媒账号超过上限',
}[reason] ?? reason)
const statusLabel = (status: string) => ({ ready: '可导入', blocked: '已阻塞', existing: '已有 KOL', applied: '已导入' }[status] ?? status)
const checksumShort = computed(() => plan.value?.plan_checksum ? `${plan.value.plan_checksum.slice(0, 12)}…` : '—')
const sourceChecksumShort = computed(() => preview.value?.source_checksum ? `${preview.value.source_checksum.slice(0, 12)}…` : '—')
const isProduction = computed(() => props.workspace.environment === 'production')
const canApply = computed(() => !!plan.value && plan.value.status === 'prepared' && plan.value.ready_count > 0 && props.workspace.import_enabled)
const blockerSummary = computed(() => plan.value?.blocker_summary ?? props.workspace.blocker_summary)

const filteredItems = computed(() => {
  const items = plan.value?.items ?? []
  const needle = search.value.trim().toLowerCase()
  return items.filter((item) => {
    const haystack = [item.display_name, item.primary_email ?? '', ...(item.profile_urls ?? []), item.effective_owner?.name ?? '', ...(item.source_owner_evidence?.source_values ?? [])].join(' ').toLowerCase()
    if (needle && !haystack.includes(needle)) return false
    if (statusFilter.value && item.readiness_status !== statusFilter.value) return false
    if (blockerFilter.value && !item.block_reasons.includes(blockerFilter.value)) return false
    return true
  })
})

const prepare = () => {
  if (!preview.value || busy.value) return
  busy.value = true
  router.post('/kol/import-activation/prepare', { preview_batch_id: preview.value.id }, { preserveScroll: true, onFinish: () => { busy.value = false } })
}
const applyPlan = () => {
  if (!plan.value || !canApply.value || busy.value) return
  const message = `确认把 ${plan.value.ready_count} 个 KOL 写入 Canonical KOL Master？\n\nPlan checksum:\n${plan.value.plan_checksum}\n\n此操作只允许在已显式启用的环境执行。`
  if (!window.confirm(message)) return
  busy.value = true
  router.post(`/kol/import-activation/${plan.value.id}/apply`, {
    plan_checksum: plan.value.plan_checksum,
    ready_count: plan.value.ready_count,
  }, { preserveScroll: true, onFinish: () => { busy.value = false } })
}
const changePreview = (event: Event) => {
  const id = Number((event.target as HTMLSelectElement).value)
  if (id) router.get('/kol/import-activation', { preview_batch_id: id })
}
const toggleDetails = (id: number) => { expandedId.value = expandedId.value === id ? null : id }
const canonicalFields = (item: PlanItem) => Object.entries(item.canonical_proposal ?? {}).filter(([key]) => !['status', 'relationship_stage', 'approval_manager_id', 'finance_owner_id', 'owner_id'].includes(key))
const ownerSource = (item: PlanItem) => (item.source_owner_evidence?.source_values ?? []).join(' / ') || '—'
const sourceFacts = (item: PlanItem) => `${item.history_summary?.source_row_count ?? 0} 条记录 · ${item.history_summary?.source_order_count ?? 0} 单`
const affiliateText = (item: PlanItem) => {
  const ids = item.affiliate_evidence?.account_ids ?? []
  return ids.length ? `${ids.length} 个候选 · #${ids.join(' / #')}` : '—'
}
const guardText = (item: PlanItem) => {
  const guard = item.existing_guard ?? {}
  return guard.status === 'none' ? '无现有精确匹配' : guard.status === 'matched' ? `命中 KOL #${(guard.kol_ids ?? []).join(', ')}` : `冲突：${(guard.kol_ids ?? []).join(', ')}`
}
const blockerCount = (key: string) => blockerSummary.value[key] ?? 0
</script>
<template>
  <Head title="KOL 正式导入 · BYL ERP" />
  <AuthenticatedLayout title="KOL 正式导入" :breadcrumbs="['BYL ERP', '网红', '真实数据核对', 'Owner 映射', '正式导入']">
    <section class="activation-page">
      <div class="activation-header">
        <div>
          <p class="eyebrow">P4 · Canonical Import</p>
          <h1>KOL 正式导入</h1>
          <p>把已核对的身份候选转成可审计的 KOL Master 导入计划；生成计划不会创建 KOL。</p>
        </div>
        <div class="page-actions">
          <a class="erp-btn erp-btn-secondary" :href="preview ? `/kol/owner-mapping?preview_batch_id=${preview.id}` : '/kol/owner-mapping'">← 返回 Owner 映射</a>
          <button class="erp-btn erp-btn-secondary" type="button" :disabled="!preview || busy" @click="prepare">{{ busy ? '处理中…' : '↻ 生成 / 刷新导入计划' }}</button>
          <button class="erp-btn erp-btn-danger" type="button" :disabled="!canApply || busy" @click="applyPlan">确认导入到 KOL Master</button>
          <a v-if="plan?.status === 'applied'" class="erp-btn erp-btn-primary" :href="`/kol/affiliatewp/binding-activation?activation_batch_id=${plan.id}`">下一步：Affiliate 精确绑定 →</a>
        </div>
      </div>

      <section v-if="preview" class="source-card">
        <div class="source-main"><span class="p4-mark">P4</span><div><strong>{{ preview.source_filename }}</strong><span>P1 Batch #{{ preview.reconciliation_batch_id }} · {{ preview.source_rows }} 来源记录 · {{ preview.candidate_count }} KOL 身份候选 · SHA-256 {{ sourceChecksumShort }}</span></div></div>
        <select v-if="workspace.previews.length > 1" :value="preview.id" class="batch-select" @change="changePreview"><option v-for="item in workspace.previews" :key="item.id" :value="item.id">Preview #{{ item.id }} · {{ item.source_filename }}</option></select>
        <div class="safety-banner"><strong>高风险边界：</strong>生成计划不会创建 KOL；只有明确确认后才写入 KOL Master。Affiliate、订单、合作、内容、佣金、付款都不会在 P4 被写入。<span>🔒 {{ isProduction ? 'Production' : workspace.environment }} · {{ workspace.import_enabled ? '导入开关已显式启用' : '导入开关锁定' }}</span></div>
      </section>

      <div class="kpi-grid">
        <article><span>身份候选</span><strong>{{ summary.candidate_count }}</strong><small>P2 deterministic candidates</small></article>
        <article class="ready"><span>可导入</span><strong>{{ summary.ready_count }}</strong><small>Owner 与身份条件均满足</small></article>
        <article class="blocked"><span>当前阻塞</span><strong>{{ summary.blocked_count }}</strong><small>Fail-closed，不猜归属</small></article>
        <article><span>安全跳过来源行</span><strong>{{ summary.skipped_count }}</strong><small>无有效 Email / 社媒 URL</small></article>
      </div>

      <section class="blocker-card">
        <div><h2>当前导入阻塞原因</h2><p>这里只展示事实和数量；解决 Owner 映射后重新生成计划即可更新准备度。</p></div>
        <div class="blocker-chips">
          <span><b>{{ blockerCount('owner_unresolved') }}</b> Owner 尚未映射</span>
          <span><b>{{ blockerCount('owner_decision_required') }}</b> 多 Owner 待裁决</span>
          <span><b>{{ blockerCount('identity_conflict') }}</b> 身份冲突</span>
          <span><b>{{ plan?.items.filter(item => Object.keys(item.exact_identity?.variations ?? {}).length > 0).length ?? 0 }}</b> 历史字段存在变化</span>
          <span><b>{{ plan?.items.filter(item => (item.exact_identity?.emails ?? []).length > 1).length ?? 0 }}</b> 多 Email 证据</span>
          <span><b>{{ plan?.items.filter(item => (item.affiliate_evidence?.account_ids ?? []).length > 1).length ?? 0 }}</b> 多 Affiliate 证据</span>
        </div>
      </section>

      <section class="plan-card">
        <header class="plan-head">
          <div><h2>Canonical Import Plan</h2><p>每一行明确告诉你：会写什么、不会写什么、为什么能导入或被阻塞。</p></div>
          <div class="plan-meta"><span v-if="plan">Plan #{{ plan.id }} · v{{ plan.plan_version }} · {{ checksumShort }}</span><span v-else>计划尚未生成</span></div>
        </header>
        <div class="toolbar">
          <input v-model="search" type="search" placeholder="搜索 KOL / Email / 社媒 / Owner">
          <select v-model="statusFilter"><option value="">状态：全部</option><option value="ready">可导入</option><option value="blocked">已阻塞</option><option value="existing">已有 KOL</option><option value="applied">已导入</option></select>
          <select v-model="blockerFilter"><option value="">阻塞原因：全部</option><option value="owner_unresolved">Owner 未映射</option><option value="owner_decision_required">多 Owner 需裁决</option><option value="identity_conflict">身份冲突</option></select>
        </div>

        <div v-if="!plan" class="empty-plan">尚未生成导入计划。点击右上角“生成 / 刷新导入计划”只会写入 P4 计划证据，不会创建 KOL。</div>
        <div v-else class="grid-shell">
          <div class="settings-anchor"><button type="button" class="settings-trigger" @click="columnSettingsOpen = !columnSettingsOpen">…</button>
            <div v-if="columnSettingsOpen" class="settings-panel">
              <div class="settings-head"><strong>列设置</strong><button type="button" @click="reset">恢复默认</button></div>
              <small>{{ syncState === 'saving' ? '正在同步…' : syncState === 'error' ? '同步失败，保留本地缓存' : syncState === 'saved' ? '已同步到账号' : '设置绑定当前账号' }}</small>
              <div class="setting-row pinned"><span>⋮⋮</span><input type="checkbox" checked disabled><b>KOL 候选</b><em>固定</em></div>
              <div v-for="column in columnPreferences" :key="column.key" class="setting-row" draggable="true" @dragstart="startDrag(column.key)" @dragover.prevent @drop="drop(column.key)"><span>⋮⋮</span><input type="checkbox" :checked="column.visible" @change="toggle(column.key)"><b>{{ column.label }}</b></div>
            </div>
          </div>
          <div class="table-wrap">
            <table class="plan-table">
              <thead><tr><th class="identity-col">KOL 候选</th><th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th class="action-col"></th></tr></thead>
              <tbody>
                <template v-for="item in filteredItems" :key="item.id">
                  <tr>
                    <td class="identity-col"><div class="identity"><span class="avatar">{{ item.display_name.slice(0,1).toUpperCase() }}</span><div><strong>{{ item.display_name }}</strong><small>{{ item.primary_email ?? item.profile_urls?.[0] ?? '—' }}</small></div></div></td>
                    <td v-for="column in visibleColumns" :key="column.key">
                      <template v-if="column.key === 'owner'"><strong>{{ item.effective_owner?.name ?? '未映射' }}</strong><small>来源：{{ ownerSource(item) }}</small></template>
                      <template v-else-if="column.key === 'source'"><strong>{{ sourceFacts(item) }}</strong><small>{{ item.profile_urls?.length ?? 0 }} 个社媒 URL</small></template>
                      <template v-else-if="column.key === 'canonical'"><strong>{{ canonicalFields(item).slice(0,3).map(([key]) => key).join(' · ') || '—' }}</strong><small v-if="canonicalFields(item).length > 3">+{{ canonicalFields(item).length - 3 }} 个字段</small></template>
                      <template v-else-if="column.key === 'affiliate'"><strong>{{ affiliateText(item) }}</strong><small>P4 仅保留证据，不绑定</small></template>
                      <template v-else-if="column.key === 'existing_guard'"><strong>{{ guardText(item) }}</strong><small>Apply 前再次精确重检</small></template>
                      <template v-else-if="column.key === 'status'"><span class="status-badge" :class="`status-${item.readiness_status}`">{{ statusLabel(item.readiness_status) }}</span><small v-if="item.block_reasons.length">{{ item.block_reasons.map(reasonLabel).join(' · ') }}</small></template>
                    </td>
                    <td class="action-col"><button type="button" class="detail-btn" @click="toggleDetails(item.id)">{{ expandedId === item.id ? '收起' : '查看详情' }}</button></td>
                  </tr>
                  <tr v-if="expandedId === item.id" class="detail-row"><td :colspan="visibleColumns.length + 2"><div class="detail-grid">
                    <div><span>精确 Email</span><strong>{{ (item.exact_identity?.emails ?? []).join(' · ') || '—' }}</strong></div>
                    <div><span>精确社媒 URL</span><strong>{{ (item.profile_urls ?? []).join(' · ') || '—' }}</strong></div>
                    <div><span>有效 Owner</span><strong>{{ item.effective_owner ? `${item.effective_owner.name} (#${item.effective_owner.id})` : '—' }}</strong></div>
                    <div><span>Owner 决策状态</span><strong>{{ item.source_owner_evidence?.resolution_status ?? '—' }}</strong></div>
                    <div class="wide"><span>Canonical 写入快照</span><pre>{{ JSON.stringify(item.canonical_proposal, null, 2) }}</pre></div>
                    <div class="wide"><span>保留为证据 / 不自动写入</span><strong>字段变化：{{ Object.keys(item.exact_identity?.variations ?? {}).join(' · ') || '无' }}；Affiliate：{{ affiliateText(item) }}</strong></div>
                  </div></td></tr>
                </template>
                <tr v-if="filteredItems.length === 0"><td :colspan="visibleColumns.length + 2" class="empty-cell">当前筛选条件下没有计划项。</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="secondary-guard"><strong>确认导入时的二次保护：</strong><span>显示 Ready 数量 + Plan checksum，并执行最新 KOL exact-identity re-check；任何新重复身份都会阻断整批，不会创建第二个 KOL。Production 环境未显式开启时按钮保持不可用。</span></section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.activation-page{padding:20px;min-height:calc(100vh - 80px);background:#f4f7f8;color:#20313a}.activation-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:14px}.eyebrow{margin:0 0 5px;color:#33867e;font-size:12px;font-weight:800}.activation-header h1{margin:0;font-size:28px;color:#142126}.activation-header p{margin:7px 0 0;color:#72858d;font-size:13px}.page-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.erp-btn{height:38px;padding:0 13px;border-radius:9px;border:1px solid #cbd8dc;background:#fff;color:#455d66;font-size:12px;font-weight:750;cursor:pointer}.erp-btn:disabled{opacity:.42;cursor:not-allowed}.erp-btn-danger{background:#a8464d;border-color:#a8464d;color:#fff}.erp-btn-primary{background:#34877f;border-color:#34877f;color:#fff}.source-card,.blocker-card,.plan-card,.secondary-guard{background:#fff;border:1px solid #dce5e7;border-radius:14px;box-shadow:0 3px 12px rgba(22,48,58,.035)}.source-card{padding:18px 20px;margin-bottom:14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.source-main{display:flex;align-items:center;gap:12px;min-width:0;flex:1}.source-main>div{display:flex;flex-direction:column;gap:4px;min-width:0}.source-main strong{font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.source-main span{font-size:11px;color:#809198}.p4-mark{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:#e8f7f2;color:#27816e;font-weight:800}.batch-select{height:36px;border:1px solid #ccd9dd;border-radius:8px;padding:0 9px}.safety-banner{width:100%;padding:10px 13px;background:#fff7e8;border:1px solid #efcf91;border-radius:9px;color:#875b16;font-size:12px;display:flex;gap:8px;justify-content:space-between}.safety-banner span{font-weight:800;white-space:nowrap}.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.kpi-grid article{background:#fff;border:1px solid #dce5e7;border-radius:12px;padding:15px 17px;display:flex;flex-direction:column;gap:3px}.kpi-grid span{font-size:11px;color:#72858d;font-weight:700}.kpi-grid strong{font-size:30px;line-height:1.05}.kpi-grid small{font-size:10px;color:#96a3a8}.kpi-grid .ready strong{color:#26845e}.kpi-grid .blocked strong{color:#d27f12}
.blocker-card{padding:17px 19px;margin-bottom:14px}.blocker-card h2,.plan-head h2{margin:0;font-size:18px;color:#1d3038}.blocker-card p,.plan-head p{margin:5px 0 0;font-size:12px;color:#7b8d94}.blocker-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:13px}.blocker-chips span{padding:7px 9px;border:1px solid #dce5e7;border-radius:8px;background:#f8fafb;color:#5f737b;font-size:11px}.blocker-chips b{color:#d17c10;margin-right:3px}.plan-card{position:relative;overflow:hidden}.plan-head{padding:17px 19px;display:flex;justify-content:space-between;gap:16px;align-items:flex-start;border-bottom:1px solid #e9eef0}.plan-meta{font-size:11px;color:#7b8d94}.toolbar{display:flex;gap:8px;padding:11px 19px;border-bottom:1px solid #edf1f2}.toolbar input,.toolbar select{height:36px;border:1px solid #ccd9dd;border-radius:8px;background:#fff;padding:0 10px;color:#40565f;font-size:12px}.toolbar input{min-width:300px;flex:0 1 390px}.empty-plan{padding:40px;text-align:center;color:#7f9097;font-size:13px}.grid-shell{position:relative}.table-wrap{overflow-x:auto}.plan-table{width:100%;min-width:1220px;border-collapse:collapse}.plan-table th{height:44px;padding:8px 11px;background:#f8fafb;border-bottom:1px solid #dfe7e9;text-align:left;color:#596e76;font-size:10px;white-space:nowrap}.plan-table td{padding:11px;border-bottom:1px solid #edf1f2;color:#40555e;font-size:11px;vertical-align:middle}.plan-table td>small{display:block;margin-top:3px;color:#91a0a6;font-size:9px}.identity-col{position:sticky;left:0;min-width:205px;background:#fff!important;z-index:3}.plan-table thead .identity-col{background:#f8fafb!important;z-index:5}.action-col{position:sticky;right:0;width:78px;min-width:78px;background:#fff!important;z-index:4}.plan-table thead .action-col{background:#f8fafb!important}.identity{display:flex;gap:9px;align-items:center}.avatar{width:31px;height:31px;border-radius:50%;display:grid;place-items:center;background:#edf2ff;color:#4a6ec3;font-weight:800}.identity div{min-width:0}.identity strong,.identity small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.identity small{color:#8b9aa0;font-size:9px;margin-top:2px}.status-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:9px;font-weight:800}.status-ready{background:#e7f6ee;color:#257652}.status-blocked{background:#fff0d8;color:#a76709}.status-existing{background:#eef2f3;color:#62757d}.status-applied{background:#e9efff;color:#4567b8}.detail-btn{height:28px;border:1px solid #ccd9dd;border-radius:7px;background:#fff;color:#287b74;font-size:10px;cursor:pointer}.detail-row td{background:#fafcfc;padding:13px 17px}.detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 18px}.detail-grid span{display:block;color:#8a989e;font-size:9px;margin-bottom:4px}.detail-grid strong{font-size:10px;overflow-wrap:anywhere}.detail-grid .wide{grid-column:1/-1}.detail-grid pre{margin:0;padding:9px;border-radius:7px;background:#f2f5f6;font-size:9px;white-space:pre-wrap;overflow-wrap:anywhere}.empty-cell{text-align:center;padding:30px!important;color:#8c9ba1!important}
.settings-anchor{position:absolute;top:0;right:0;width:78px;height:44px;display:flex;align-items:center;justify-content:center;border-left:1px solid #e2e8ea;border-bottom:1px solid #e2e8ea;background:#f8fafb;z-index:20}.settings-trigger{width:39px;height:29px;border:1px solid #d5dfe2;border-radius:7px;background:#fff;color:#40565f;font-size:19px;cursor:pointer}.settings-panel{position:absolute;top:38px;right:0;width:290px;padding:11px;background:#fff;border:1px solid #dce5e7;border-radius:10px;box-shadow:0 12px 30px rgba(20,40,48,.14);z-index:30}.settings-head{display:flex;justify-content:space-between}.settings-head button{border:0;background:transparent;color:#2d8c84;font-size:11px;cursor:pointer}.settings-panel>small{display:block;margin:6px 0;color:#839299;font-size:10px}.setting-row{display:grid;grid-template-columns:20px 20px 1fr auto;align-items:center;gap:5px;min-height:31px;padding:3px 4px;border-radius:6px;font-size:11px;cursor:grab}.setting-row:hover{background:#f4f7f8}.setting-row b{font-weight:600}.setting-row em{color:#8b9aa0;font-size:9px;font-style:normal}.setting-row.pinned{background:#f8fafb;cursor:default}.secondary-guard{margin-top:14px;padding:13px 16px;display:flex;gap:9px;align-items:flex-start;background:#f5fbf9;border-color:#cfe6df;color:#526b67;font-size:11px}.secondary-guard strong{color:#267568;white-space:nowrap}.secondary-guard span{line-height:1.55}@media(max-width:1000px){.activation-header{flex-direction:column}.page-actions{width:100%;justify-content:flex-start}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.toolbar{flex-wrap:wrap}.toolbar input{min-width:220px}.safety-banner{flex-direction:column}.safety-banner span{white-space:normal}}@media(max-width:620px){.activation-page{padding:12px}.activation-header h1{font-size:24px}.page-actions{display:grid;grid-template-columns:1fr}.page-actions .erp-btn{width:100%}.source-card{padding:15px}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.kpi-grid article{padding:12px}.kpi-grid strong{font-size:25px}.toolbar input,.toolbar select{width:100%;min-width:0}.blocker-chips{display:grid;grid-template-columns:1fr 1fr}.secondary-guard{flex-direction:column}.detail-grid{grid-template-columns:1fr}}
</style>
