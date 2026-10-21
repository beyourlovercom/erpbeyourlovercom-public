<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ApprovalModule = 'kols' | 'collaborations' | 'payouts'
type PendingRow = {
  id:number; title:string; detail_href:string; owner_name?:string|null; country_code?:string|null; cooperation_type?:string|null;
  platform?:string|null; handle?:string|null; reason_code?:string|null; created_at?:string|null; submitted_at?:string|null;
  kol_id?:number; kol_name?:string; amount?:string|null; currency?:string|null; deliverables?:string|null; planned_publish_at?:string|null;
  requester_name?:string; affiliate_id?:number; referral_ids?:number[]
}
type HistoryRow = {
  record_key:string; id:number; title:string; detail_href:string; outcome:'approved'|'rejected'; actor_name:string; decided_at:string|null;
  source:string; reason?:string|null; meta?:string[]; kol_id?:number; kol_name?:string; cooperation_type?:string|null;
  amount?:string|null; currency?:string|null; requester_name?:string; affiliate_id?:number
}
type Workspace = { module:ApprovalModule; pending_count:number; history_count:number; pending:PendingRow[]; history:HistoryRow[] }

const props = defineProps<{workspace:Workspace}>()
const view = ref<'pending'|'history'>('pending')
const reasons = reactive<Record<number,string>>({})

const modules = [
  { key:'kols' as const, label:'KOL 审批', href:'/kol/approvals/kols', description:'网红准入与资料审批' },
  { key:'collaborations' as const, label:'付费合作审批', href:'/kol/approvals/collaborations', description:'合作金额与付费条款审批' },
  { key:'payouts' as const, label:'佣金付款审批', href:'/kol/approvals/payouts', description:'佣金结算申请审批' },
]
const current = computed(() => modules.find(item => item.key === props.workspace.module)!)
const kind = computed(() => props.workspace.module)
const breadcrumbs = computed(() => [{label:'BYL ERP',href:'/dashboard'},{label:'网红',href:'/kol'},current.value.label])
const pageTitle = computed(() => `${current.value.label} · BYL ERP`)

function approve(id:number){ router.post(`/kol/approvals/${kind.value}/${id}/approve`, {}, { preserveScroll:true }) }
function reject(id:number){ const reason=(reasons[id]||'').trim(); if(reason) router.post(`/kol/approvals/${kind.value}/${id}/reject`, {reason}, {preserveScroll:true}) }
function date(v:string|null|undefined){ return v ? new Date(v).toLocaleString('zh-CN',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}) : '—' }
function money(amount:string|null|undefined,currency:string|null|undefined){ return amount ? `${(currency||'').toUpperCase()} ${amount}`.trim() : '' }
function initial(row:PendingRow|HistoryRow){ const name=('kol_name' in row && row.kol_name) ? row.kol_name : row.title; return (name||'?').trim().slice(0,1).toUpperCase() }
function pendingMeta(row:PendingRow){
  if(props.workspace.module==='kols') return [row.platform && (row.handle ? `${row.platform} · ${row.handle}` : row.platform), row.country_code, row.cooperation_type].filter(Boolean).join('  ·  ')
  if(props.workspace.module==='collaborations') return [row.kol_name, row.cooperation_type, row.planned_publish_at ? `计划发布 ${row.planned_publish_at}` : null].filter(Boolean).join('  ·  ')
  return [row.kol_name, row.requester_name ? `申请人：${row.requester_name}` : null, row.affiliate_id ? `Affiliate #${row.affiliate_id}` : null].filter(Boolean).join('  ·  ')
}
function historyMeta(row:HistoryRow){
  if(props.workspace.module==='kols') return (row.meta||[]).join('  ·  ') || row.source
  if(props.workspace.module==='collaborations') return [row.kol_name,row.cooperation_type].filter(Boolean).join('  ·  ')
  return [row.kol_name,row.requester_name ? `申请人：${row.requester_name}` : null,row.affiliate_id ? `Affiliate #${row.affiliate_id}` : null].filter(Boolean).join('  ·  ')
}
function detailText(){ return props.workspace.module==='payouts' ? '查看结算单' : '查看详情' }
</script>

<template>
  <Head :title="pageTitle" />
  <AuthenticatedLayout :title="current.label" :breadcrumbs="breadcrumbs">
    <section class="approval-shell">
      <header class="approval-heading">
        <div>
          <p class="eyebrow">KOL · MANAGER APPROVAL</p>
          <h2>{{ current.label }}</h2>
          <p>{{ current.description }}。每条申请与历史审批保持一一对应，批准佣金付款不代表 Finance 已完成真实付款。</p>
        </div>
        <div class="summary-number"><strong>{{ workspace.pending_count }}</strong><span>待处理</span><small>{{ workspace.history_count }} 条审批记录</small></div>
      </header>

      <nav class="module-tabs" aria-label="审批模块">
        <Link v-for="item in modules" :key="item.key" :href="item.href" :class="{active:item.key===workspace.module}">{{ item.label }}</Link>
      </nav>

      <div class="view-toolbar">
        <div class="view-tabs">
          <button :class="{active:view==='pending'}" @click="view='pending'">待审批 <b>{{ workspace.pending_count }}</b></button>
          <button :class="{active:view==='history'}" @click="view='history'">审批记录 <b>{{ workspace.history_count }}</b></button>
        </div>
        <p>{{ view==='pending' ? '按提交时间显示当前待处理申请' : '按审批时间倒序显示最近审批记录' }}</p>
      </div>

      <div v-if="view==='pending'" class="approval-list">
        <article v-for="row in workspace.pending" :key="row.id" class="approval-card">
          <section class="business-side">
            <div class="identity-mark">{{ initial(row) }}</div>
            <div class="business-copy">
              <div class="title-line"><h3>{{ row.title }}</h3><span v-if="workspace.module==='payouts'" class="id-chip">#{{ row.id }}</span></div>
              <p class="primary-meta">{{ pendingMeta(row) || '—' }}</p>
              <p v-if="workspace.module==='kols'" class="secondary-meta">负责人：{{ row.owner_name || '未分配' }} · 创建时间：{{ date(row.created_at) }}</p>
              <p v-else-if="workspace.module==='collaborations'" class="secondary-meta">负责人：{{ row.owner_name || '未分配' }} · 创建时间：{{ date(row.created_at) }}</p>
              <p v-else class="secondary-meta">Referral {{ (row.referral_ids||[]).map(id=>`#${id}`).join('、') || '—' }} · 创建时间：{{ date(row.created_at) }}</p>
              <p v-if="workspace.module==='kols' && row.reason_code" class="record-note">申请依据：{{ row.reason_code }}</p>
              <p v-if="workspace.module==='collaborations' && row.deliverables" class="record-note">{{ row.deliverables }}</p>
            </div>
            <div v-if="row.amount" class="amount-column"><strong>{{ row.amount }}</strong><span>{{ row.currency }}</span></div>
          </section>

          <aside class="decision-side">
            <div class="status-badge pending"><span>◷</span> 待审批</div>
            <dl>
              <div><dt>提交时间</dt><dd>{{ date(row.submitted_at) }}</dd></div>
              <div v-if="workspace.module==='payouts'"><dt>当前阶段</dt><dd>Manager 审批</dd></div>
              <div v-else><dt>当前审批</dt><dd>Manager</dd></div>
            </dl>
            <div class="decision-actions">
              <button class="approve-btn" type="button" @click="approve(row.id)">✓ {{ workspace.module==='payouts' ? '批准付款' : '批准' }}</button>
              <div class="reject-line"><input v-model="reasons[row.id]" placeholder="填写驳回原因"><button type="button" :disabled="!(reasons[row.id]||'').trim()" @click="reject(row.id)">驳回</button></div>
              <Link class="detail-btn" :href="row.detail_href">{{ detailText() }}</Link>
            </div>
          </aside>
        </article>
        <div v-if="!workspace.pending.length" class="empty-state"><strong>当前没有待审批记录</strong><p>新的 {{ current.label }} 申请会自动出现在这里。</p></div>
      </div>

      <div v-else class="approval-list">
        <article v-for="row in workspace.history" :key="row.record_key" class="approval-card history-card">
          <section class="business-side">
            <div class="identity-mark muted">{{ initial(row) }}</div>
            <div class="business-copy">
              <div class="title-line"><h3>{{ row.title }}</h3></div>
              <p class="primary-meta">{{ historyMeta(row) || '—' }}</p>
              <p class="secondary-meta">审批来源：{{ row.source }}</p>
              <p v-if="row.reason" class="record-note" :class="{ 'reject-note': row.outcome==='rejected' }">{{ row.outcome==='rejected' ? '驳回原因' : '审批备注' }}：{{ row.reason }}</p>
            </div>
            <div v-if="row.amount" class="amount-column"><strong>{{ row.amount }}</strong><span>{{ row.currency }}</span></div>
          </section>

          <aside class="decision-side history-decision">
            <div class="status-badge" :class="row.outcome"><span>{{ row.outcome==='approved'?'✓':'×' }}</span> {{ row.outcome==='approved'?'已批准':'已驳回' }}</div>
            <dl>
              <div><dt>审批人</dt><dd>{{ row.actor_name }}</dd></div>
              <div><dt>审批时间</dt><dd>{{ date(row.decided_at) }}</dd></div>
            </dl>
            <div class="decision-actions"><Link class="detail-btn" :href="row.detail_href">{{ detailText() }}</Link></div>
          </aside>
        </article>
        <div v-if="!workspace.history.length" class="empty-state"><strong>暂无审批记录</strong><p>批准或驳回后，记录会保留在当前模块。</p></div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.approval-shell{display:grid;gap:18px;max-width:1500px;margin:0 auto;padding-bottom:36px}.approval-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:28px}.eyebrow{margin:0 0 8px;font-size:11px;font-weight:750;letter-spacing:.18em;color:#64748b}.approval-heading h2{margin:0;font-size:30px;letter-spacing:-.025em;color:#0f172a}.approval-heading>div>p:last-child{margin:9px 0 0;max-width:860px;color:#64748b;line-height:1.7}.summary-number{text-align:right;min-width:120px}.summary-number strong{display:block;font-size:38px;line-height:1;color:#0f172a}.summary-number span{display:block;margin-top:6px;font-weight:700;color:#334155}.summary-number small{display:block;margin-top:5px;color:#94a3b8}.module-tabs{display:flex;gap:36px;border-bottom:1px solid #e2e8f0}.module-tabs a{position:relative;padding:12px 0 14px;color:#475569;text-decoration:none;font-weight:650}.module-tabs a.active{color:#0f766e}.module-tabs a.active:after{content:"";position:absolute;left:0;right:0;bottom:-1px;height:3px;border-radius:3px;background:#0f766e}.view-toolbar{display:flex;justify-content:space-between;align-items:center;gap:16px}.view-toolbar p{margin:0;font-size:12px;color:#94a3b8}.view-tabs{display:flex;gap:24px}.view-tabs button{border:0;border-bottom:2px solid transparent;background:transparent;padding:9px 4px;color:#64748b;font-weight:650;cursor:pointer}.view-tabs button.active{border-bottom-color:#0f766e;color:#0f766e}.view-tabs b{margin-left:5px}.approval-list{display:grid;gap:12px}.approval-card{display:grid;grid-template-columns:minmax(0,1fr) 390px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 3px 14px rgba(15,23,42,.055);overflow:hidden}.business-side{display:grid;grid-template-columns:58px minmax(0,1fr) auto;gap:16px;align-items:center;padding:18px 22px}.identity-mark{display:grid;place-items:center;width:54px;height:54px;border-radius:12px;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;font-size:22px;font-weight:800}.identity-mark.muted{background:linear-gradient(135deg,#64748b,#94a3b8)}.business-copy{min-width:0}.title-line{display:flex;align-items:center;gap:8px}.title-line h3{margin:0;color:#172033;font-size:17px;line-height:1.35}.id-chip{padding:2px 7px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:11px}.primary-meta,.secondary-meta{margin:5px 0 0;color:#64748b;font-size:12px;line-height:1.5}.secondary-meta{color:#7c8a9c}.record-note{display:inline-block;max-width:720px;margin:8px 0 0;padding:5px 9px;border-radius:6px;background:#f8fafc;color:#64748b;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.reject-note{background:#fff1f2;color:#be123c}.amount-column{min-width:130px;text-align:right;padding-left:14px}.amount-column strong{display:block;color:#0f766e;font-size:20px;letter-spacing:.02em}.amount-column span{display:block;margin-top:2px;color:#64748b;font-size:11px}.decision-side{display:grid;grid-template-columns:minmax(0,1fr) 145px;gap:12px 18px;align-items:start;padding:18px 20px;border-left:1px solid #e8edf3;background:#fcfdfd}.status-badge{grid-column:1;justify-self:start;display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:750}.status-badge.pending{background:#fff7ed;color:#c2410c}.status-badge.approved{background:#ecfdf5;color:#047857}.status-badge.rejected{background:#fff1f2;color:#dc2626}.decision-side dl{grid-column:1;margin:0;display:grid;gap:5px}.decision-side dl div{display:flex;gap:7px;font-size:12px}.decision-side dt{color:#64748b}.decision-side dd{margin:0;color:#334155}.decision-actions{grid-column:2;grid-row:1 / span 2;display:grid;gap:8px}.decision-actions button,.detail-btn{min-height:34px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#334155;font-size:12px;font-weight:650;text-align:center;text-decoration:none;display:grid;place-items:center;cursor:pointer}.decision-actions .approve-btn{border-color:#0f766e;background:#0f766e;color:#fff}.reject-line{display:grid;grid-template-columns:1fr auto;gap:6px}.reject-line input{min-width:0;width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:7px 8px;font-size:11px}.reject-line button{padding:0 9px;border-color:#fecaca;color:#dc2626}.reject-line button:disabled{opacity:.4;cursor:not-allowed}.history-decision{grid-template-columns:minmax(0,1fr) 145px}.empty-state{padding:70px 24px;text-align:center;border:1px dashed #cbd5e1;border-radius:14px;background:#fff;color:#64748b}.empty-state strong{display:block;color:#334155}.empty-state p{margin:7px 0 0;font-size:12px}@media(max-width:1100px){.approval-card{grid-template-columns:1fr}.decision-side{border-left:0;border-top:1px solid #e8edf3}.amount-column{min-width:auto}.module-tabs{gap:20px;overflow:auto}}@media(max-width:720px){.approval-heading{flex-direction:column}.summary-number{text-align:left}.business-side{grid-template-columns:48px 1fr}.identity-mark{width:46px;height:46px}.amount-column{grid-column:2;text-align:left;padding-left:0}.decision-side{grid-template-columns:1fr}.decision-actions{grid-column:1;grid-row:auto}.view-toolbar{align-items:flex-start;flex-direction:column}}
</style>
