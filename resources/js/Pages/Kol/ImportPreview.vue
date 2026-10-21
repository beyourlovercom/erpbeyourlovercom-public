<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Candidate = {
  id: number
  source_row_count: number
  identity: { emails?: string[]; profile_urls?: string[]; issues?: string[]; variations?: Record<string, unknown> }
  proposed_kol: Record<string, any>
  social_accounts: Array<Record<string, any>>
  owner_evidence: Record<string, any>
  owner_resolution_status: string
  matched_owner_id: number | null
  history_summary: Record<string, any>
  affiliate_account_ids: number[]
  existing_kol: { id: number; display_name: string } | null
  status: string
  message: string
}

type Preview = {
  id: number
  reconciliation_batch_id: number
  source_filename: string
  total_source_rows: number
  candidate_count: number
  unresolved_source_rows: number
  summary: Record<string, number>
  created_at?: string
  candidates: Candidate[]
}

type SourceBatch = {
  id: number
  original_filename: string
  checksum: string
  total_rows: number
  distinct_source_identities: number
  created_at?: string
  import_preview: Preview | null
}

const props = defineProps<{ batches: SourceBatch[]; canManageOwnerMapping?: boolean }>()
const selected = ref<number | null>(props.batches[0]?.id ?? null)
const preview = ref<Preview | null>(props.batches.find((batch) => batch.id === selected.value)?.import_preview ?? null)
const busy = ref(false)
const error = ref('')
const search = ref('')
const statusFilter = ref('')
const matchFilter = ref('')
const reasonFilter = ref('')
const page = ref(1)
const pageSize = ref(20)
const expandedCandidateId = ref<number | null>(null)

const statuses = [
  { key: 'ready_new_kol', label: '可新建', helper: '可进入后续创建审核', icon: '+', tone: 'blue' },
  { key: 'needs_review', label: '需复核', helper: '需要人工进一步确认身份', icon: '!', tone: 'amber' },
  { key: 'existing_kol', label: '已有 KOL', helper: '已存在于 ERP KOL Master', icon: '✓', tone: 'green' },
  { key: 'conflict', label: '冲突', helper: '存在多个身份匹配冲突', icon: '!', tone: 'red' },
]

const statusLabel = (status: string) => ({
  ready_new_kol: '可新建',
  needs_review: '需复核',
  existing_kol: '已有 KOL',
  conflict: '冲突',
}[status] ?? status)

const source = computed(() => props.batches.find((batch) => batch.id === selected.value) ?? null)
const candidateName = (candidate: Candidate) => String(candidate.proposed_kol.display_name ?? candidate.identity.emails?.[0]?.split('@')[0] ?? `候选 #${candidate.id}`)
const emails = (candidate: Candidate) => candidate.identity.emails ?? []
const primaryEmail = (candidate: Candidate) => emails(candidate)[0] ?? '—'
const socialList = (candidate: Candidate) => candidate.social_accounts ?? []
const country = (candidate: Candidate) => String(candidate.proposed_kol.country_code ?? '—')
const city = (candidate: Candidate) => String(candidate.proposed_kol.shipping_city ?? '')
const locationLabel = (candidate: Candidate) => [country(candidate), city(candidate)].filter((value) => value && value !== '—').join(' · ') || '—'
const initials = (candidate: Candidate) => candidateName(candidate).slice(0, 1).toUpperCase()
const checksumShort = computed(() => source.value?.checksum ? `${source.value.checksum.slice(0, 12)}…` : '—')

const platformLabel = (platform: string) => ({
  instagram: 'Instagram',
  twitter: 'Twitter / X',
  tiktok: 'TikTok',
  youtube: 'YouTube',
  facebook: 'Facebook',
  other: '平台待确认',
}[String(platform).toLowerCase()] ?? platform)

const primarySocial = (candidate: Candidate) => socialList(candidate)[0] ?? null
const socialText = (candidate: Candidate) => {
  const social = primarySocial(candidate)
  if (!social) return '—'
  const handle = social.handle ? `@${String(social.handle).replace(/^@/, '')}` : '无 Handle'
  return `${platformLabel(String(social.platform ?? 'other'))} · ${handle}`
}

const issueLabel = (issue: string) => ({
  owner_unresolved: '负责人未映射',
  owner_multiple: '负责人来源不一致',
  owner_conflict: '负责人匹配冲突',
  multiple_emails: '存在多个 Email',
  field_variation: '历史字段存在变化',
  too_many_social_accounts: '社媒账号数量异常',
  multiple_affiliate_accounts: '存在多个 Affiliate',
  missing_display_name: '缺少可用名称',
}[issue] ?? issue)

const candidateReasons = (candidate: Candidate) => {
  if (candidate.status === 'existing_kol') return ['ERP 已存在精确身份匹配']
  if (candidate.status === 'conflict') return ['ERP 身份匹配发生冲突']
  const issues = candidate.identity.issues ?? []
  if (issues.length) return issues.map(issueLabel)
  if (candidate.status === 'ready_new_kol') return ['精确身份完整，可进入后续审核']
  return ['需要人工确认']
}

const ownerSourceValues = (candidate: Candidate) => candidate.owner_evidence?.source_values ?? []
const ownerResolutionLabel = (status: string) => ({
  matched: '已匹配 ERP 用户',
  unresolved: '未解析',
  multiple: '多个候选',
  conflict: '匹配冲突',
}[status] ?? '未解析')
const affiliateAccountText = (candidate: Candidate) => candidate.affiliate_account_ids.length
  ? candidate.affiliate_account_ids.map((id) => `Affiliate Account #${id}`).join(' · ')
  : '—'
const systemExplanation = (candidate: Candidate) => {
  const issues = candidate.identity.issues ?? []
  const owners = ownerSourceValues(candidate)
  if (issues.includes('owner_unresolved')) {
    return owners.length
      ? `负责人 “${owners.join(' / ')}” 尚未匹配到 ERP 用户，需要人工确认。`
      : '负责人尚未匹配到 ERP 用户，需要人工确认。'
  }
  if (issues.includes('owner_multiple')) return `来源记录中存在多个负责人值（${owners.join(' / ') || '未提供'}），需要人工确认。`
  if (issues.includes('owner_conflict')) return '负责人映射出现冲突，需要人工确认。'
  return candidateReasons(candidate).join('；')
}

const reasonCategory = (candidate: Candidate) => {
  const issues = candidate.identity.issues ?? []
  if (issues.some((issue) => issue.startsWith('owner_'))) return 'owner'
  if (issues.some((issue) => ['multiple_emails', 'missing_display_name', 'too_many_social_accounts'].includes(issue))) return 'identity'
  if (issues.includes('multiple_affiliate_accounts')) return 'affiliate'
  if (issues.includes('field_variation')) return 'data'
  if (candidate.status === 'conflict') return 'conflict'
  if (candidate.status === 'existing_kol') return 'existing'
  return 'other'
}

const matchLabel = (candidate: Candidate) => {
  if (candidate.status === 'conflict') return '匹配冲突'
  if (candidate.existing_kol) return '已匹配'
  return '未匹配'
}

const filteredCandidates = computed(() => {
  const needle = search.value.trim().toLowerCase()
  return (preview.value?.candidates ?? []).filter((candidate) => {
    const searchValues = [
      candidateName(candidate),
      ...emails(candidate),
      ...socialList(candidate).flatMap((social) => [String(social.platform ?? ''), String(social.handle ?? ''), String(social.profile_url ?? '')]),
      locationLabel(candidate),
    ].join(' ').toLowerCase()
    if (needle && !searchValues.includes(needle)) return false
    if (statusFilter.value && candidate.status !== statusFilter.value) return false
    if (matchFilter.value === 'matched' && !candidate.existing_kol) return false
    if (matchFilter.value === 'unmatched' && (candidate.existing_kol || candidate.status === 'conflict')) return false
    if (matchFilter.value === 'conflict' && candidate.status !== 'conflict') return false
    if (reasonFilter.value && reasonCategory(candidate) !== reasonFilter.value) return false
    return true
  })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredCandidates.value.length / pageSize.value)))
const paginatedCandidates = computed(() => {
  const start = (page.value - 1) * pageSize.value
  return filteredCandidates.value.slice(start, start + pageSize.value)
})
const pageNumbers = computed(() => {
  const total = totalPages.value
  const current = page.value
  if (total <= 6) return Array.from({ length: total }, (_, index) => index + 1)
  const values = new Set([1, total, current, current - 1, current + 1].filter((value) => value >= 1 && value <= total))
  return [...values].sort((a, b) => a - b)
})

watch([search, statusFilter, matchFilter, reasonFilter, pageSize], () => { page.value = 1 })
watch(totalPages, (value) => { if (page.value > value) page.value = value })

const formatDate = (value?: string) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  }).format(date).replace(/\//g, '-')
}

const generate = async () => {
  if (!selected.value) return
  busy.value = true
  error.value = ''
  try {
    preview.value = (await axios.post('/kol/import-preview/generate', { reconciliation_batch_id: selected.value })).data.batch
    page.value = 1
    expandedCandidateId.value = null
  } catch (e: any) {
    error.value = e.response?.data?.message ?? '生成预览失败'
  } finally {
    busy.value = false
  }
}

const changeBatch = () => {
  preview.value = source.value?.import_preview ?? null
  page.value = 1
  expandedCandidateId.value = null
}

const toggleDetails = (candidate: Candidate) => {
  expandedCandidateId.value = expandedCandidateId.value === candidate.id ? null : candidate.id
}

const csvEscape = (value: unknown) => `"${String(value ?? '').replaceAll('"', '""')}"`
const exportPreview = () => {
  if (!preview.value) return
  const header = ['候选ID', 'KOL候选', 'Email', '社媒', '来源记录', 'ERP匹配', '判断原因', '状态']
  const rows = filteredCandidates.value.map((candidate) => [
    candidate.id,
    candidateName(candidate),
    emails(candidate).join(' | '),
    socialList(candidate).map((social) => `${platformLabel(String(social.platform ?? 'other'))}:${social.handle ?? social.profile_url ?? ''}`).join(' | '),
    candidate.source_row_count,
    candidate.existing_kol ? `${candidate.existing_kol.display_name} (#${candidate.existing_kol.id})` : matchLabel(candidate),
    candidateReasons(candidate).join(' | '),
    statusLabel(candidate.status),
  ])
  const csv = [header, ...rows].map((row) => row.map(csvEscape).join(',')).join('\n')
  const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `kol-import-preview-${preview.value.id}.csv`
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <Head title="KOL 导入预览 · BYL ERP" />
  <AuthenticatedLayout title="KOL 导入预览" :breadcrumbs="['BYL ERP', '网红', '真实数据核对', 'KOL 导入预览']">
    <section class="erp-form-page kol-import-page">
      <div class="erp-form-content kol-import-workspace">
        <section class="source-card">
          <div class="source-card-head">
            <div>
              <p class="section-eyebrow">数据来源（P1 身份对齐结果）</p>
              <div class="source-file-row">
                <div class="csv-icon">CSV</div>
                <div class="source-file-main">
                  <div class="source-file-title-row">
                    <h1>{{ source?.original_filename ?? '暂无可用批次' }}</h1>
                    <select v-if="props.batches.length > 1" v-model="selected" class="source-batch-select" aria-label="选择 P1 批次" @change="changeBatch">
                      <option v-for="batch in props.batches" :key="batch.id" :value="batch.id">P1 Batch #{{ batch.id }}</option>
                    </select>
                  </div>
                  <div class="source-meta">
                    <span>导入时间：{{ formatDate(source?.created_at) }}</span>
                    <span>批次 ID：P1 Batch #{{ source?.id ?? '—' }}</span>
                  </div>
                </div>
              </div>
            </div>
            <div class="source-actions">
              <a class="erp-btn erp-btn-secondary" href="/kol/affiliatewp/reconciliation">← 返回真实数据核对</a>
              <a v-if="preview && props.canManageOwnerMapping" class="erp-btn erp-btn-secondary" :href="`/kol/owner-mapping?preview_batch_id=${preview.id}`">下一步：Owner 映射 →</a>
              <button class="erp-btn erp-btn-primary" type="button" :disabled="busy || !selected" @click="generate">
                {{ busy ? '生成中…' : '↻ 生成 / 重算预览' }}
              </button>
            </div>
          </div>

          <div class="source-lower">
            <div class="source-kpis">
              <div class="source-kpi">
                <strong>{{ source?.total_rows ?? 0 }} 条历史记录</strong>
                <span>原始来源行数</span>
              </div>
              <div class="source-kpi">
                <strong>{{ source?.distinct_source_identities ?? 0 }} 个不同身份</strong>
                <span>P1 精确身份数量</span>
              </div>
              <div class="source-kpi source-kpi-checksum" :title="source?.checksum">
                <strong>{{ checksumShort }}</strong>
                <span>SHA-256 校验值</span>
              </div>
            </div>
            <aside class="source-explainer">
              <strong>说明</strong>
              <p>P1 已基于 Email / 社媒 URL 进行精确身份对齐；本页面对 P1 身份再次聚合，生成最终 KOL 身份候选。</p>
            </aside>
          </div>
          <p v-if="error" class="erp-error source-error">{{ error }}</p>
        </section>

        <section v-if="preview" class="preview-card">
          <div class="preview-head">
            <div>
              <div class="preview-title-row">
                <h2>本次导入预览（P2 身份候选结果）</h2>
                <span class="info-dot" title="这里只生成可审阅候选，不创建 KOL、不绑定 Affiliate。">?</span>
              </div>
              <p><strong>{{ preview.total_source_rows }}</strong> 条历史记录；P1 识别出 <strong>{{ source?.distinct_source_identities ?? 0 }}</strong> 个精确身份 → P2 聚合为 <strong>{{ preview.candidate_count }}</strong> 个 KOL 身份候选；另有 <strong>{{ preview.unresolved_source_rows }}</strong> 条来源记录因缺少有效身份依据未参与聚合。</p>
            </div>
            <div class="preview-meta">
              <span>Preview ID：#{{ preview.id }}</span>
              <span>生成时间：{{ formatDate(preview.created_at) }}</span>
            </div>
          </div>

          <div class="status-grid">
            <article v-for="status in statuses" :key="status.key" class="status-card" :class="`tone-${status.tone}`">
              <div>
                <strong>{{ preview.summary[status.key] ?? 0 }}</strong>
                <span>{{ status.label }}</span>
                <small>{{ status.helper }}</small>
              </div>
              <div class="status-icon">{{ status.icon }}</div>
            </article>
          </div>

          <div v-if="preview.unresolved_source_rows" class="identity-warning">
            <div>
              <strong>⚠ 无有效身份依据：</strong>
              {{ preview.unresolved_source_rows }} 条来源记录缺少 Email / 社媒 URL 等有效身份信息，未进入自动身份判断。
            </div>
            <span>已安全跳过</span>
          </div>

          <div class="candidate-toolbar">
            <label class="search-field">
              <span class="search-icon">⌕</span>
              <input v-model="search" type="search" placeholder="搜索 KOL 候选（Email / 用户名 / 社媒）" aria-label="搜索 KOL 候选">
            </label>
            <label class="filter-field"><span>状态：</span><select v-model="statusFilter"><option value="">全部</option><option v-for="status in statuses" :key="status.key" :value="status.key">{{ status.label }}</option></select></label>
            <label class="filter-field"><span>匹配结果：</span><select v-model="matchFilter"><option value="">全部</option><option value="unmatched">未匹配</option><option value="matched">已匹配</option><option value="conflict">冲突</option></select></label>
            <label class="filter-field"><span>判断原因：</span><select v-model="reasonFilter"><option value="">全部</option><option value="owner">负责人映射</option><option value="identity">身份信息</option><option value="data">历史字段变化</option><option value="affiliate">Affiliate</option><option value="existing">已有 KOL</option><option value="conflict">冲突</option></select></label>
            <button class="erp-btn erp-btn-secondary export-button" type="button" @click="exportPreview">↓ 导出当前预览</button>
          </div>

          <div class="candidate-table-wrap">
            <table class="candidate-table">
              <thead>
                <tr>
                  <th>KOL 候选</th>
                  <th>精确身份（主要依据）</th>
                  <th>社媒账号</th>
                  <th>来源记录</th>
                  <th>ERP 匹配结果</th>
                  <th>判断原因</th>
                  <th>状态</th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="candidate in paginatedCandidates" :key="candidate.id">
                  <tr>
                    <td>
                      <div class="candidate-cell">
                        <span class="candidate-avatar">{{ initials(candidate) }}</span>
                        <div>
                          <strong>{{ candidateName(candidate) }}</strong>
                          <small>{{ locationLabel(candidate) }}</small>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="identity-cell">
                        <span>{{ primaryEmail(candidate) }}</span>
                        <span v-if="primaryEmail(candidate) !== '—'" class="exact-badge">Email 精确依据</span>
                        <small v-if="emails(candidate).length > 1">+{{ emails(candidate).length - 1 }} 个 Email</small>
                      </div>
                    </td>
                    <td>
                      <div class="social-cell">
                        <strong>{{ primarySocial(candidate) ? platformLabel(String(primarySocial(candidate)?.platform ?? 'other')) : '—' }}</strong>
                        <span v-if="primarySocial(candidate)?.handle">@{{ String(primarySocial(candidate)?.handle).replace(/^@/, '') }}</span>
                        <span v-else-if="primarySocial(candidate)?.profile_url">有 URL · 无 Handle</span>
                        <small v-if="socialList(candidate).length > 1">+{{ socialList(candidate).length - 1 }} 个账号</small>
                      </div>
                    </td>
                    <td>
                      <strong class="source-count">{{ candidate.source_row_count }} 条记录</strong>
                      <small class="muted-line">{{ candidate.history_summary.source_order_count ?? 0 }} 单 · {{ candidate.history_summary.sku_count ?? 0 }} SKU</small>
                    </td>
                    <td>
                      <strong :class="['match-result', candidate.existing_kol ? 'matched' : candidate.status === 'conflict' ? 'conflict' : 'unmatched']">{{ matchLabel(candidate) }}</strong>
                      <small class="muted-line">{{ candidate.existing_kol ? `${candidate.existing_kol.display_name} (#${candidate.existing_kol.id})` : 'ERP 中无精确身份匹配' }}</small>
                    </td>
                    <td>
                      <div class="reason-cell">
                        <span v-for="reason in candidateReasons(candidate).slice(0, 2)" :key="reason">• {{ reason }}</span>
                        <small v-if="candidateReasons(candidate).length > 2">另有 {{ candidateReasons(candidate).length - 2 }} 项</small>
                      </div>
                    </td>
                    <td><span class="status-badge" :class="`status-${candidate.status}`">{{ statusLabel(candidate.status) }}</span></td>
                    <td><button class="details-button" type="button" @click="toggleDetails(candidate)">{{ expandedCandidateId === candidate.id ? '收起' : '查看详情' }}</button></td>
                  </tr>
                  <tr v-if="expandedCandidateId === candidate.id" class="details-row">
                    <td colspan="8">
                      <div class="details-grid">
                        <div><span>全部 Email</span><strong>{{ emails(candidate).join(' · ') || '—' }}</strong></div>
                        <div><span>社媒</span><strong>{{ socialList(candidate).map((social) => `${platformLabel(String(social.platform ?? 'other'))} ${social.handle ? `@${social.handle}` : ''}`).join(' · ') || '—' }}</strong></div>
                        <div><span>Owner 原始值</span><strong>{{ ownerSourceValues(candidate).join(' · ') || '—' }}</strong></div>
                        <div><span>ERP Owner 映射</span><strong>{{ ownerResolutionLabel(candidate.owner_resolution_status) }}</strong></div>
                        <div><span>Affiliate Account ID</span><strong>{{ affiliateAccountText(candidate) }}</strong></div>
                        <div class="details-wide"><span>系统说明</span><strong>{{ systemExplanation(candidate) }}</strong></div>
                      </div>
                    </td>
                  </tr>
                </template>
                <tr v-if="paginatedCandidates.length === 0">
                  <td colspan="8" class="empty-state">当前筛选条件下没有候选。</td>
                </tr>
              </tbody>
            </table>
          </div>

          <footer class="candidate-footer">
            <span>共 {{ filteredCandidates.length }} 条候选</span>
            <div class="pagination">
              <label>每页 <select v-model.number="pageSize"><option :value="20">20</option><option :value="50">50</option><option :value="100">100</option></select> 条</label>
              <button type="button" :disabled="page <= 1" @click="page--">‹</button>
              <template v-for="(pageNumber, index) in pageNumbers" :key="pageNumber">
                <span v-if="index > 0 && pageNumber - pageNumbers[index - 1] > 1" class="page-ellipsis">…</span>
                <button type="button" :class="{ active: pageNumber === page }" @click="page = pageNumber">{{ pageNumber }}</button>
              </template>
              <button type="button" :disabled="page >= totalPages" @click="page++">›</button>
            </div>
          </footer>
        </section>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.kol-import-page { padding-top: 24px; }
.kol-import-workspace { max-width: 1480px; }
.source-card, .preview-card { background: #fff; border: 1px solid #e3e9eb; border-radius: 14px; box-shadow: 0 2px 10px rgba(20,33,38,.035); }
.source-card { padding: 22px 24px; margin-bottom: 16px; }
.source-card-head, .preview-head, .source-lower, .candidate-toolbar, .candidate-footer { display: flex; align-items: center; justify-content: space-between; gap: 18px; }
.section-eyebrow { margin: 0 0 14px; color: #24383f; font-size: 14px; font-weight: 700; }
.source-file-row { display: flex; align-items: center; gap: 14px; }
.csv-icon { width: 48px; height: 48px; border-radius: 50%; background: #edf8f3; color: #17805f; display: grid; place-items: center; font-size: 11px; font-weight: 800; border: 1px solid #d7eee5; }
.source-file-main h1 { margin: 0; font-size: 22px; color: #142126; font-weight: 650; }
.source-file-title-row { display: flex; align-items: center; gap: 10px; }
.source-batch-select { border: 0; background: #f1f5f6; color: #53666e; border-radius: 8px; padding: 5px 8px; font-size: 12px; }
.source-meta, .preview-meta { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 8px; color: #7a8c94; font-size: 12px; }
.source-actions { display: flex; align-items: center; gap: 10px; align-self: flex-start; }
.source-lower { margin-top: 18px; align-items: stretch; }
.source-kpis { display: grid; grid-template-columns: repeat(3,minmax(160px,1fr)); gap: 12px; flex: 1; }
.source-kpi { padding: 14px 16px; border: 1px solid #e4eaed; border-radius: 10px; background: #fbfcfc; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.source-kpi strong { color: #142126; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.source-kpi span { color: #87969d; font-size: 11px; }
.source-explainer { width: min(390px,34%); background: #f1faf7; border: 1px solid #cfe7de; border-radius: 10px; padding: 13px 16px; color: #44615e; }
.source-explainer strong { color: #28776b; font-size: 13px; }
.source-explainer p { margin: 6px 0 0; font-size: 12px; line-height: 1.65; }
.source-error { margin-top: 12px; }
.preview-card { padding: 22px 24px 0; overflow: hidden; }
.preview-title-row { display: flex; align-items: center; gap: 7px; }
.preview-title-row h2 { margin: 0; color: #142126; font-size: 19px; }
.info-dot { width: 16px; height: 16px; border: 1px solid #c8d4d9; border-radius: 50%; color: #7a8c94; display: grid; place-items: center; font-size: 10px; }
.preview-head p { margin: 8px 0 0; color: #53666e; font-size: 13px; }
.preview-meta { justify-content: flex-end; margin-top: 0; }
.status-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 14px; margin: 20px 0 16px; }
.status-card { min-height: 112px; padding: 17px 18px; border: 1px solid #dfe7ea; border-radius: 10px; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; background: #fbfcfd; }
.status-card strong { display: block; font-size: 28px; line-height: 1; margin-bottom: 9px; }
.status-card span { display: block; font-size: 13px; color: #22343a; font-weight: 700; }
.status-card small { display: block; margin-top: 5px; font-size: 11px; color: #86959c; }
.status-icon { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; font-size: 22px; font-weight: 700; }
.tone-blue strong { color: #397bdc; } .tone-blue .status-icon { color: #397bdc; background: #edf4ff; }
.tone-amber { border-color: #ebb55e; background: #fffaf1; } .tone-amber strong, .tone-amber .status-icon { color: #d68114; } .tone-amber .status-icon { background: #fff1d8; }
.tone-green strong { color: #168157; } .tone-green .status-icon { color: #168157; background: #e8f7f0; }
.tone-red strong { color: #d94b57; } .tone-red .status-icon { color: #d94b57; background: #fdeff1; }
.identity-warning { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #fff9ec; border: 1px solid #f3dfad; color: #a36a12; border-radius: 8px; padding: 10px 13px; font-size: 12px; margin-bottom: 16px; }
.identity-warning span { font-weight: 650; white-space: nowrap; }
.candidate-toolbar { padding: 14px 0; border-top: 1px solid #edf1f2; gap: 10px; justify-content: flex-start; }
.search-field { position: relative; flex: 1 1 310px; max-width: 380px; }
.search-field input { width: 100%; height: 38px; border: 1px solid #d6dfe2; border-radius: 8px; padding: 0 34px 0 34px; outline: none; color: #24383f; }
.search-field input:focus, .filter-field select:focus { border-color: #277d78; box-shadow: 0 0 0 2px rgba(39,125,120,.12); }
.search-icon { position: absolute; left: 12px; top: 9px; color: #7a8c94; }
.filter-field { display: flex; height: 38px; align-items: center; border: 1px solid #d6dfe2; border-radius: 8px; padding-left: 10px; color: #7a8c94; font-size: 12px; background: #fff; white-space: nowrap; }
.filter-field select { border: 0; outline: 0; height: 34px; color: #344b53; background: transparent; padding: 0 26px 0 5px; }
.export-button { margin-left: auto; height: 38px; padding: 0 14px; }
.candidate-table-wrap { width: 100%; overflow-x: auto; border: 1px solid #e3e9eb; border-radius: 9px; }
.candidate-table { width: 100%; border-collapse: collapse; min-width: 1180px; table-layout: fixed; }
.candidate-table th { background: #fafbfb; color: #43565e; font-size: 11px; font-weight: 700; text-align: left; padding: 11px 12px; border-bottom: 1px solid #e3e9eb; white-space: nowrap; }
.candidate-table td { padding: 12px; border-bottom: 1px solid #edf1f2; vertical-align: middle; color: #344b53; font-size: 12px; }
.candidate-table tbody tr:last-child td { border-bottom: 0; }
.candidate-table th:nth-child(1) { width: 16%; } .candidate-table th:nth-child(2) { width: 18%; } .candidate-table th:nth-child(3) { width: 14%; } .candidate-table th:nth-child(4) { width: 9%; } .candidate-table th:nth-child(5) { width: 13%; } .candidate-table th:nth-child(6) { width: 15%; } .candidate-table th:nth-child(7) { width: 8%; } .candidate-table th:nth-child(8) { width: 7%; }
.candidate-cell { display: flex; align-items: center; gap: 10px; min-width: 0; }
.candidate-avatar { width: 32px; height: 32px; border-radius: 50%; background: #eef3ff; color: #586ac8; display: grid; place-items: center; flex: 0 0 auto; font-weight: 700; }
.candidate-cell strong, .identity-cell > span, .social-cell strong { color: #22343a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
.candidate-cell small, .identity-cell small, .social-cell small, .muted-line { display: block; color: #8a989e; margin-top: 3px; font-size: 10px; }
.identity-cell { min-width: 0; }
.exact-badge { display: inline-flex !important; width: fit-content; margin-top: 5px; padding: 2px 6px; border-radius: 5px; background: #e7f6ef; color: #288267 !important; font-size: 9px; font-weight: 650; }
.social-cell { min-width: 0; }
.social-cell span { display: block; color: #6f8087; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.source-count { font-size: 12px; color: #344b53; }
.match-result { display: block; font-size: 12px; } .match-result.unmatched { color: #566970; } .match-result.matched { color: #168157; } .match-result.conflict { color: #d94b57; }
.reason-cell { display: flex; flex-direction: column; gap: 3px; color: #5a6c73; font-size: 10px; }
.reason-cell span::first-letter { color: #d88a18; }
.status-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 54px; padding: 5px 9px; border-radius: 999px; font-size: 10px; font-weight: 700; }
.status-ready_new_kol { color: #3375ca; background: #edf4ff; }
.status-needs_review { color: #c67912; background: #fff1d8; }
.status-existing_kol { color: #168157; background: #e8f7f0; }
.status-conflict { color: #d94b57; background: #fdeff1; }
.details-button { height: 28px; padding: 0 9px; border: 1px solid #d6dfe2; border-radius: 6px; background: #fff; color: #277d78; font-size: 10px; cursor: pointer; }
.details-button:hover { background: #f2f8f7; }
.details-row td { background: #fafcfc; padding: 14px 18px; }
.details-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 14px 22px; }
.details-grid div { min-width: 0; } .details-grid span { display: block; color: #8a989e; font-size: 10px; margin-bottom: 4px; } .details-grid strong { display: block; color: #344b53; font-size: 11px; overflow-wrap: anywhere; }
.details-wide { grid-column: 1 / -1; }
.empty-state { padding: 38px !important; text-align: center; color: #8a989e !important; }
.candidate-footer { min-height: 60px; color: #62747b; font-size: 12px; }
.pagination { display: flex; align-items: center; gap: 6px; }
.pagination label { display: flex; align-items: center; gap: 6px; color: #667980; }
.pagination select, .pagination button { height: 32px; border: 1px solid #d6dfe2; border-radius: 7px; background: #fff; color: #53666e; }
.pagination select { padding: 0 24px 0 8px; }
.pagination button { min-width: 32px; padding: 0 8px; cursor: pointer; }
.pagination button.active { background: #2ba69a; border-color: #2ba69a; color: #fff; }
.pagination button:disabled { opacity: .4; cursor: default; }
.page-ellipsis { padding: 0 3px; color: #8a989e; }
@media (max-width: 1100px) {
  .source-card-head, .source-lower, .preview-head { align-items: flex-start; flex-direction: column; }
  .source-actions { align-self: stretch; }
  .source-explainer { width: auto; }
  .status-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
  .candidate-toolbar { flex-wrap: wrap; }
  .export-button { margin-left: 0; }
}
@media (max-width: 680px) {
  .kol-import-page { padding: 16px 10px 32px; }
  .source-card, .preview-card { padding-left: 15px; padding-right: 15px; }
  .source-actions { flex-direction: column; }
  .source-actions .erp-btn { width: 100%; }
  .source-kpis, .status-grid { grid-template-columns: 1fr; width: 100%; }
  .candidate-footer { align-items: flex-start; flex-direction: column; padding: 12px 0; }
  .pagination { flex-wrap: wrap; }
}
</style>
