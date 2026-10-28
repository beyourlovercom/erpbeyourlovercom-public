<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Availability = { warehouse_id:number; warehouse_slug:string; warehouse_name:string; inventory_state:string; can_auto_allocate:boolean; quantity_on_hand:number|null; quantity_reserved:number|null; quantity_available:number|null }
type GiftOption = { mapping_id:number; warehouse_id:number; warehouse_code:string; warehouse_name:string; fulfillment_product_id:number; fulfillment_sku:string; fulfillment_name:string; quantity_available:number|null; can_fulfill:boolean; priority:number }
type Item = { id:number; product_id:number|null; product_url:string|null; sku:string|null; source_sku:string|null; resolved_fulfillment_sku:string|null; product_summary:string|null; quantity:number; availability:Availability[]; is_gift:boolean; gift_options_by_warehouse:Record<string,GiftOption[]> }
type PlanItem = { product_id:number|null; quantity:number; sku?:string|null; source_sku?:string|null; resolved_fulfillment_sku?:string|null; product_summary?:string|null }
type Segment = { source_type:'factory'|'warehouse'; source_code:string; warehouse_id:number|null; warehouse_name:string; carrier?:string|null; service?:string|null; carrier_selection_source?:string|null; items:PlanItem[] }
type PlanCost = { status:string; amount:number|null; currency:string|null; reason_code?:string|null; reason?:string|null }
type Zone = { status:string; zone:'west'|'east'|null; zone_label:string|null; postal_code:string|null; zip3:number|null; state:string|null; warehouse_priority:string[] }
type Plan = { plan_key:string; plan_type:'single'|'factory'|'split'; is_split:boolean; shipment_count:number; label:string; segments:Segment[]; cost:PlanCost; effective_cost?:number|null; execution_status:string; destination_zone?:Zone|null }
type AllocationDecision = { status:'system_recommendation'|'manual_review'; status_label:string; reason_code:string; manual_reasons:string[]; recommended_plan_key:string|null; recommended_plan:Plan|null; baseline_plan:Plan|null; estimated_cost:number|null; currency:string|null; estimated_saving:number|null; plans:Plan[]; can_use_recommendation:boolean; policy:{ system_is_advisory:boolean; human_confirmation_required:boolean; missing_data_falls_back_to_manual:boolean; same_order_item_quantity_split_allowed:boolean; max_sources:number; split_pattern:string } }
type DerivedOrderRecord = { order_id:number; erp_order_id:string|null; derived:boolean; suffix:string|null; warehouse_id:number|null; warehouse_code:string|null; warehouse_name:string|null; carrier?:string|null; service?:string|null; carrier_selection_source?:string|null; items:Array<{ sku:string|null; source_sku?:string|null; resolved_fulfillment_sku?:string|null; product_summary:string|null; quantity:number }> }
type ExistingAllocation = { status:string; decision_source:'system_recommendation'|'manual'; selected_plan_key:string|null; decision_note:string|null; plan_snapshot:(Plan & { derived_orders?:DerivedOrderRecord[] })|null; submitted_at:string|null }
type CarrierRoute = { carrier:string|null; carrier_label:string|null; service:string|null; automatic:boolean }
type Order = { id:number; erp_order_id:string|null; order_url:string|null; recipient_name:string|null; phone:string|null; country:string|null; province_state:string|null; city:string|null; address_line_1:string|null; address_line_2:string|null; postal_code:string|null; us_fulfillment_zone:Zone|null; carrier_routes:Record<string,CarrierRoute>; item_count:number; items:Item[]; allocation_decision:AllocationDecision; fulfillment_allocation:ExistingAllocation|null }
type Warehouse = { id:number; slug:string; code:string; name:string; country_code:string|null; can_auto_allocate:boolean }
type CarrierOption = { value:string; label:string }
type Paginated<T> = { data:T[]; current_page:number; last_page:number; per_page:number; total:number }
type TabKey = 'pending'|'recommendation'|'manual'|'gift'|'processed'

const props = defineProps<{ orders:Paginated<Order>; warehouses:Warehouse[]; carrierOptions:CarrierOption[]; pendingTotal:number; canOperate:boolean }>()
const activeTab = ref<TabKey>('pending')
const selectedOrderId = ref<number|null>(null)
const decisionNote = ref('')
const submitError = ref('')
const submitting = ref(false)
const localSubmitted = reactive<Record<number,{source:'system_recommendation'|'manual';submittedAt:string}>>({})
const manualAssignments = reactive<Record<number,Record<number,string>>>({})
const manualGiftMappings = reactive<Record<number,Record<number,string>>>({})
const manualCarriers = reactive<Record<number,Record<string,string>>>({})

const selectedOrder = computed(() => props.orders.data.find(order => order.id === selectedOrderId.value) ?? null)
const processed = (order:Order) => Boolean(order.fulfillment_allocation || localSubmitted[order.id])
const hasGift = (order:Order) => order.items.some(item => item.is_gift)
const counts = computed(() => ({
  pending: props.orders.data.filter(order => !processed(order)).length,
  recommendation: props.orders.data.filter(order => !processed(order) && order.allocation_decision.status === 'system_recommendation').length,
  manual: props.orders.data.filter(order => !processed(order) && order.allocation_decision.status === 'manual_review').length,
  gift: props.orders.data.filter(order => !processed(order) && hasGift(order)).length,
  processed: props.orders.data.filter(processed).length,
}))
const filteredOrders = computed(() => props.orders.data.filter(order => {
  if (activeTab.value === 'processed') return processed(order)
  if (processed(order)) return false
  if (activeTab.value === 'recommendation') return order.allocation_decision.status === 'system_recommendation'
  if (activeTab.value === 'manual') return order.allocation_decision.status === 'manual_review'
  if (activeTab.value === 'gift') return hasGift(order)
  return true
}))
const warehouseOptions = computed(() => props.warehouses.filter(w => ['FACTORY','US-NY','US-LA','US-SM','DE','CA'].includes(w.code)))
const canonicalWarehouseCode = (code:string) => code === 'US-SJ' ? 'US-SM' : code
const sourceLabel = (code:string) => { const canonical=canonicalWarehouseCode(code); return props.warehouses.find(w => w.code === canonical)?.name ?? canonical }
const carrierLabel = (carrier:string|null|undefined) => props.carrierOptions.find(row => row.value === carrier)?.label ?? carrier ?? '—'
const zonePriorityLabel = (zone:Zone|null) => !zone?.warehouse_priority?.length ? '—' : zone.warehouse_priority.map(sourceLabel).join(' → ')
const zoneShort = (order:Order) => order.us_fulfillment_zone?.status === 'matched' ? order.us_fulfillment_zone.zone_label ?? '已识别' : (order.country === 'US' ? '需人工确认' : '—')

function csrfHeaders():Record<string,string>{ const m=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content??''; if(m) return {'X-CSRF-TOKEN':m}; const x=decodeURIComponent(document.cookie.split('; ').find(r=>r.startsWith('XSRF-TOKEN='))?.split('=')[1]??''); return x?{'X-XSRF-TOKEN':x}:{} }
function money(v:number|null|undefined,c:string|null|undefined){ if(v==null||!c)return '—'; return `${c==='CNY'?'¥':c+' '}${Number(v).toFixed(2)}` }
function addressLine(order:Order){ return [order.address_line_1,order.address_line_2,order.city,order.province_state,order.postal_code,order.country].filter(Boolean).join(', ') }
function recommendedSegment(order:Order,item:Item){ return order.allocation_decision.recommended_plan?.segments.find(segment => segment.items.some(planItem => (item.product_id != null && planItem.product_id === item.product_id) || (!!item.sku && planItem.sku === item.sku))) }
function defaultCarrier(order:Order,source:string){ const canonical=canonicalWarehouseCode(source); return order.carrier_routes?.[canonical]?.carrier ?? order.carrier_routes?.[source]?.carrier ?? '' }
function ensureManual(order:Order){
  manualAssignments[order.id] ||= {}; manualGiftMappings[order.id] ||= {}; manualCarriers[order.id] ||= {}
  for(const item of order.items){
    if(!(item.id in manualAssignments[order.id])) manualAssignments[order.id][item.id]=canonicalWarehouseCode(recommendedSegment(order,item)?.source_code ?? '')
    if(!(item.id in manualGiftMappings[order.id])) manualGiftMappings[order.id][item.id]=''
    const source=manualAssignments[order.id][item.id]
    if(source && !(source in manualCarriers[order.id])) manualCarriers[order.id][source]=recommendedSegment(order,item)?.carrier ?? defaultCarrier(order,source)
  }
}
function openPlan(order:Order){ selectedOrderId.value=order.id; decisionNote.value=order.fulfillment_allocation?.decision_note??''; submitError.value=''; ensureManual(order) }
function closePlan(){ selectedOrderId.value=null; submitError.value='' }
function availabilityFor(item:Item,w:Warehouse){ return item.availability.find(r=>r.warehouse_id===w.id) }
function availabilityLabel(item:Item,w:Warehouse){ if(w.code==='FACTORY') return '国内工厂'; const r=availabilityFor(item,w); if(!r||r.quantity_available==null)return '库存未知'; return r.quantity_available>=item.quantity?`有货 ${r.quantity_available}`:`不足 ${r.quantity_available}` }
function availabilityClass(item:Item,w:Warehouse){ if(w.code==='FACTORY')return 'stock-neutral'; const r=availabilityFor(item,w); if(!r||r.quantity_available==null)return 'stock-unknown'; return r.quantity_available>=item.quantity?'stock-ok':'stock-short' }

type WarehouseAdvice = { kind:'recommended'|'manual'|'none'; label:string; detail:string }
// Display advisory warehouse inventory independently of automatic-routing eligibility.
// Never turn a ZIP region preference or manually-entered stock into a green system recommendation.
function warehouseAdvice(order:Order,item:Item):WarehouseAdvice {
  const segment=order.allocation_decision.status==='system_recommendation' && order.allocation_decision.can_use_recommendation
    ? recommendedSegment(order,item) : null
  if(segment){
    if(segment.source_type==='factory')return {kind:'recommended',label:segment.warehouse_name||sourceLabel(segment.source_code),detail:'已形成系统履约方案；仍需 Vita 确认'}
    const w=warehouseOptions.value.find(row=>canonicalWarehouseCode(row.code)===canonicalWarehouseCode(segment.source_code))
    const stock=w?availabilityFor(item,w):null
    if(w && stock?.can_auto_allocate && stock.quantity_available!=null && stock.quantity_available>=item.quantity)
      return {kind:'recommended',label:segment.warehouse_name||w.name,detail:'库存覆盖需求且符合自动分仓资格'}
  }
  if(item.is_gift)return {kind:'none',label:'需人工确认礼品',detail:'先选择实际赠品 SKU 与发货仓'}
  const priority=order.us_fulfillment_zone?.status==='matched' ? order.us_fulfillment_zone.warehouse_priority.map(canonicalWarehouseCode) : []
  const fallback=['US-SM','US-LA','US-NY']
  const candidates=warehouseOptions.value
    .filter(w=>w.code!=='FACTORY' && w.country_code===order.country)
    .map(w=>({w,stock:availabilityFor(item,w)}))
    .filter((c):c is {w:Warehouse;stock:Availability}=>c.stock!=null && c.stock.quantity_available!=null && c.stock.quantity_available>=item.quantity)
    .sort((a,b)=>{
      const score=(c:{w:Warehouse})=>{
        const code=canonicalWarehouseCode(c.w.code)
        const region=priority.indexOf(code)
        const backup=fallback.indexOf(code)
        return region>=0 ? region : priority.length+(backup>=0?backup:100)
      }
      return score(a)-score(b)
    })
  if(!candidates.length)return {kind:'none',label:'无有效推荐',detail:'没有已知库存覆盖需求的同国家仓库，请人工核实'}
  const {w,stock}=candidates[0]
  const crossRegion=order.country==='US' && priority.length>0 && !priority.includes(canonicalWarehouseCode(w.code))
  const unconfirmed=!stock.can_auto_allocate || stock.inventory_state!=='confirmed'
  return {
    kind:'manual',
    label:`${crossRegion?'跨区':'有货'}人工备选 · ${w.name}`,
    detail:`可用 ${stock.quantity_available} 件；${unconfirmed?'库存尚未通过自动分仓 Full Snapshot 验收；':''}${crossRegion?'跨区发货须人工确认；':''}不是系统自动推荐`,
  }
}

function giftOptions(item:Item,source:string){ return item.gift_options_by_warehouse?.[source] ?? [] }
function availableGiftOptions(item:Item,source:string){ return giftOptions(item,source).filter(o=>o.can_fulfill).sort((a,b)=>a.priority-b.priority) }
function giftEmptyLabel(item:Item,source:string){ const opts=giftOptions(item,source); if(!opts.length)return '该仓当前没有可用清仓礼品'; if(!opts.some(o=>o.can_fulfill))return '礼品库存在，但当前全部缺货'; return '' }
function chosenGift(item:Item,order:Order){ const id=Number(manualGiftMappings[order.id]?.[item.id]||0); return Object.values(item.gift_options_by_warehouse||{}).flat().find(o=>o.mapping_id===id) }
function giftStockLabel(item:Item,w:Warehouse){ const opts=giftOptions(item,w.code); if(!opts.length)return '无清仓礼品'; const available=availableGiftOptions(item,w.code); if(available.length)return `有货 ${available[0].quantity_available ?? '未知'}`; return '礼品库全部缺货' }
function giftStockClass(item:Item,w:Warehouse){ const opts=giftOptions(item,w.code); if(!opts.length)return 'stock-unknown'; return opts.some(o=>o.can_fulfill)?'stock-ok':(opts.some(o=>o.quantity_available!=null)?'stock-short':'stock-unknown') }
function carrierForItem(order:Order,item:Item){ const source=manualAssignments[order.id]?.[item.id]??''; return source ? (manualCarriers[order.id]?.[source] ?? defaultCarrier(order,source)) : '' }
function carrierIsDefault(order:Order,item:Item){ const source=manualAssignments[order.id]?.[item.id]??''; const selected=carrierForItem(order,item); return !!source && !!selected && selected===defaultCarrier(order,source) }
function onWarehouseChanged(order:Order,item:Item){
  const source=manualAssignments[order.id][item.id]
  if(source && !manualCarriers[order.id][source]) manualCarriers[order.id][source]=defaultCarrier(order,source)
  if(!item.is_gift)return
  const selected=manualGiftMappings[order.id][item.id]
  if(selected && !giftOptions(item,source).some(o=>String(o.mapping_id)===selected)) manualGiftMappings[order.id][item.id]=''
}
function onCarrierChanged(order:Order,item:Item,event:Event){ const source=manualAssignments[order.id]?.[item.id]??''; if(!source)return; manualCarriers[order.id][source]=(event.target as HTMLSelectElement).value }
function resolvedSkuLabel(order:Order,item:Item){ return item.is_gift ? (chosenGift(item,order)?.fulfillment_sku ?? 'gift · 待解析') : (item.resolved_fulfillment_sku ?? item.sku ?? item.product_summary ?? '无 SKU') }
function selectedSources(order:Order){ const result:string[]=[]; for(const item of order.items){ const source=manualAssignments[order.id]?.[item.id]??''; if(source && !result.includes(source)) result.push(source) } return result }
function fulfillmentGroups(order:Order){
  const sources=selectedSources(order)
  return sources.map((source,index)=>({
    source,
    suffix:sources.length>1?String.fromCharCode(65+index):'',
    erpOrderId:order.erp_order_id ? `${order.erp_order_id}${sources.length>1?String.fromCharCode(65+index):''}` : '—',
    warehouse:sourceLabel(source),
    carrier:manualCarriers[order.id]?.[source] ?? defaultCarrier(order,source),
    items:order.items.filter(item=>manualAssignments[order.id]?.[item.id]===source),
  }))
}
function splitConstraintError(order:Order){
  const values=Object.values(manualAssignments[order.id]??{}).filter(Boolean)
  if(values.length!==order.items.length)return '请为每个 SKU 选择发货仓库'
  const sources=[...new Set(values)]
  const maxSources=order.allocation_decision.policy.max_sources || 26
  if(sources.length>maxSources)return `最多允许 ${maxSources} 个履约来源`
  for(const source of sources){ if(!(manualCarriers[order.id]?.[source] || defaultCarrier(order,source))) return `发货仓 ${sourceLabel(source)} 需要选择物流承运商` }
  for(const item of order.items){
    if(!item.is_gift)continue
    const source=manualAssignments[order.id][item.id]
    const selected=manualGiftMappings[order.id][item.id]
    if(!selected)return 'gift 必须选择实际履约礼品 SKU'
    const option=giftOptions(item,source).find(o=>String(o.mapping_id)===selected)
    if(!option)return '所选礼品 SKU 不属于当前发货仓'
    if(!option.can_fulfill)return '所选实际礼品 SKU 库存不足或库存未知'
  }
  return null
}
function matchesRecommendation(order:Order){
  const plan=order.allocation_decision.recommended_plan
  if(!plan || !order.allocation_decision.can_use_recommendation || hasGift(order))return false
  return order.items.every(item=>{
    const segment=recommendedSegment(order,item); if(!segment)return false
    const source=manualAssignments[order.id]?.[item.id]??''
    const carrier=carrierForItem(order,item)
    const recommendedCarrier=segment.carrier ?? defaultCarrier(order,segment.source_code)
    return canonicalWarehouseCode(source)===canonicalWarehouseCode(segment.source_code) && carrier===(recommendedCarrier??'')
  })
}
async function submitRecommended(){ const order=selectedOrder.value,decision=order?.allocation_decision; if(!order||!decision?.recommended_plan_key||!decision.can_use_recommendation||!props.canOperate)return; submitting.value=true; submitError.value=''; try{ const r=await fetch(`/logistics/orders/${order.id}/fulfillment-allocation/recommended`,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json',...csrfHeaders()},body:JSON.stringify({plan_key:decision.recommended_plan_key,decision_note:decisionNote.value})}); const b=await r.json().catch(()=>({})) as {message?:string;submitted_at?:string}; if(!r.ok)throw new Error(b.message??`提交失败（${r.status}）`); localSubmitted[order.id]={source:'system_recommendation',submittedAt:b.submitted_at??new Date().toISOString()}; closePlan() }catch(e){ submitError.value=e instanceof Error?e.message:'提交失败，请重试' }finally{submitting.value=false} }
async function submitManual(){ const order=selectedOrder.value; if(!order||!props.canOperate)return; const validation=splitConstraintError(order); if(validation){submitError.value=validation;return} const assignments=order.items.map(item=>{ const source=manualAssignments[order.id][item.id]; const carrier=manualCarriers[order.id]?.[source] || defaultCarrier(order,source); const route=order.carrier_routes?.[source]; return {order_item_id:item.id,source_code:source,gift_mapping_id:item.is_gift?Number(manualGiftMappings[order.id][item.id]):null,carrier,service:carrier===route?.carrier?route?.service??null:null} }); submitting.value=true; submitError.value=''; try{ const r=await fetch(`/logistics/orders/${order.id}/fulfillment-allocation/manual`,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json',...csrfHeaders()},body:JSON.stringify({assignments,decision_note:decisionNote.value})}); const b=await r.json().catch(()=>({})) as {message?:string;submitted_at?:string}; if(!r.ok)throw new Error(b.message??`提交失败（${r.status}）`); localSubmitted[order.id]={source:'manual',submittedAt:b.submitted_at??new Date().toISOString()}; closePlan() }catch(e){ submitError.value=e instanceof Error?e.message:'提交失败，请重试' }finally{submitting.value=false} }
async function submitPrimary(){ const order=selectedOrder.value; if(!order)return; if(matchesRecommendation(order)) await submitRecommended(); else await submitManual() }
</script>

<template>
  <Head title="待分仓 · BYL ERP" />
  <AuthenticatedLayout title="待分仓" :breadcrumbs="['BYL ERP','物流','待分仓']">
    <section class="allocation-page">
      <header class="page-heading">
        <div><div class="title-row"><h1>待分仓</h1><span class="version">V1.1</span></div><p>系统提供区域、库存、仓库与承运商建议；Vita 在一个弹窗内完成最终履约分配。</p></div>
        <div class="principle"><strong>System Assisted</strong><span>Human Confirmed</span></div>
      </header>
      <nav class="tabs">
        <button :class="{active:activeTab==='pending'}" @click="activeTab='pending'">全部 <span>{{ counts.pending }}</span></button>
        <button :class="{active:activeTab==='manual'}" @click="activeTab='manual'">需要人工判断 <span>{{ counts.manual }}</span></button>
        <button :class="{active:activeTab==='gift'}" @click="activeTab='gift'">礼品待确认 <span>{{ counts.gift }}</span></button>
        <button :class="{active:activeTab==='recommendation'}" @click="activeTab='recommendation'">系统推荐 <span>{{ counts.recommendation }}</span></button>
        <button :class="{active:activeTab==='processed'}" @click="activeTab='processed'">已处理 <span>{{ counts.processed }}</span></button>
      </nav>
      <div class="table-wrap"><table class="allocation-table"><thead><tr><th>#</th><th>订单号</th><th>SKU 摘要</th><th>收件信息摘要</th><th>美国区域 / 推荐仓</th><th>系统建议</th><th>状态</th><th class="sticky-action">操作</th></tr></thead><tbody>
        <tr v-for="(order,index) in filteredOrders" :key="order.id">
          <td>{{ index+1 }}</td>
          <td><Link v-if="order.order_url" class="order-link" :href="order.order_url">{{ order.erp_order_id ?? '—' }}</Link><span v-else>{{ order.erp_order_id ?? '—' }}</span></td>
          <td class="sku-list"><div v-for="item in order.items" :key="item.id"><strong>{{ item.sku ?? item.product_summary ?? '无 SKU' }} × {{ item.quantity }}</strong><span v-if="item.is_gift" :class="item.resolved_fulfillment_sku?'gift-done':'gift-pending'">{{ item.resolved_fulfillment_sku ? `→ ${item.resolved_fulfillment_sku}` : '待解析' }}</span></div></td>
          <td class="address-summary"><strong>{{ order.recipient_name ?? '—' }}</strong><span>{{ addressLine(order) || '—' }}</span><small v-if="order.phone">{{ order.phone }}</small></td>
          <td><template v-if="order.country==='US'"><span :class="['zone-chip',order.us_fulfillment_zone?.zone??'unknown']">{{ zoneShort(order) }}</span><div class="zone-priority">{{ zonePriorityLabel(order.us_fulfillment_zone) }}</div></template><span v-else>{{ order.country ?? '—' }}</span></td>
          <td><span v-if="order.allocation_decision.status==='system_recommendation'" class="plan-chip good">{{ order.allocation_decision.recommended_plan?.label ?? '系统推荐' }}</span><span v-else class="plan-chip warn">需要人工判断</span></td>
          <td><span v-if="processed(order)" class="status-chip done">已分仓</span><span v-else-if="hasGift(order)" class="status-chip gift">礼品待确认</span><span v-else-if="order.allocation_decision.status==='system_recommendation'" class="status-chip recommend">待确认</span><span v-else class="status-chip manual">数据不足</span></td>
          <td class="sticky-action"><button class="primary compact" @click="openPlan(order)">{{ processed(order)?'查看记录':'查看方案' }}</button></td>
        </tr>
        <tr v-if="!filteredOrders.length"><td colspan="8" class="empty">当前分类暂无订单</td></tr>
      </tbody></table></div>
      <footer class="page-foot">共 {{ props.orders.total }} 条候选订单 · 美国分仓：美国3世迈仓优先 → MorePlus 兜底；美东纽约仓；区域无法可靠识别时必须人工判断。</footer>
    </section>

    <Teleport to="body"><div v-if="selectedOrder" class="modal-backdrop" @click.self="closePlan"><section class="plan-modal" role="dialog" aria-modal="true">
      <header class="modal-header"><div><p class="eyebrow">FULFILLMENT PLAN</p><h2>履约方案详情</h2></div><button class="icon-button" @click="closePlan">×</button></header>

      <section v-if="!processed(selectedOrder)" class="primary-allocation">
        <div class="section-title"><div><h3>SKU 履约分配</h3><p>先确认发货仓，再确认物流承运商；系统不能判断的项目由 Vita 人工补齐。</p></div><span class="limit-chip">最多 {{ selectedOrder.allocation_decision.policy.max_sources }} 个履约来源</span></div>
        <div class="manual-table-wrap"><table><thead><tr><th>SKU</th><th>数量</th><th>库存参考</th><th>系统推荐 / 人工备选</th><th>选择发货仓</th><th>选择物流承运商</th><th>实际履约 SKU</th></tr></thead><tbody>
          <tr v-for="item in selectedOrder.items" :key="item.id">
            <td><strong>{{ item.sku??item.product_summary }}</strong><span v-if="item.is_gift" class="gift-tag">礼品</span></td>
            <td>{{ item.quantity }}</td>
            <td><div class="stock-list"><span v-for="warehouse in warehouseOptions" :key="warehouse.code" :class="item.is_gift?giftStockClass(item,warehouse):availabilityClass(item,warehouse)">{{ warehouse.name }}：{{ item.is_gift?giftStockLabel(item,warehouse):availabilityLabel(item,warehouse) }}</span></div></td>
            <td><div class="warehouse-advice" :class="warehouseAdvice(selectedOrder,item).kind"><strong>{{ warehouseAdvice(selectedOrder,item).label }}</strong><small>{{ warehouseAdvice(selectedOrder,item).detail }}</small></div></td>
            <td><select v-model="manualAssignments[selectedOrder.id][item.id]" @change="onWarehouseChanged(selectedOrder,item)"><option value="">请选择</option><option v-for="warehouse in warehouseOptions" :key="warehouse.code" :value="warehouse.code">{{ warehouse.name }}</option></select></td>
            <td><div class="carrier-cell"><select :value="carrierForItem(selectedOrder,item)" :disabled="!manualAssignments[selectedOrder.id][item.id]" @change="onCarrierChanged(selectedOrder,item,$event)"><option value="">请选择承运商</option><option v-for="carrier in props.carrierOptions" :key="carrier.value" :value="carrier.value">{{ carrier.label }}</option></select><small v-if="manualAssignments[selectedOrder.id][item.id] && carrierForItem(selectedOrder,item)" :class="carrierIsDefault(selectedOrder,item)?'auto-route':'manual-route'">{{ carrierIsDefault(selectedOrder,item)?'系统默认':'人工覆盖' }}</small></div></td>
            <td><template v-if="item.is_gift"><select v-model="manualGiftMappings[selectedOrder.id][item.id]" :disabled="!manualAssignments[selectedOrder.id][item.id]"><option value="">请选择实际礼品</option><option v-for="option in availableGiftOptions(item,manualAssignments[selectedOrder.id][item.id])" :key="option.mapping_id" :value="String(option.mapping_id)">{{ option.fulfillment_sku }} · {{ option.fulfillment_name }} · 有货 {{option.quantity_available}}</option></select><small v-if="chosenGift(item,selectedOrder)" class="resolved-note">{{ chosenGift(item,selectedOrder)?.fulfillment_sku }} · {{ sourceLabel(manualAssignments[selectedOrder.id][item.id]) }}</small><small v-else-if="manualAssignments[selectedOrder.id][item.id]&&giftEmptyLabel(item,manualAssignments[selectedOrder.id][item.id])" class="mapping-missing">{{giftEmptyLabel(item,manualAssignments[selectedOrder.id][item.id])}}</small></template><span v-else>—</span></td>
          </tr>
        </tbody></table></div>
        <div v-if="fulfillmentGroups(selectedOrder).length" class="group-preview"><div class="group-heading"><strong>当前将生成 {{ fulfillmentGroups(selectedOrder).length }} 个履约订单</strong><span>系统按发货仓自动归组，不需要 Vita 手工创建 A/B。</span></div><div class="group-grid"><article v-for="group in fulfillmentGroups(selectedOrder)" :key="group.source" class="group-card"><header><div><small>{{ group.suffix?`履约组 ${group.suffix}`:'单仓履约' }}</small><strong>{{ group.erpOrderId }}</strong></div><div class="group-route"><b>{{ group.warehouse }}</b><span>{{ carrierLabel(group.carrier) }}</span></div></header><div class="group-items"><span v-for="item in group.items" :key="item.id">{{ resolvedSkuLabel(selectedOrder,item) }} × {{ item.quantity }}</span></div></article></div></div>
        <p class="constraint">分仓规则：每个订单商品仍只归属一个履约来源，不拆数量；一个订单可以按实际仓库需要生成 A/B/C… 多个履约单，海外仓与中国工厂可组合。</p>
      </section>

      <section v-else class="processed-primary"><div class="record-banner"><div><strong>分仓已完成</strong><span>后续物流只处理已确定仓库、承运商与实际履约 SKU 的执行订单。</span></div><span class="status-chip done">{{ selectedOrder.fulfillment_allocation?.decision_source==='manual'?'人工确认':'采用系统推荐' }}</span></div><div class="segment-grid"><article v-for="child in selectedOrder.fulfillment_allocation?.plan_snapshot?.derived_orders??[]" :key="child.order_id" class="segment-card"><header><div><small>{{ child.derived?`衍生订单 ${child.suffix??''}`:'原订单直接履约' }}</small><h3>{{ child.erp_order_id }}</h3></div><div class="record-route"><span>{{ child.warehouse_code?sourceLabel(child.warehouse_code):(child.warehouse_name??'—') }}</span><strong>{{ carrierLabel(child.carrier) }}</strong></div></header><table><thead><tr><th>实际履约 SKU</th><th>来源</th><th>数量</th></tr></thead><tbody><tr v-for="item in child.items" :key="`${child.order_id}-${item.sku}`"><td><strong>{{ item.resolved_fulfillment_sku??item.sku }}</strong></td><td>{{ item.source_sku??item.sku }}</td><td>{{ item.quantity }}</td></tr></tbody></table></article></div></section>

      <div class="top-grid">
        <section class="info-card"><header><strong>订单信息</strong><span :class="selectedOrder.allocation_decision.status==='manual_review'?'mini-warn':'mini-good'">{{ selectedOrder.allocation_decision.status_label }}</span></header><dl><dt>订单号</dt><dd>{{ selectedOrder.erp_order_id }}</dd><dt>收件人</dt><dd>{{ selectedOrder.recipient_name ?? '—' }}</dd><dt>联系电话</dt><dd>{{ selectedOrder.phone ?? '—' }}</dd><dt>订单商品</dt><dd><span class="order-item-chip" v-for="item in selectedOrder.items" :key="item.id">{{ item.sku??item.product_summary }} × {{ item.quantity }}</span></dd><dt>收货地址</dt><dd>{{ addressLine(selectedOrder) || '—' }}</dd></dl></section>
        <section class="info-card"><header><strong>地址区域判定</strong><small>国家 + ZIP Code；州用于一致性校验</small></header><dl><dt>国家/地区</dt><dd>{{ selectedOrder.country ?? '—' }}</dd><dt>州 / 邮编</dt><dd>{{ selectedOrder.province_state ?? '—' }} · {{ selectedOrder.postal_code ?? '—' }}</dd><dt>所属区域</dt><dd><span :class="['zone-chip',selectedOrder.us_fulfillment_zone?.zone??'unknown']">{{ zoneShort(selectedOrder) }}</span></dd><dt>区域优先仓（不代表有货）</dt><dd>{{ zonePriorityLabel(selectedOrder.us_fulfillment_zone) }}</dd></dl></section>
        <section v-if="selectedOrder.country==='US'" class="map-card"><header><strong>地图区域划分</strong><small>美国物理美东 / 美西区域</small></header><svg viewBox="0 0 420 230" role="img" aria-label="美国美东美西分仓区域示意图"><defs><clipPath id="usClip"><path d="M30 70 L55 38 L95 43 L125 53 L165 46 L200 58 L235 53 L270 65 L305 61 L345 75 L380 98 L395 122 L378 143 L350 150 L330 169 L300 164 L280 185 L244 190 L215 175 L185 182 L152 171 L122 175 L96 159 L68 154 L52 135 L35 126 Z"/></clipPath></defs><g clip-path="url(#usClip)"><rect x="0" y="0" width="212" height="230" class="map-west"/><rect x="212" y="0" width="208" height="230" class="map-east"/></g><path d="M30 70 L55 38 L95 43 L125 53 L165 46 L200 58 L235 53 L270 65 L305 61 L345 75 L380 98 L395 122 L378 143 L350 150 L330 169 L300 164 L280 185 L244 190 L215 175 L185 182 L152 171 L122 175 L96 159 L68 154 L52 135 L35 126 Z" class="map-outline"/><line x1="212" y1="43" x2="212" y2="184" class="map-divider"/><text x="83" y="112" class="map-label">美西区</text><text x="293" y="112" class="map-label">美东区</text><g v-if="selectedOrder.us_fulfillment_zone?.status==='matched'" :transform="selectedOrder.us_fulfillment_zone?.zone==='west'?'translate(110 132)':'translate(310 132)'" class="map-pin"><circle r="12"/><text x="0" y="4" text-anchor="middle">●</text></g><g v-else class="map-unknown"><circle cx="212" cy="132" r="13"/><text x="212" y="137" text-anchor="middle">?</text></g></svg><div class="map-result"><strong>{{ selectedOrder.postal_code ?? 'ZIP 未知' }}</strong><span>{{ zoneShort(selectedOrder) }}</span></div></section>
      </div>

      <section class="system-explanation"><header><strong>系统推荐结果说明</strong><span>辅助信息，不替代 Vita 的最终判断</span></header><div class="explanation-grid"><div><h4>{{ selectedOrder.allocation_decision.status==='system_recommendation'?'推荐依据':'当前可确认的信息' }}</h4><ul><li v-if="selectedOrder.us_fulfillment_zone?.status==='matched'">收货地址识别为 {{ zoneShort(selectedOrder) }}，仓库优先级：{{ zonePriorityLabel(selectedOrder.us_fulfillment_zone) }}</li><li v-if="selectedOrder.allocation_decision.recommended_plan">系统候选方案：{{ selectedOrder.allocation_decision.recommended_plan.label }}</li><li v-if="selectedOrder.allocation_decision.estimated_cost!=null">估算成本：{{ money(selectedOrder.allocation_decision.estimated_cost,selectedOrder.allocation_decision.currency) }}</li><li>仓库确定后，固定路线承运商会自动带出；特殊情况可人工覆盖。</li></ul></div><div><h4>不能自动推荐 / 需要人工确认时</h4><ul><li v-for="reason in selectedOrder.allocation_decision.manual_reasons" :key="reason">{{ reason }}</li><li v-if="!selectedOrder.allocation_decision.manual_reasons.length">当前无额外阻断原因；提交前仍由 Vita 确认。</li></ul></div></div></section>

      <label v-if="!processed(selectedOrder)" class="note-field">运营备注（选填）<textarea v-model="decisionNote" rows="2" placeholder="客户特殊要求、临时仓库情况、人工调整原因…" maxlength="500"/><small>{{ decisionNote.length }}/500</small></label><div v-else-if="selectedOrder.fulfillment_allocation?.decision_note" class="record-note"><strong>运营备注</strong><span>{{ selectedOrder.fulfillment_allocation.decision_note }}</span></div>
      <p v-if="submitError" class="submit-error">{{ submitError }}</p>
      <footer class="modal-actions"><button class="secondary" :disabled="submitting" @click="closePlan">关闭</button><button v-if="!processed(selectedOrder)&&props.canOperate" class="primary green" :disabled="submitting" @click="submitPrimary">{{ submitting?'提交中…':(matchesRecommendation(selectedOrder)?'采用推荐并提交分仓':'确认人工分配并提交') }}</button><span v-else-if="processed(selectedOrder)" class="submitted-note">该订单分仓决策已提交</span></footer>
    </section></div></Teleport>
  </AuthenticatedLayout>
</template>

<style scoped>
.allocation-page{display:grid;gap:14px;padding:18px 22px 28px;color:#172033}.page-heading{display:flex;justify-content:space-between;gap:20px}.title-row{display:flex;align-items:center;gap:9px}.title-row h1{margin:0;font-size:26px;color:#111827}.version{padding:3px 7px;border-radius:5px;background:#dbeafe;color:#1d4ed8;font-weight:800;font-size:11px}.page-heading p{margin:5px 0 0;color:#5f6d80;font-size:13px}.principle{display:grid;gap:2px;padding:9px 13px;border:1px solid #dbe7f5;border-radius:9px;background:#f7fbff;text-align:right;font-size:12px}.principle span{color:#64748b}.tabs{display:flex;gap:6px;border-bottom:1px solid #e6ebf1}.tabs button{border:0;background:transparent;padding:10px 13px;color:#59677c;cursor:pointer;border-bottom:2px solid transparent}.tabs button span{color:#8a96a8;margin-left:4px}.tabs button.active{color:#1261d7;font-weight:750;border-bottom-color:#1d72f2}.table-wrap{overflow:auto;border:1px solid #e1e7ef;border-radius:9px;background:#fff}.allocation-table{width:100%;min-width:1260px;border-collapse:collapse;font-size:12px}.allocation-table th,.allocation-table td{padding:10px 12px;border-bottom:1px solid #edf0f4;text-align:left;vertical-align:top}.allocation-table th{background:#f5f8fb;color:#526076}.order-link{color:#1261d7;font-weight:750;text-decoration:none}.sku-list{min-width:160px}.sku-list>div{display:flex;align-items:center;gap:7px;margin-bottom:5px}.gift-pending,.gift-done{padding:2px 6px;border-radius:4px;font-size:10px;font-weight:750}.gift-pending{background:#fff0dd;color:#b46100}.gift-done{background:#e8f8ef;color:#11834f}.address-summary{min-width:300px;max-width:360px}.address-summary strong,.address-summary span,.address-summary small{display:block}.address-summary span{margin-top:4px;line-height:1.45;color:#475569}.address-summary small{margin-top:3px;color:#64748b}.zone-chip{display:inline-flex;padding:3px 8px;border-radius:6px;font-weight:750}.zone-chip.west{background:#fff0e4;color:#b45309}.zone-chip.east{background:#eaf2ff;color:#1d4ed8}.zone-chip.unknown{background:#f1f5f9;color:#64748b}.zone-priority{margin-top:5px;color:#475569;font-weight:650}.plan-chip,.status-chip{display:inline-flex;padding:3px 9px;border-radius:6px;font-weight:750}.plan-chip.good{background:#e8f8ef;color:#11834f}.plan-chip.warn{background:#fff3dd;color:#b8650b}.status-chip.recommend{background:#e8f1ff;color:#1764ce}.status-chip.manual{background:#fff1dc;color:#b86100}.status-chip.done{background:#e9f8ef;color:#16814f}.status-chip.gift{background:#fce7f3;color:#be185d}.sticky-action{position:sticky;right:0;z-index:2;background:#fff!important;box-shadow:-6px 0 8px rgba(24,39,75,.04)}th.sticky-action{z-index:3;background:#f5f8fb!important}.primary,.secondary{min-height:34px;padding:7px 15px;border-radius:7px;border:1px solid transparent;font-weight:750;font-size:12px;cursor:pointer}.primary{background:#126cf3;color:#fff}.primary.green{background:#087f5b}.primary.compact{min-height:29px;padding:4px 14px}.secondary{background:#fff;border-color:#d5dde8;color:#36445b}.empty{text-align:center!important;padding:28px!important;color:#8a96a8}.page-foot{font-size:11px;color:#7d899a}
.modal-backdrop{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:12px;background:rgba(17,28,45,.55)}.plan-modal{width:min(1320px,98vw);max-height:96vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 22px 70px rgba(15,23,42,.28);color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei","Segoe UI",sans-serif;font-size:13px}.modal-header{position:sticky;top:0;z-index:8;display:flex;justify-content:space-between;align-items:center;padding:15px 18px;border-bottom:1px solid #e7ebf1;background:#fff}.modal-header h2{margin:2px 0 0;font-size:21px;color:#111827}.eyebrow{margin:0;color:#087f5b;font-size:11px;font-weight:850;letter-spacing:.05em}.icon-button{border:0;background:transparent;color:#64748b;font-size:25px;cursor:pointer}
.primary-allocation,.processed-primary{margin:14px 18px;border:1px solid #dbe3ec;border-radius:9px;background:#fff;overflow:hidden}.section-title{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:11px 13px;border-bottom:1px solid #e6ebf1;background:#f8fafc}.section-title h3{margin:0;color:#111827;font-size:15px}.section-title p{margin:4px 0 0;color:#64748b}.limit-chip{padding:4px 8px;border-radius:6px;background:#fff1dc;color:#9a4f00;font-size:11px;font-weight:750}.manual-table-wrap{overflow:auto}.manual-table-wrap table{width:100%;min-width:1220px;border-collapse:collapse}.manual-table-wrap th,.manual-table-wrap td{padding:9px 10px;border-bottom:1px solid #edf1f5;text-align:left;vertical-align:top}.manual-table-wrap th{background:#f7f9fc;color:#475569}.manual-table-wrap select{min-width:150px;max-width:220px;padding:7px 9px;border:1px solid #aebdce;border-radius:6px;background:#fff;color:#111827}.gift-tag{display:inline-block;margin-left:6px;padding:2px 6px;border-radius:4px;background:#fce7f3;color:#be185d;font-size:10px;font-weight:750}.stock-list{display:flex;gap:5px;flex-wrap:wrap;max-width:430px}.stock-list span{padding:3px 7px;border-radius:4px;font-size:10px;font-weight:650}.stock-ok{background:#e6f8ed;color:#087b47}.stock-short{background:#ffe8e8;color:#b42c2c}.stock-unknown{background:#fff0d7;color:#8b4b00}.stock-neutral{background:#eaf1fb;color:#334e6f}.warehouse-advice{display:grid;gap:4px;max-width:240px}.warehouse-advice strong{width:max-content;max-width:100%;padding:4px 7px;border-radius:5px;font-size:11px}.warehouse-advice small{font-size:10px;line-height:1.4;color:#64748b}.warehouse-advice.recommended strong{background:#eaf7ef;color:#087b47}.warehouse-advice.manual strong{background:#fff0dc;color:#9a4f00}.warehouse-advice.none strong{background:#f1f5f9;color:#64748b}.carrier-cell{display:grid;gap:4px}.carrier-cell small{width:max-content;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:750}.auto-route{background:#e8f8ef;color:#087b47}.manual-route{background:#fff0dc;color:#a85a00}.resolved-note,.mapping-missing{display:block;margin-top:5px;font-size:10px}.resolved-note{color:#087b47}.mapping-missing{color:#b42318}.constraint{margin:8px 12px 11px;color:#8a4600;font-weight:650}.group-preview{margin:10px 12px;padding:10px;border:1px solid #dce6df;border-radius:8px;background:#f7fbf8}.group-heading{display:flex;justify-content:space-between;gap:14px;margin-bottom:8px}.group-heading span{color:#64748b;font-size:11px}.group-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:9px}.group-card{border:1px solid #dce5df;border-radius:7px;background:#fff;overflow:hidden}.group-card header{display:flex;justify-content:space-between;gap:12px;padding:9px 11px;background:#fbfdfb}.group-card header>div:first-child{display:grid;gap:2px}.group-card small{color:#64748b}.group-card strong{color:#111827}.group-route{display:grid;text-align:right;gap:2px}.group-route b{color:#087f5b}.group-route span{color:#475569;font-size:11px}.group-items{display:flex;flex-wrap:wrap;gap:6px;padding:9px 11px;border-top:1px solid #edf1ee}.group-items span{padding:3px 7px;border-radius:5px;background:#f1f5f9;color:#334155;font-size:11px;font-weight:650}
.top-grid{display:grid;grid-template-columns:1.15fr .9fr 1.2fr;gap:10px;margin:14px 18px}.info-card,.map-card{border:1px solid #dfe6ee;border-radius:9px;background:#fff;overflow:hidden}.info-card header,.map-card header{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;background:#f8fafc;border-bottom:1px solid #e8edf3}.info-card header small,.map-card header small{color:#64748b}.mini-good,.mini-warn{padding:2px 7px;border-radius:5px;font-size:10px;font-weight:750}.mini-good{background:#e7f8ef;color:#0d7f4b}.mini-warn{background:#fff0dc;color:#ad5c00}.info-card dl{display:grid;grid-template-columns:86px 1fr;gap:7px 9px;padding:11px 12px;margin:0}.info-card dt{color:#64748b}.info-card dd{margin:0;color:#111827;font-weight:650;line-height:1.45}.order-item-chip{display:inline-flex;margin:0 4px 4px 0;padding:2px 6px;border-radius:5px;background:#f1f5f9;font-size:11px}.map-card svg{display:block;width:100%;height:185px;padding:7px 8px 0}.map-west{fill:#ffd8b4}.map-east{fill:#dbeafe}.map-outline{fill:none;stroke:#64748b;stroke-width:2}.map-divider{stroke:#ef4444;stroke-width:3}.map-label{font-size:17px;font-weight:800;fill:#334155}.map-pin circle{fill:#0f766e}.map-pin text{font-size:12px;fill:#fff}.map-unknown circle{fill:#f59e0b}.map-unknown text{font-size:14px;font-weight:900;fill:#fff}.map-result{display:flex;justify-content:center;gap:10px;padding:7px 10px 11px;color:#334155}.map-result span{font-weight:750}
.system-explanation{margin:12px 18px;padding:0;border:1px solid #e4e8ed;border-radius:8px;background:#f4f6f8;color:#475569;overflow:hidden}.system-explanation>header{display:flex;justify-content:space-between;padding:10px 13px;border-bottom:1px solid #e2e6ea}.system-explanation>header strong{color:#334155}.system-explanation>header span{font-size:11px;color:#7b8794}.explanation-grid{display:grid;grid-template-columns:1fr 1fr}.explanation-grid>div{padding:10px 14px}.explanation-grid>div+div{border-left:1px solid #dde2e7}.explanation-grid h4{margin:0 0 5px;color:#475569;font-size:12px}.explanation-grid ul{margin:0;padding-left:18px;line-height:1.65}.explanation-grid li{color:#667281}
.record-banner{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:11px 13px;border-bottom:1px solid #dce7df;background:#eefaf3}.record-banner>div{display:grid;gap:4px}.segment-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;padding:12px}.segment-card{border:1px solid #dfe6ee;border-radius:8px;overflow:hidden}.segment-card>header{display:flex;justify-content:space-between;padding:10px 12px;background:#f8fafc}.segment-card h3{margin:2px 0 0;color:#175ebf;font-size:14px}.segment-card table{width:100%;border-collapse:collapse}.segment-card th,.segment-card td{padding:8px 10px;border-top:1px solid #e5e7eb;text-align:left}.record-route{display:grid;text-align:right;gap:3px}.record-route span{color:#334155}.record-route strong{color:#087f5b;font-size:11px}
.note-field{position:relative;display:grid;gap:6px;margin:13px 18px;color:#374151;font-weight:650}.note-field textarea{padding:9px 10px 22px;border:1px solid #b8c4d1;border-radius:7px;color:#111827;resize:vertical}.note-field>small{position:absolute;right:9px;bottom:6px;color:#64748b;font-weight:500}.record-note{display:grid;gap:5px;margin:13px 18px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc}.submit-error{margin:0 18px;padding:8px 10px;border-radius:6px;background:#fff0f0;color:#b42318}.modal-actions{position:sticky;bottom:0;z-index:8;display:flex;justify-content:flex-end;gap:9px;align-items:center;padding:13px 18px;border-top:1px solid #e7ebf1;background:#fff}.submitted-note{color:#16814f;font-weight:750}
@media(max-width:980px){.top-grid{grid-template-columns:1fr}.explanation-grid{grid-template-columns:1fr}.explanation-grid>div+div{border-left:0;border-top:1px solid #dde2e7}.map-card svg{height:150px}.plan-modal{width:100%}.modal-backdrop{padding:5px}.group-heading{display:grid}}
</style>
