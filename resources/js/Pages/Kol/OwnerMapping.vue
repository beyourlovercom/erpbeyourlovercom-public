<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type UserOption = { id: number; name: string; email: string }
type Mapping = { id: number; mapped_user_id: number | null; user: UserOption | null; status: string }
type SourceOwner = { key: string; value: string; single_candidate_count: number; mention_candidate_count: number; mapping: Mapping | null }
type Candidate = {
  id: number
  cluster_key: string
  display_name: string
  primary_email: string | null
  social_accounts: Array<Record<string, any>>
  source_row_count: number
  source_owner_values: string[]
  source_mappings: Array<{ source_owner: string; mapping_id: number | null; user: UserOption | null }>
  resolution_status: string
  resolved: boolean
  effective_owner: UserOption | null
  decision: { id: number; mapped_user_id: number | null; user: UserOption | null; reason: string | null; snapshot_matches: boolean } | null
  affiliate_account_ids: number[]
}
type PreviewOption = { id: number; reconciliation_batch_id: number; source_filename: string | null; candidate_count: number; created_at?: string }
type Workspace = {
  previews: PreviewOption[]
  preview: (PreviewOption & { unresolved_source_rows: number }) | null
  summary: { total_candidates: number; resolved_candidates: number; unresolved_candidates: number; multi_owner_candidates: number; decision_required: number; source_owner_count: number }
  source_owners: SourceOwner[]
  multi_candidates: Candidate[]
  eligible_users: UserOption[]
}

const props = defineProps<{ workspace: Workspace }>()
const state = ref<Workspace>(props.workspace)
const busyKey = ref('')
const error = ref('')
const search = ref('')
const resolutionFilter = ref('pending')
const mappingSelections = ref<Record<string, string>>({})
const decisionSelections = ref<Record<string, string>>({})
const decisionReasons = ref<Record<string, string>>({})

const hydrate = () => {
  const mappings: Record<string, string> = {}
  for (const owner of state.value.source_owners) mappings[owner.key] = owner.mapping?.mapped_user_id ? String(owner.mapping.mapped_user_id) : ''
  mappingSelections.value = mappings
  const decisions: Record<string, string> = {}
  const reasons: Record<string, string> = {}
  for (const candidate of state.value.multi_candidates) {
    decisions[candidate.cluster_key] = candidate.decision?.mapped_user_id ? String(candidate.decision.mapped_user_id) : ''
    reasons[candidate.cluster_key] = candidate.decision?.reason ?? ''
  }
  decisionSelections.value = decisions
  decisionReasons.value = reasons
}
hydrate()

const applyWorkspace = (workspace: Workspace) => {
  state.value = workspace
  hydrate()
}

const formatDate = (value?: string) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(date).replace(/\//g, '-')
}

const changePreview = (event: Event) => {
  const id = Number((event.target as HTMLSelectElement).value)
  if (id) router.get('/kol/owner-mapping', { preview_batch_id: id })
}

const saveSourceMapping = async (owner: SourceOwner) => {
  const userId = Number(mappingSelections.value[owner.key])
  if (!userId || !state.value.preview) return
  busyKey.value = `source:${owner.key}`
  error.value = ''
  try {
    const response = await axios.post('/kol/owner-mapping/source', {
      preview_batch_id: state.value.preview.id,
      source_owner_value: owner.value,
      mapped_user_id: userId,
    })
    applyWorkspace(response.data.workspace)
  } catch (e: any) {
    error.value = e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {})?.flat()?.[0] ?? '保存 Owner 映射失败'
  } finally { busyKey.value = '' }
}

const resetSourceMapping = async (owner: SourceOwner) => {
  if (!owner.mapping || !state.value.preview) return
  busyKey.value = `source:${owner.key}`
  error.value = ''
  try {
    const response = await axios.delete(`/kol/owner-mapping/source/${owner.mapping.id}`, { data: { preview_batch_id: state.value.preview.id } })
    applyWorkspace(response.data.workspace)
  } catch (e: any) {
    error.value = e.response?.data?.message ?? '重置 Owner 映射失败'
  } finally { busyKey.value = '' }
}

const saveDecision = async (candidate: Candidate) => {
  const userId = Number(decisionSelections.value[candidate.cluster_key])
  if (!userId || !state.value.preview) return
  busyKey.value = `decision:${candidate.cluster_key}`
  error.value = ''
  try {
    const response = await axios.post('/kol/owner-mapping/decision', {
      preview_batch_id: state.value.preview.id,
      cluster_key: candidate.cluster_key,
      mapped_user_id: userId,
      reason: decisionReasons.value[candidate.cluster_key] || null,
    })
    applyWorkspace(response.data.workspace)
  } catch (e: any) {
    error.value = e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {})?.flat()?.[0] ?? '保存候选 Owner 裁决失败'
  } finally { busyKey.value = '' }
}

const resetDecision = async (candidate: Candidate) => {
  if (!candidate.decision || !state.value.preview) return
  busyKey.value = `decision:${candidate.cluster_key}`
  error.value = ''
  try {
    const response = await axios.delete(`/kol/owner-mapping/decision/${candidate.decision.id}`, { data: { preview_batch_id: state.value.preview.id } })
    applyWorkspace(response.data.workspace)
  } catch (e: any) {
    error.value = e.response?.data?.message ?? '重置候选 Owner 裁决失败'
  } finally { busyKey.value = '' }
}

const statusLabel = (status: string) => ({
  mapped: '已映射',
  mapped_converged: '映射已收敛',
  decided: '已人工裁决',
  unmapped: '未映射',
  needs_decision: '需人工裁决',
  no_source_owner: '无来源 Owner',
}[status] ?? status)
const statusTone = (status: string) => ['mapped', 'mapped_converged', 'decided'].includes(status) ? 'green' : status === 'needs_decision' ? 'amber' : 'gray'
const socialText = (candidate: Candidate) => {
  const social = candidate.social_accounts?.[0]
  if (!social) return '—'
  const platform = ({ instagram: 'Instagram', twitter: 'Twitter / X', tiktok: 'TikTok', youtube: 'YouTube', facebook: 'Facebook', other: '平台待确认' } as Record<string, string>)[String(social.platform ?? '').toLowerCase()] ?? String(social.platform ?? '平台待确认')
  const handle = social.handle ? `@${String(social.handle).replace(/^@/, '')}` : String(social.profile_url ?? '')
  return `${platform} · ${handle}`
}
const mappingChain = (candidate: Candidate) => candidate.source_mappings.map((item) => `${item.source_owner} → ${item.user?.name ?? '未映射'}`)
const filteredCandidates = computed(() => {
  const needle = search.value.trim().toLowerCase()
  return state.value.multi_candidates.filter((candidate) => {
    if (resolutionFilter.value === 'pending' && candidate.resolved) return false
    if (resolutionFilter.value === 'resolved' && !candidate.resolved) return false
    const haystack = [candidate.display_name, candidate.primary_email ?? '', ...candidate.source_owner_values, ...mappingChain(candidate)].join(' ').toLowerCase()
    return !needle || haystack.includes(needle)
  })
})
const completionRate = computed(() => state.value.summary.total_candidates ? Math.round(state.value.summary.resolved_candidates / state.value.summary.total_candidates * 1000) / 10 : 0)
</script>

<template>
  <Head title="Owner 映射 · BYL ERP" />
  <AuthenticatedLayout title="Owner 映射" :breadcrumbs="['BYL ERP', '网红', '真实数据核对', 'KOL 导入预览', 'Owner 映射']">
    <section class="owner-page">
      <div class="owner-header-card">
        <div class="owner-header-main">
          <div>
            <p class="eyebrow">P3A · Owner Mapping Closure</p>
            <h1>历史 KOL Owner 映射</h1>
            <p class="subtitle">把 P2 候选中的来源负责人显式映射到 ERP 用户，为后续 KOL Master 导入准备责任人事实。</p>
          </div>
          <div class="page-actions">
            <a class="erp-btn erp-btn-secondary" href="/kol/import-preview">← 返回 P2 导入预览</a>
            <a v-if="state.preview" class="erp-btn erp-btn-primary" :href="`/kol/import-activation?preview_batch_id=${state.preview.id}`">下一步：正式导入 →</a>
          </div>
        </div>
        <div class="safety-banner"><strong>边界：</strong>仅保存 Owner 映射与候选裁决；<strong>不创建 KOL、不绑定 Affiliate、不写 Provider。</strong></div>
        <div v-if="state.preview" class="preview-strip">
          <div>
            <strong>{{ state.preview.source_filename }}</strong>
            <span>P2 Preview #{{ state.preview.id }} · P1 Batch #{{ state.preview.reconciliation_batch_id }} · {{ formatDate(state.preview.created_at) }}</span>
          </div>
          <select v-if="state.previews.length > 1" :value="state.preview.id" class="preview-select" aria-label="选择 P2 预览" @change="changePreview">
            <option v-for="preview in state.previews" :key="preview.id" :value="preview.id">Preview #{{ preview.id }} · {{ preview.source_filename }}</option>
          </select>
        </div>
      </div>

      <div v-if="error" class="error-banner">{{ error }}</div>

      <div class="kpi-grid">
        <div class="kpi-card"><span>候选总数</span><strong>{{ state.summary.total_candidates }}</strong><small>P2 身份候选</small></div>
        <div class="kpi-card kpi-green"><span>Owner 已解析</span><strong>{{ state.summary.resolved_candidates }}</strong><small>{{ completionRate }}% 映射完成</small></div>
        <div class="kpi-card kpi-amber"><span>Owner 待处理</span><strong>{{ state.summary.unresolved_candidates }}</strong><small>未映射或待裁决</small></div>
        <div class="kpi-card"><span>多 Owner 候选</span><strong>{{ state.summary.multi_owner_candidates }}</strong><small>当前 {{ state.summary.decision_required }} 条仍需裁决</small></div>
      </div>

      <section class="workspace-card">
        <div class="section-head">
          <div><p class="eyebrow">STEP 1</p><h2>来源 Owner 全局映射</h2><p>7 个来源负责人值只需映射一次；所有单一 Owner 候选会立即重新计算准备度。</p></div>
          <span class="count-pill">{{ state.summary.source_owner_count }} 个来源 Owner</span>
        </div>
        <div class="table-wrap">
          <table class="owner-table">
            <thead><tr><th>来源 Owner</th><th>单一 Owner 候选</th><th>总涉及候选</th><th>ERP Owner</th><th>状态</th><th class="action-col">操作</th></tr></thead>
            <tbody>
              <tr v-for="owner in state.source_owners" :key="owner.key">
                <td><strong>{{ owner.value }}</strong><small>来源：Belongs to</small></td>
                <td><strong>{{ owner.single_candidate_count }}</strong><small>可由此映射直接解析</small></td>
                <td>{{ owner.mention_candidate_count }}</td>
                <td>
                  <select v-model="mappingSelections[owner.key]" class="owner-select" :aria-label="`${owner.value} ERP Owner`">
                    <option value="">请选择 ERP 用户</option>
                    <option v-for="user in state.eligible_users" :key="user.id" :value="String(user.id)">{{ user.name }} · {{ user.email }}</option>
                  </select>
                </td>
                <td><span class="status-badge" :class="owner.mapping?.status === 'mapped' ? 'tone-green' : 'tone-gray'">{{ owner.mapping?.status === 'mapped' ? '已映射' : owner.mapping ? '映射用户已失效' : '未映射' }}</span></td>
                <td class="action-cell">
                  <button class="mini-btn primary" type="button" :disabled="!mappingSelections[owner.key] || busyKey === `source:${owner.key}`" @click="saveSourceMapping(owner)">{{ busyKey === `source:${owner.key}` ? '保存中…' : '保存' }}</button>
                  <button v-if="owner.mapping" class="mini-btn" type="button" :disabled="busyKey === `source:${owner.key}`" @click="resetSourceMapping(owner)">重置</button>
                </td>
              </tr>
              <tr v-if="!state.source_owners.length"><td colspan="6" class="empty-cell">当前 P2 预览没有可映射的来源 Owner。</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="workspace-card">
        <div class="section-head conflict-head">
          <div><p class="eyebrow">STEP 2</p><h2>多 Owner 候选裁决</h2><p>同一身份候选历史上出现多个负责人时，只有映射收敛到同一 ERP 用户或 Manager/Admin 明确裁决后才算完成。</p></div>
          <span class="count-pill amber">{{ state.summary.multi_owner_candidates }} 个候选</span>
        </div>
        <div class="toolbar">
          <input v-model="search" class="search-input" type="search" placeholder="搜索 KOL / Email / 来源 Owner" />
          <select v-model="resolutionFilter" class="filter-select">
            <option value="pending">只看待裁决</option>
            <option value="resolved">只看已解析</option>
            <option value="all">全部</option>
          </select>
          <span class="toolbar-count">显示 {{ filteredCandidates.length }} / {{ state.summary.multi_owner_candidates }}</span>
        </div>
        <div class="table-wrap">
          <table class="candidate-table">
            <thead><tr><th>KOL 候选</th><th>来源 Owner</th><th>映射结果</th><th>当前状态</th><th>最终 ERP Owner</th><th>裁决备注</th><th class="action-col">操作</th></tr></thead>
            <tbody>
              <tr v-for="candidate in filteredCandidates" :key="candidate.cluster_key">
                <td class="candidate-cell"><div class="avatar">{{ candidate.display_name.slice(0, 1).toUpperCase() }}</div><div><strong>{{ candidate.display_name }}</strong><span>{{ candidate.primary_email ?? '无 Email' }}</span><small>{{ socialText(candidate) }} · {{ candidate.source_row_count }} 条来源记录</small></div></td>
                <td><div class="chip-row"><span v-for="owner in candidate.source_owner_values" :key="owner" class="owner-chip">{{ owner }}</span></div></td>
                <td><div class="mapping-chain"><span v-for="line in mappingChain(candidate)" :key="line">{{ line }}</span></div></td>
                <td><span class="status-badge" :class="`tone-${statusTone(candidate.resolution_status)}`">{{ statusLabel(candidate.resolution_status) }}</span><small v-if="candidate.effective_owner">→ {{ candidate.effective_owner.name }}</small></td>
                <td><select v-model="decisionSelections[candidate.cluster_key]" class="owner-select"><option value="">选择最终 Owner</option><option v-for="user in state.eligible_users" :key="user.id" :value="String(user.id)">{{ user.name }}</option></select></td>
                <td><input v-model="decisionReasons[candidate.cluster_key]" class="reason-input" maxlength="500" placeholder="可选：历史交接 / 当前负责人" /></td>
                <td class="action-cell"><button class="mini-btn primary" type="button" :disabled="!decisionSelections[candidate.cluster_key] || busyKey === `decision:${candidate.cluster_key}`" @click="saveDecision(candidate)">{{ busyKey === `decision:${candidate.cluster_key}` ? '保存中…' : '保存裁决' }}</button><button v-if="candidate.decision" class="mini-btn" type="button" :disabled="busyKey === `decision:${candidate.cluster_key}`" @click="resetDecision(candidate)">清除</button></td>
              </tr>
              <tr v-if="!filteredCandidates.length"><td colspan="7" class="empty-cell">当前筛选条件下没有多 Owner 候选。</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="closure-note"><strong>P3A 完成条件</strong><span>Owner 待处理 = 0 后，只代表责任人映射准备完成。下一阶段 P4 才能讨论 canonical KOL Master 导入；本页永远不执行导入。</span></section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.owner-page{padding:18px 20px 32px;background:#f5f7f7;min-height:calc(100vh - 80px);color:#20313a}.owner-header-card,.workspace-card,.closure-note{background:#fff;border:1px solid #dce5e7;border-radius:14px;box-shadow:0 4px 14px rgba(27,52,61,.04)}.owner-header-card{padding:22px 24px;margin-bottom:14px}.owner-header-main{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.eyebrow{margin:0 0 6px;font-size:12px;font-weight:800;letter-spacing:.06em;color:#34877f;text-transform:uppercase}.owner-header-main h1,.section-head h2{margin:0;color:#172830}.owner-header-main h1{font-size:24px}.subtitle,.section-head p{margin:7px 0 0;color:#71828b;font-size:13px}.page-actions{display:flex;gap:8px}.safety-banner{margin-top:18px;padding:11px 14px;border-radius:9px;background:#edf8f5;border:1px solid #bee3d8;color:#286b63;font-size:13px}.preview-strip{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-top:14px;padding-top:14px;border-top:1px solid #edf1f2}.preview-strip>div{display:flex;flex-direction:column;gap:3px}.preview-strip strong{font-size:14px}.preview-strip span{font-size:12px;color:#83939b}.preview-select,.owner-select,.filter-select,.search-input,.reason-input{border:1px solid #ccd9dd;border-radius:8px;background:#fff;color:#30444d;font-size:13px}.preview-select,.owner-select,.filter-select{height:36px;padding:0 10px}.error-banner{margin:0 0 14px;padding:10px 13px;border-radius:9px;background:#fff0f1;border:1px solid #f2c5c9;color:#ad3540;font-size:13px}.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.kpi-card{background:#fff;border:1px solid #dce5e7;border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:4px}.kpi-card span{font-size:12px;color:#70818a;font-weight:700}.kpi-card strong{font-size:30px;line-height:1.1}.kpi-card small{font-size:11px;color:#96a4aa}.kpi-green strong{color:#26845e}.kpi-amber strong{color:#d48615}.workspace-card{margin-bottom:14px;overflow:hidden}.section-head{padding:18px 20px;display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:1px solid #edf1f2}.section-head h2{font-size:18px}.count-pill{background:#eaf7f3;color:#267568;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:800;white-space:nowrap}.count-pill.amber{background:#fff3dd;color:#ad6e0d}.table-wrap{overflow:auto}.owner-table,.candidate-table{width:100%;border-collapse:collapse;min-width:920px}.candidate-table{min-width:1280px}.owner-table th,.candidate-table th{padding:10px 12px;text-align:left;background:#f7f9f9;border-bottom:1px solid #dfe7e9;color:#566a73;font-size:11px;font-weight:800;white-space:nowrap}.owner-table td,.candidate-table td{padding:12px;border-bottom:1px solid #edf1f2;vertical-align:middle;font-size:12px}.owner-table td>small,.candidate-table td>small{display:block;margin-top:3px;color:#98a5aa;font-size:10px}.owner-table td>strong{display:block}.action-col{width:120px}.action-cell{display:flex;gap:6px;align-items:center;white-space:nowrap}.mini-btn{height:30px;padding:0 10px;border-radius:7px;border:1px solid #cad7da;background:#fff;color:#52666f;font-size:11px;cursor:pointer}.mini-btn.primary{background:#34877f;border-color:#34877f;color:#fff}.mini-btn:disabled{opacity:.45;cursor:not-allowed}.owner-select{min-width:220px;max-width:290px}.status-badge{display:inline-flex;align-items:center;min-height:24px;padding:0 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap}.tone-green{background:#e8f7ee;color:#247751}.tone-amber{background:#fff2d8;color:#a86709}.tone-gray{background:#eef2f3;color:#61737b}.toolbar{display:flex;gap:8px;align-items:center;padding:12px 20px;border-bottom:1px solid #edf1f2}.search-input{height:36px;padding:0 12px;min-width:280px}.filter-select{min-width:140px}.toolbar-count{margin-left:auto;font-size:11px;color:#819098}.candidate-cell{display:flex;gap:9px;min-width:260px}.candidate-cell>div:last-child{display:flex;flex-direction:column;gap:2px}.candidate-cell strong{font-size:12px}.candidate-cell span{color:#71838c;font-size:11px}.candidate-cell small{color:#97a4aa;font-size:10px}.avatar{width:32px;height:32px;border-radius:50%;display:grid;place-items:center;background:#eef3ff;color:#4772c8;font-weight:800;flex:none}.chip-row{display:flex;gap:5px;flex-wrap:wrap}.owner-chip{padding:3px 7px;border-radius:999px;background:#f1f4f5;color:#50636b;font-size:10px;font-weight:700}.mapping-chain{display:flex;flex-direction:column;gap:3px;min-width:150px}.mapping-chain span{font-size:10px;color:#60737b}.reason-input{height:36px;padding:0 9px;min-width:190px}.empty-cell{text-align:center!important;padding:28px!important;color:#89989f!important}.closure-note{padding:14px 18px;display:flex;gap:12px;align-items:flex-start;border-color:#cde3de;background:#f7fcfa}.closure-note strong{color:#267568;white-space:nowrap}.closure-note span{font-size:12px;color:#667982}.erp-btn{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 13px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:700}.erp-btn-secondary{background:#fff;border:1px solid #cbd8dc;color:#4b626b}.erp-btn-primary{background:#34877f;border:1px solid #34877f;color:#fff}@media(max-width:900px){.owner-header-main,.preview-strip,.section-head{flex-direction:column}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.toolbar{flex-wrap:wrap}.toolbar-count{margin-left:0}}@media(max-width:560px){.owner-page{padding:12px}.kpi-grid{grid-template-columns:1fr}.owner-header-card{padding:17px}.search-input{min-width:100%;width:100%}}
</style>
