<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import { useAccountGridPreferences, type GridColumnDefinition, type GridPreferenceValue } from '../../../Composables/useAccountGridPreferences'

type Summary = { eligible_kol_count:number; ready_count:number; already_bound_count:number; no_affiliate_count:number; blocked_count:number }
type Activation = { id:number; reconciliation_batch_id:number; import_preview_batch_id:number; source_filename:string|null; source_checksum:string|null; connection_key:string|null; candidate_count:number; created_count:number; status:string; applied_at:string|null }
type Item = {
  id:number; cluster_key:string; canonical_kol_id:number|null; kol:{id:number;display_name:string;contact_email:string|null}|null
  affiliate_account_id:number|null; affiliate:Record<string,any>|null; readiness_status:string; block_reasons:string[]
  exact_identity:Record<string,any>; primary_email:string|null; profile_urls:string[]; affiliate_snapshot:Record<string,any>
  referral_impact_count:number; referral_provider_checksum:string|null; applied_at:string|null
}
type Plan = Summary & { id:number; status:string; plan_version:number; plan_checksum:string; generated_at:string|null; applied_at:string|null; blocker_summary:Record<string,number>; items:Item[] }
type Workspace = { activations:Activation[]; activation:Activation|null; plan:Plan|null; live_summary:Summary; blocker_summary:Record<string,number>; binding_enabled:boolean; environment:string }
type ColumnKey = 'exact_identity'|'affiliate'|'referrals'|'provider_guard'|'status'

const props = defineProps<{ workspace:Workspace; gridPreferenceNamespace:string; gridPreference:GridPreferenceValue<ColumnKey>|null }>()
const busy=ref(false), search=ref(''), statusFilter=ref(''), blockerFilter=ref('')
const expandedId=ref<number|null>(null), columnSettingsOpen=ref(false)
const plan=computed(()=>props.workspace.plan), activation=computed(()=>props.workspace.activation)
const summary=computed(()=>plan.value ?? props.workspace.live_summary)
const blockerSummary=computed(()=>plan.value?.blocker_summary ?? props.workspace.blocker_summary)
const isProduction=computed(()=>props.workspace.environment==='production')
const canApply=computed(()=>!!plan.value && plan.value.status==='prepared' && plan.value.ready_count>0 && props.workspace.binding_enabled)
const checksumShort=computed(()=>plan.value?.plan_checksum ? `${plan.value.plan_checksum.slice(0,12)}…` : '—')
const sourceChecksumShort=computed(()=>activation.value?.source_checksum ? `${activation.value.source_checksum.slice(0,12)}…` : '—')

const defaultColumns:GridColumnDefinition<ColumnKey>[]=[
  {key:'exact_identity',label:'精确身份证据'}, {key:'affiliate',label:'Current Affiliate mirror'},
  {key:'referrals',label:'Referral 重解析'}, {key:'provider_guard',label:'Provider 事实保护'}, {key:'status',label:'绑定状态'},
]
const {columns:columnPreferences,visibleColumns,syncState,toggle,startDrag,drop,reset}=useAccountGridPreferences<ColumnKey>({namespace:props.gridPreferenceNamespace,definitions:defaultColumns,serverValue:props.gridPreference})
const reasonLabel=(r:string)=>({missing_canonical_kol:'Canonical KOL 缺失',stale_kol_identity:'KOL 身份已漂移',stale_affiliate_evidence:'Affiliate 证据已漂移',multiple_affiliates:'多个精确 Affiliate',affiliate_bound_elsewhere:'Affiliate 已绑定其他 KOL',kol_bound_elsewhere:'KOL 已绑定另一 Affiliate'}[r]??r)
const statusLabel=(s:string)=>({ready_for_binding:'可精确绑定',already_bound:'已正确绑定',no_affiliate:'无 Affiliate',blocked:'已阻塞',applied:'已绑定'}[s]??s)
const blockerCount=(k:string)=>blockerSummary.value[k]??0
const affiliateLabel=(item:Item)=>item.affiliate ? `Affiliate #${item.affiliate.affiliate_id} · Account #${item.affiliate_account_id}` : '—'
const providerHash=(item:Item)=>item.referral_provider_checksum ? `${item.referral_provider_checksum.slice(0,12)}…` : '—'
const emails=(item:Item)=>(item.exact_identity?.emails??[]).join(' · ')||'—'
const urls=(item:Item)=>(item.profile_urls??[]).join(' · ')||'—'

const filteredItems=computed(()=>{
  const needle=search.value.trim().toLowerCase()
  return (plan.value?.items??[]).filter(item=>{
    const hay=[item.kol?.display_name??'',item.primary_email??'',...(item.profile_urls??[]),item.affiliate?.display_name??'',item.affiliate?.email??''].join(' ').toLowerCase()
    if(needle&&!hay.includes(needle)) return false
    if(statusFilter.value&&item.readiness_status!==statusFilter.value) return false
    if(blockerFilter.value&&!item.block_reasons.includes(blockerFilter.value)) return false
    return true
  })
})
const prepare=()=>{ if(!activation.value||busy.value)return; busy.value=true; router.post('/kol/affiliatewp/binding-activation/prepare',{activation_batch_id:activation.value.id},{preserveScroll:true,onFinish:()=>busy.value=false}) }
const applyPlan=()=>{
  if(!plan.value||!canApply.value||busy.value)return
  const msg=`确认执行 ${plan.value.ready_count} 个精确 Affiliate → KOL 绑定？\n\nPlan checksum:\n${plan.value.plan_checksum}\n\n只调用 ERP 现有 bind authority；0 KOL create · 0 Provider write · 0 Convert。`
  if(!window.confirm(msg))return
  busy.value=true
  router.post(`/kol/affiliatewp/binding-activation/${plan.value.id}/apply`,{plan_checksum:plan.value.plan_checksum,ready_count:plan.value.ready_count},{preserveScroll:true,onFinish:()=>busy.value=false})
}
const changeActivation=(e:Event)=>{const id=Number((e.target as HTMLSelectElement).value);if(id)router.get('/kol/affiliatewp/binding-activation',{activation_batch_id:id})}
</script>

<template>
  <Head title="Affiliate 精确绑定 · BYL ERP" />
  <AuthenticatedLayout title="Affiliate 精确绑定" :breadcrumbs="['BYL ERP','网红','正式导入','Affiliate 精确绑定']">
    <section class="binding-page">
      <header class="page-header">
        <div><p class="eyebrow">P4B · Exact Binding Activation</p><h1>Affiliate 精确绑定</h1><p>把已完成 P4 的 Canonical KOL 与当前 Affiliate mirror 做精确绑定；不创建 KOL，不写 Provider。</p></div>
        <div class="page-actions">
          <a class="erp-btn secondary" :href="activation ? `/kol/import-activation?preview_batch_id=${activation.import_preview_batch_id}` : '/kol/import-activation'">← 返回 P4</a>
          <button class="erp-btn secondary" :disabled="!activation||busy" @click="prepare">{{busy?'处理中…':'↻ 生成 / 刷新绑定计划'}}</button>
          <button class="erp-btn danger" :disabled="!canApply||busy" @click="applyPlan">确认执行精确绑定</button>
        </div>
      </header>

      <section v-if="activation" class="source-card">
        <div class="source-main"><span class="mark">P4B</span><div><strong>{{activation.source_filename}}</strong><span>P4 Activation #{{activation.id}} · {{activation.candidate_count}} candidates · {{activation.created_count}} created KOL · {{activation.connection_key}} · SHA-256 {{sourceChecksumShort}}</span></div></div>
        <select v-if="workspace.activations.length>1" :value="activation.id" class="batch-select" @change="changeActivation"><option v-for="a in workspace.activations" :key="a.id" :value="a.id">P4 #{{a.id}} · {{a.source_filename}}</option></select>
        <div class="safety"><strong>执行边界：</strong>只有 current exact mirror 唯一且无冲突的 Affiliate 才可绑定；Referral 仅本地重解析。<span>🔒 {{isProduction?'Production':workspace.environment}} · {{workspace.binding_enabled?'绑定开关已显式启用':'绑定开关锁定'}} · 0 Provider write</span></div>
      </section>

      <div class="kpi-grid">
        <article><span>Canonical KOL</span><strong>{{summary.eligible_kol_count}}</strong><small>P4 已创建 / 精确解析</small></article>
        <article class="green"><span>可精确绑定</span><strong>{{summary.ready_count}}</strong><small>唯一 current Affiliate</small></article>
        <article class="blue"><span>已正确绑定</span><strong>{{summary.already_bound_count}}</strong><small>幂等安全跳过</small></article>
        <article><span>无 Affiliate</span><strong>{{summary.no_affiliate_count}}</strong><small>不 provisioning / 不 convert</small></article>
        <article class="amber"><span>阻塞 / 冲突</span><strong>{{summary.blocked_count}}</strong><small>Fail-closed</small></article>
      </div>

      <section class="blocker-card">
        <div><h2>精确状态阻塞</h2><p>任何 P4 evidence 与 current mirror 漂移都会阻断；重新核对上游事实后再生成计划。</p></div>
        <div class="chips">
          <span><b>{{blockerCount('stale_affiliate_evidence')}}</b> Affiliate 证据漂移</span><span><b>{{blockerCount('multiple_affiliates')}}</b> 多 Affiliate</span>
          <span><b>{{blockerCount('affiliate_bound_elsewhere')}}</b> Affiliate 已绑别人</span><span><b>{{blockerCount('kol_bound_elsewhere')}}</b> KOL 已绑另一 Affiliate</span>
          <span><b>{{blockerCount('stale_kol_identity')}}</b> KOL 身份漂移</span><span><b>{{blockerCount('missing_canonical_kol')}}</b> KOL 缺失</span>
        </div>
      </section>

      <section class="plan-card">
        <header class="plan-head"><div><h2>Affiliate Binding Activation Plan</h2><p>固定左侧 KOL identity，右侧状态；中间只展示精确 evidence、mirror、Referral impact 与 Provider guard。</p></div><span>{{plan?`Plan #${plan.id} · v${plan.plan_version} · ${checksumShort}`:'计划尚未生成'}}</span></header>
        <div class="toolbar"><input v-model="search" type="search" placeholder="搜索 KOL / Email / Affiliate"><select v-model="statusFilter"><option value="">状态：全部</option><option value="ready_for_binding">可精确绑定</option><option value="already_bound">已正确绑定</option><option value="no_affiliate">无 Affiliate</option><option value="blocked">已阻塞</option><option value="applied">已绑定</option></select><select v-model="blockerFilter"><option value="">阻塞原因：全部</option><option value="stale_affiliate_evidence">Affiliate 证据漂移</option><option value="multiple_affiliates">多个 Affiliate</option><option value="affiliate_bound_elsewhere">Affiliate 已绑别人</option><option value="kol_bound_elsewhere">KOL 已绑另一 Affiliate</option><option value="stale_kol_identity">KOL 身份漂移</option></select></div>
        <div v-if="!plan" class="empty">尚未生成绑定计划。右上角生成计划只写 P4B evidence，不会绑定 Affiliate。</div>
        <div v-else class="grid-shell">
          <div class="settings-anchor"><button class="settings-trigger" @click="columnSettingsOpen=!columnSettingsOpen">…</button><div v-if="columnSettingsOpen" class="settings-panel"><div class="settings-head"><strong>列设置</strong><button @click="reset">恢复默认</button></div><small>{{syncState==='saving'?'正在同步…':syncState==='saved'?'已同步到账号':syncState==='error'?'同步失败，保留本地缓存':'设置绑定当前账号'}}</small><div class="setting-row pinned"><span>⋮⋮</span><input type="checkbox" checked disabled><b>KOL identity</b><em>固定</em></div><div v-for="c in columnPreferences" :key="c.key" class="setting-row" draggable="true" @dragstart="startDrag(c.key)" @dragover.prevent @drop="drop(c.key)"><span>⋮⋮</span><input type="checkbox" :checked="c.visible" @change="toggle(c.key)"><b>{{c.label}}</b></div></div></div>
          <div class="table-wrap"><table><thead><tr><th class="identity-col">Canonical KOL</th><th v-for="c in visibleColumns" :key="c.key">{{c.label}}</th><th class="action-col"></th></tr></thead><tbody><template v-for="item in filteredItems" :key="item.id"><tr>
            <td class="identity-col"><div class="identity"><span>{{(item.kol?.display_name??'?').slice(0,1).toUpperCase()}}</span><div><strong>{{item.kol?.display_name??'Canonical KOL 缺失'}}</strong><small>{{item.kol?`#${item.kol.id} · ${item.kol.contact_email??'—'}`:'—'}}</small></div></div></td>
            <td v-for="c in visibleColumns" :key="c.key"><template v-if="c.key==='exact_identity'"><strong>{{emails(item)}}</strong><small>{{urls(item)}}</small></template><template v-else-if="c.key==='affiliate'"><strong>{{affiliateLabel(item)}}</strong><small>{{item.affiliate?.display_name??item.affiliate?.email??'current mirror 无唯一候选'}}</small></template><template v-else-if="c.key==='referrals'"><strong>{{item.referral_impact_count}} 条历史 Referral</strong><small>绑定后仅重解析 kol_id / status</small></template><template v-else-if="c.key==='provider_guard'"><strong>{{providerHash(item)}}</strong><small>Provider facts checksum</small></template><template v-else-if="c.key==='status'"><span class="status" :class="`s-${item.readiness_status}`">{{statusLabel(item.readiness_status)}}</span><small v-if="item.block_reasons.length">{{item.block_reasons.map(reasonLabel).join(' · ')}}</small></template></td>
            <td class="action-col"><button class="detail-btn" @click="expandedId=expandedId===item.id?null:item.id">{{expandedId===item.id?'收起':'详情'}}</button></td>
          </tr><tr v-if="expandedId===item.id" class="detail-row"><td :colspan="visibleColumns.length+2"><div class="detail-grid"><div><span>Exact Email</span><strong>{{emails(item)}}</strong></div><div><span>Exact Social URL</span><strong>{{urls(item)}}</strong></div><div><span>Canonical KOL</span><strong>{{item.kol?`${item.kol.display_name} (#${item.kol.id})`:'—'}}</strong></div><div><span>Current Affiliate</span><strong>{{affiliateLabel(item)}}</strong></div><div><span>Affiliate provider status</span><strong>{{item.affiliate?.provider_status??'—'}}</strong></div><div><span>当前绑定 KOL</span><strong>{{item.affiliate?.kol_id?`#${item.affiliate.kol_id}`:'未绑定'}}</strong></div><div><span>Referral impact</span><strong>{{item.referral_impact_count}} 条</strong></div><div><span>Provider checksum</span><strong>{{item.referral_provider_checksum??'—'}}</strong></div><div class="wide"><span>永久保护边界</span><strong>不调用 convert()；不创建 KOL；不写 AffiliateWP / WordPress / WooCommerce；Referral 的 referral_id、order、amount、currency、provider status、payout、dates/context 均保持不变。</strong></div></div></td></tr></template><tr v-if="filteredItems.length===0"><td :colspan="visibleColumns.length+2" class="empty-cell">当前筛选条件下没有计划项。</td></tr></tbody></table></div>
        </div>
      </section>

      <section class="confirm-guard"><strong>高风险确认：</strong><span>Apply 前重新读取 current Affiliate mirror + P4/KOL 状态 + Referral Provider checksum。任一项漂移会整批阻断。成功路径仅执行现有 `AffiliateBindingService::bind()`，并在同一业务事务内完成 ERP-local Referral re-resolution。</span><b>0 KOL create · 0 Provider write · 0 Convert</b></section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.binding-page{padding:20px;min-height:calc(100vh - 80px);background:#f4f7f8;color:#20313a}.page-header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:14px}.eyebrow{margin:0 0 5px;color:#33867e;font-size:12px;font-weight:800}.page-header h1{margin:0;font-size:28px;color:#142126}.page-header p{margin:7px 0 0;color:#72858d;font-size:13px}.page-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.erp-btn{height:38px;padding:0 13px;border-radius:9px;border:1px solid #cbd8dc;background:#fff;color:#455d66;font-size:12px;font-weight:750;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}.erp-btn:disabled{opacity:.42;cursor:not-allowed}.erp-btn.danger{background:#a8464d;border-color:#a8464d;color:#fff}.source-card,.blocker-card,.plan-card,.confirm-guard{background:#fff;border:1px solid #dce5e7;border-radius:14px;box-shadow:0 3px 12px rgba(22,48,58,.035)}.source-card{padding:18px 20px;margin-bottom:14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.source-main{display:flex;align-items:center;gap:12px;min-width:0;flex:1}.source-main>div{display:flex;flex-direction:column;gap:4px;min-width:0}.source-main strong{font-size:15px}.source-main span{font-size:11px;color:#809198}.mark{width:45px;height:42px;display:grid;place-items:center;border-radius:10px;background:#e8f7f2;color:#27816e;font-weight:800}.batch-select{height:36px;border:1px solid #ccd9dd;border-radius:8px;padding:0 9px}.safety{width:100%;padding:10px 13px;background:#fff7e8;border:1px solid #efcf91;border-radius:9px;color:#875b16;font-size:12px;display:flex;justify-content:space-between;gap:8px}.safety span{font-weight:800}.kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.kpi-grid article{background:#fff;border:1px solid #dce5e7;border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:3px}.kpi-grid span{font-size:11px;color:#72858d;font-weight:700}.kpi-grid strong{font-size:29px;line-height:1.05}.kpi-grid small{font-size:10px;color:#96a3a8}.green strong{color:#26845e}.blue strong{color:#4567b8}.amber strong{color:#d27f12}.blocker-card{padding:17px 19px;margin-bottom:14px}.blocker-card h2,.plan-head h2{margin:0;font-size:18px}.blocker-card p,.plan-head p{margin:5px 0 0;font-size:12px;color:#7b8d94}.chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:13px}.chips span{padding:7px 9px;border:1px solid #dce5e7;border-radius:8px;background:#f8fafb;font-size:11px;color:#5f737b}.chips b{color:#d17c10}.plan-card{position:relative;overflow:hidden}.plan-head{padding:17px 19px;display:flex;justify-content:space-between;gap:16px;border-bottom:1px solid #e9eef0}.plan-head>span{font-size:11px;color:#7b8d94}.toolbar{display:flex;gap:8px;padding:11px 19px;border-bottom:1px solid #edf1f2}.toolbar input,.toolbar select{height:36px;border:1px solid #ccd9dd;border-radius:8px;background:#fff;padding:0 10px;color:#40565f;font-size:12px}.toolbar input{min-width:290px}.empty{padding:40px;text-align:center;color:#7f9097;font-size:13px}.grid-shell{position:relative}.table-wrap{overflow-x:auto}table{width:100%;min-width:1260px;border-collapse:collapse}th{height:44px;padding:8px 11px;background:#f8fafb;border-bottom:1px solid #dfe7e9;text-align:left;color:#596e76;font-size:10px;white-space:nowrap}td{padding:11px;border-bottom:1px solid #edf1f2;color:#40555e;font-size:11px;vertical-align:middle}td>small{display:block;margin-top:3px;color:#91a0a6;font-size:9px}.identity-col{position:sticky;left:0;min-width:215px;background:#fff!important;z-index:3}thead .identity-col{background:#f8fafb!important;z-index:5}.action-col{position:sticky;right:0;width:70px;min-width:70px;background:#fff!important;z-index:4}thead .action-col{background:#f8fafb!important}.identity{display:flex;gap:9px;align-items:center}.identity>span{width:31px;height:31px;border-radius:50%;display:grid;place-items:center;background:#edf2ff;color:#4a6ec3;font-weight:800}.identity strong,.identity small{display:block;max-width:175px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:9px;font-weight:800}.s-ready_for_binding{background:#e7f6ee;color:#257652}.s-already_bound,.s-applied{background:#e9efff;color:#4567b8}.s-no_affiliate{background:#eef2f3;color:#62757d}.s-blocked{background:#fff0d8;color:#a76709}.detail-btn{height:28px;border:1px solid #ccd9dd;border-radius:7px;background:#fff;color:#287b74;font-size:10px}.detail-row td{background:#fafcfc;padding:13px 17px}.detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 18px}.detail-grid span{display:block;color:#8a989e;font-size:9px;margin-bottom:4px}.detail-grid strong{font-size:10px;overflow-wrap:anywhere}.detail-grid .wide{grid-column:1/-1}.settings-anchor{position:absolute;top:0;right:0;width:70px;height:44px;display:flex;align-items:center;justify-content:center;background:#f8fafb;z-index:20}.settings-trigger{width:39px;height:29px;border:1px solid #d5dfe2;border-radius:7px;background:#fff;font-size:19px}.settings-panel{position:absolute;top:38px;right:0;width:295px;padding:11px;background:#fff;border:1px solid #dce5e7;border-radius:10px;box-shadow:0 12px 30px rgba(20,40,48,.14);z-index:30}.settings-head{display:flex;justify-content:space-between}.settings-head button{border:0;background:transparent;color:#2d8c84}.settings-panel>small{display:block;margin:6px 0;color:#839299;font-size:10px}.setting-row{display:grid;grid-template-columns:20px 20px 1fr auto;align-items:center;gap:5px;min-height:31px;padding:3px 4px;font-size:11px}.setting-row em{font-size:9px;color:#8b9aa0;font-style:normal}.confirm-guard{margin-top:14px;padding:13px 16px;display:flex;gap:9px;align-items:flex-start;background:#f5fbf9;border-color:#cfe6df;color:#526b67;font-size:11px}.confirm-guard strong{color:#267568;white-space:nowrap}.confirm-guard span{line-height:1.55;flex:1}.confirm-guard b{white-space:nowrap;color:#855b16}.empty-cell{text-align:center;padding:30px!important;color:#8c9ba1!important}@media(max-width:1050px){.page-header{flex-direction:column}.page-actions{justify-content:flex-start}.kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.safety,.confirm-guard{flex-direction:column}.toolbar{flex-wrap:wrap}}@media(max-width:620px){.binding-page{padding:12px}.page-header h1{font-size:24px}.page-actions{display:grid;grid-template-columns:1fr;width:100%}.erp-btn{justify-content:center}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.toolbar input,.toolbar select{width:100%;min-width:0}.chips{display:grid;grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:1fr}}
</style>
