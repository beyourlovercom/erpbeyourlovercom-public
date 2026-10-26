<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { publishGlobalFeedback } from '../../lib/globalFeedback'

const commonCurrencyOptions = [
  { value: 'USD', label: 'USD · 美元' },
  { value: 'CAD', label: 'CAD · 加拿大元' },
  { value: 'HKD', label: 'HKD · 港币' },
  { value: 'EUR', label: 'EUR · 欧元' },
  { value: 'CNY', label: 'CNY · 人民币' },
  { value: 'GBP', label: 'GBP · 英镑' },
  { value: 'AUD', label: 'AUD · 澳元' },
  { value: 'JPY', label: 'JPY · 日元' },
  { value: 'SGD', label: 'SGD · 新加坡元' },
]
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'

type Availability = { warehouse_id: number; warehouse_slug: string; warehouse_name: string; inventory_state: string; can_auto_allocate: boolean; quantity_on_hand: number | null; quantity_reserved: number | null; quantity_available: number | null }
type Warehouse = { id: number; slug: string; name: string; code: string; country_code: string | null; can_auto_allocate?: boolean }
type CarrierServiceOption = { id:number; key:string; value:string; label:string; name_en:string|null; name_zh:string|null }
type CarrierOption = { value:string; label:string; default_service_id:number|null; default_service_value:string|null; services:CarrierServiceOption[] }
type AllocationUi = { status: 'pending' | 'submitted' | 'locked'; can_operate: boolean; can_reallocate?: boolean; can_partial_reallocate?: boolean; logistics_started?: boolean; requires_full_flow: boolean; selectable_warehouse_ids: number[]; recommended_warehouse_ids: Record<number, number | null>; selected_warehouse_ids: Record<number, number | null>; recommended_plan_key: string | null; can_use_recommendation: boolean; full_allocation_url: string }

type OrderItem = {
  id: number
  product_id: number | null
  product_summary: string
  sku: string | null
  quantity: number
  unit_price: string
}

type FulfillmentSegment = {
  order_id: number
  erp_order_id: string | null
  suffix: string | null
  role: 'original' | 'replacement'
  role_label: string
  reason: string | null
  reason_label: string | null
  origin_fulfillment_id: number | null
  origin_erp_order_id: string | null
  can_reallocate?: boolean
  items: { id: number; sku: string | null; product_summary: string | null; quantity: number }[]
  shipment: {
    shipment_status: string
    status_label: string
    warehouse_id: number | null
    warehouse_name: string | null
    carrier: string | null
    service: string | null
    tracking_number: string | null
    shipped_at: string | null
    logistics_note?: string | null
  }
}

type FulfillmentProjection = {
  is_split: boolean
  aggregate_status: string
  aggregate_status_label: string
  segment_count: number
  original_segment_count: number
  replacement_count: number
  fulfilled_count: number
  has_exception: boolean
  original_logistics_started: boolean
  segments: FulfillmentSegment[]
}

type PackagingReference = {
  source: 'current_product_standard' | 'shipment_snapshot'
  source_label: string
  measurement_record_id: number | null
  packaging_method_key: string | null
  packaging_method_label: string | null
  packaging_length_cm: string
  packaging_width_cm: string
  packaging_height_cm: string
  packaged_weight_kg: string
  measured_at?: string | null
  captured_at?: string | null
}

type Order = {
  id: number
  erp_order_id?: string | null
  source_type: string
  source_label: string
  order_kind: string | null
  order_kind_label: string | null
  order_date: string
  order_status: string
  order_status_label: string
  order_note: string | null
  currency: string
  owner: { id: number; name: string } | null
  owner_label: string | null
  address: Record<string, string> | null
  items: OrderItem[]
  shipment: {
    shipment_status: string
    warehouse_id?: number | null
    warehouse_name?: string | null
    warehouse_selection_source?: string | null
    carrier: string | null
    service?: string | null
    carrier_selection_source?: string | null
    tracking_number: string | null
    shipped_at: string | null
    logistics_note?: string | null
  } | null
  manual_sync: { status: string; synced_at: string | null } | null
  fulfillment?: FulfillmentProjection | null
  operational_markers: string[]
  procurement_order: { id: number; procurement_number: string } | null
}

const props = withDefaults(defineProps<{
  order: Order
  inventoryAvailability?: Record<number, Availability[]>
  packagingReferences?: Record<number, PackagingReference>
  warehouses?: Warehouse[]
  warehouseRouting?: Record<number,{ carrier:string|null; service:string|null; carrier_service_id:number|null; automatic:boolean }>
  carrierOptions?: CarrierOption[]
  allocationUi?: AllocationUi
  audit: { action: string; actor_id: number | null; created_at: string; reason?: string }[]
  canMarkOperational?: boolean
  canEditOrder?: boolean
  canManageProductPackaging?: boolean
  canViewPackagingEvidence?: boolean
  canCreateProcurement?: boolean
  canCreateReplacement?: boolean
  canOperateLogistics?: boolean
  canViewProfit?: boolean

}>(), { canMarkOperational: false, canEditOrder: false, canManageProductPackaging: false, canViewPackagingEvidence: false, canCreateProcurement: false, canCreateReplacement: false, canOperateLogistics: false, canViewProfit: false })


const carrierOption = (name:string) => (props.carrierOptions ?? []).find(row => row.value === name)
const contextualDefaultService = (name:string, country?:string|null) => {
  const carrier = carrierOption(name)
  if (!carrier) return ''
  if (name === 'DHL' && String(country ?? '').toUpperCase() === 'DE') {
    return carrier.services.find(service => service.key === 'paket_domestic')?.value ?? carrier.default_service_value ?? ''
  }
  return carrier.default_service_value ?? ''
}
const shipmentServiceOptions = computed(() => carrierOption(shipmentEditor.value.carrier)?.services ?? [])
const routeForWarehouse = (warehouseId:number|null) => warehouseId ? props.warehouseRouting?.[warehouseId] : undefined
const applyShipmentWarehouseDefault = () => {
  const route = routeForWarehouse(shipmentEditor.value.warehouse_id)
  shipmentEditor.value.carrier = route?.carrier ?? ''
  shipmentEditor.value.service = route?.service ?? contextualDefaultService(shipmentEditor.value.carrier, props.order.address?.country)
}
const applyShipmentCarrierDefault = () => {
  shipmentEditor.value.service = contextualDefaultService(shipmentEditor.value.carrier, props.order.address?.country)
}

const isKol = computed(() => props.order.order_kind === 'kol_fulfillment')
const basePath = computed(() => isKol.value ? '/kol/orders' : '/customers/orders')

const breadcrumbs = computed(() => isKol.value
  ? [{ label: 'BYL ERP', href: '/dashboard' }, { label: '网红', href: '/kol' }, { label: '订单', href: '/kol/orders' }, props.order.erp_order_id ?? '—']
  : [{ label: 'BYL ERP', href: '/dashboard' }, { label: '客户', href: '/customers' }, { label: '订单', href: '/customers/orders' }, props.order.erp_order_id ?? '—']
)

type EditItem = {
  id: number | null
  product_id: number | null
  sku: string
  product_summary: string
  quantity: number
  unit_price: string
}

const makeFormData = () => ({
  order_date: props.order.order_date ?? '',
  order_note: props.order.order_note ?? '',
  currency: props.order.currency ?? 'USD',
  recipient_name: props.order.address?.recipient_name ?? '',
  phone: props.order.address?.phone ?? '',
  country: props.order.address?.country ?? '',
  province_state: props.order.address?.province_state ?? '',
  city: props.order.address?.city ?? '',
  address_line_1: props.order.address?.address_line_1 ?? '',
  address_line_2: props.order.address?.address_line_2 ?? '',
  postal_code: props.order.address?.postal_code ?? '',
  items: props.order.items.map(item => ({
    id: item.id,
    product_id: item.product_id,
    sku: item.sku ?? '',
    product_summary: item.product_summary ?? '',
    quantity: item.quantity,
    unit_price: item.unit_price,
  })) as EditItem[],
})

const form = useForm(makeFormData())

const currencyOptions = computed(() => {
  const current = form.currency || props.order.currency || 'USD'
  return commonCurrencyOptions.some(option => option.value === current)
    ? commonCurrencyOptions
    : [{ value: current, label: current }, ...commonCurrencyOptions]
})

type AmendmentPreview = {
  changed:boolean; can_save_direct:boolean; requires_replan:boolean; can_atomic_single_replan:boolean; expected_revision:string; no_writes:boolean;
  old_items_gross:string; new_items_gross:string; gross_delta:string; currency:string;
  rows:{order_item_id:number|null; operation:string; before:{sku:string|null;quantity:number}|null; after:{sku:string;quantity:number}|null}[];
  issues:string[];
  stock_by_warehouse:{warehouse_id:number; warehouse_name:string; warehouse_code:string; single_warehouse_candidate:boolean;
    details:{product_id:number;required:number;available:number|null;enough:boolean}[]}[];
  inventory_note:string;
}
const amendmentPreview = ref<AmendmentPreview|null>(null)
const amendmentPreviewLoading = ref(false)
const amendmentPreviewError = ref('')
const amendmentReplanWarehouse = ref('')
const amendmentReplanReason = ref('')
const amendmentReplanApproved = ref(false)
const amendmentReplanSaving = ref(false)
const commitAtomicReplan = async () => {
  const preview = amendmentPreview.value
  if (!preview?.can_atomic_single_replan || amendmentReplanSaving.value) return
  if (!amendmentReplanApproved.value || amendmentReplanReason.value.trim().length < 4 || !amendmentReplanWarehouse.value) {
    amendmentPreviewError.value = '请选择目标仓库、填写客户变更原因并勾选确认。'
    return
  }
  const target = preview.stock_by_warehouse.find(
    wh => wh.warehouse_code === amendmentReplanWarehouse.value && wh.single_warehouse_candidate,
  )
  if (!target) {
    amendmentPreviewError.value = '所选仓库没有可用库存或状态已失效，请重新预检。'
    return
  }
  amendmentReplanSaving.value = true
  amendmentPreviewError.value = ''
  publishGlobalFeedback({state:'RUNNING',title:'正在更新订单与库存预留',
    message:'订单商品、分仓与预留将作为一次事务提交；发生冲突会全部回滚。'})
  try {
    await axios.post(`${basePath.value}/${props.order.id}/items/replan`, {
      items:form.items.map(item => ({
        id:item.id, product_id:item.product_id, product_summary:item.product_summary,
        quantity:item.quantity, unit_price:item.unit_price,
      })),
      assignments:form.items.map((_,index) => ({
        item_index:index, source_code:target.warehouse_code,
      })),
      expected_revision:preview.expected_revision,
      reason:amendmentReplanReason.value.trim(),
    })
    publishGlobalFeedback({state:'SUCCESS',title:'单仓商品及库存重规划完成',
      message:'旧库存预留已释放、新库存预留已建立，物流仍待人工确认后发货。'})
    amendmentPreview.value = null
    amendmentReplanApproved.value = false
    editMode.value = false
    router.reload()
  } catch (error) {
    const payload = axios.isAxiosError(error)
      ? error.response?.data as {message?:string;errors?:Record<string,string[]>}|undefined : undefined
    amendmentPreviewError.value = Object.values(payload?.errors ?? {}).flat()[0]
      || payload?.message || '事务未完成，订单和旧库存预留保持不变，请重新预检。'
    publishGlobalFeedback({state:'BLOCKED',title:'库存重规划未成功',message:amendmentPreviewError.value,
      next:'请重新检查订单、库存和发货仓库；未完成的事务不会产生部分发货。'})
    amendmentPreview.value = null
  } finally {
    amendmentReplanSaving.value = false
  }
}
const previewItemAmendment = async () => {
  if (amendmentPreviewLoading.value) return
  amendmentPreviewLoading.value = true
  amendmentPreview.value = null
  amendmentPreviewError.value = ''
  amendmentReplanApproved.value = false
  amendmentReplanWarehouse.value = ''
  try {
    const response = await axios.post<AmendmentPreview>(
      `${basePath.value}/${props.order.id}/items/preview`,
      { items:form.items.map(item => ({
        id:item.id, product_id:item.product_id, product_summary:item.product_summary,
        quantity:item.quantity, unit_price:item.unit_price,
      })) },
    )
    amendmentPreview.value = response.data
    if (!response.data.can_save_direct && response.data.changed) {
      publishGlobalFeedback({
        state:'BLOCKED',title:'商品变更需要重新规划',
        message:response.data.issues[0] ?? '订单当前不允许直接修改商品。',
        next:'先处理库存、仓库或履约冲突；本次预检没有修改任何订单。',
      })
    } else {
      publishGlobalFeedback({
        state:'SUCCESS',title:'商品变更预检完成',
        message:response.data.changed ? '可按权限保存，然后重新确认发货仓库。' : '商品明细没有变化。',
      })
    }
  } catch (error) {
    const payload = axios.isAxiosError(error)
      ? error.response?.data as {message?:string;errors?:Record<string,string[]>}|undefined : undefined
    const problem = Object.values(payload?.errors ?? {}).flat()[0]
    amendmentPreviewError.value = problem || payload?.message || '预检失败，请检查产品 SKU、数量及权限后重试。'
    publishGlobalFeedback({
      state:'ERROR',title:'商品修改预检失败',message:amendmentPreviewError.value,
      next:'请在当前订单页修正错误，不需要重新打开页面。',
    })
  } finally {
    amendmentPreviewLoading.value = false
  }
}
const editMode = ref(false)

const enterEditMode = () => { editMode.value = true }
const addOrderItem = () => {
  form.items.push({ id: null, product_id: null, sku: '', product_summary: '', quantity: 1, unit_price: '0.00' })
  form.clearErrors('items')
}
const removeOrderItem = (index: number) => {
  if (form.items.length <= 1) return
  form.items.splice(index, 1)
  form.clearErrors('items')
}
const initialItemSnapshot = () => props.order.items.map(item => ({
  id: item.id, product_id: item.product_id, product_summary: item.product_summary ?? '',
  quantity: Number(item.quantity), unit_price: Number(item.unit_price).toFixed(2),
}))
const hasItemChanges = () => JSON.stringify(form.items.map(item => ({
  id: item.id, product_id: item.product_id, product_summary: item.product_summary ?? '',
  quantity: Number(item.quantity), unit_price: Number(item.unit_price).toFixed(2),
}))) !== JSON.stringify(initialItemSnapshot())

watch(() => JSON.stringify(form.items.map(item => [
  item.id,item.product_id,item.sku,item.product_summary,item.quantity,item.unit_price,
])), () => { amendmentPreview.value = null; amendmentPreviewError.value = '' })

const cancelEdit = () => {
  form.reset()
  Object.assign(form, makeFormData())
  form.clearErrors()
  amendmentPreview.value = null
  amendmentPreviewError.value = ''
  amendmentReplanApproved.value = false
  editMode.value = false
}

const submit = () => {
  const itemsChanged = hasItemChanges()
  if (itemsChanged && !amendmentPreview.value) {
    publishGlobalFeedback({state:'BLOCKED',title:'请先检查商品变更',
      message:'保存前请执行 SKU、库存和发货仓库影响预检。'})
    return
  }
  if (itemsChanged && !amendmentPreview.value?.can_save_direct) {
    publishGlobalFeedback({state:'BLOCKED',title:'订单尚不能直接变更',
      message:amendmentPreview.value?.issues[0] ?? '当前商品关联的分仓或库存状态尚未完成审核。'})
    return
  }
  form.transform(data => {
    if (itemsChanged) return data
    const { items: _unchangedItems, ...otherFields } = data
    return otherFields
  }).patch(`${basePath.value}/${props.order.id}`, {
    preserveScroll: true,
    onSuccess: () => { editMode.value = false },
    onError: errors => {
      const reason = errors.items || Object.values(errors).find(Boolean)
      if (reason) publishGlobalFeedback({
        state:'BLOCKED', title:'订单修改未保存', message:String(reason),
        next:'请根据订单商品区域的红色提示修正后重新提交，已填写内容不会丢失。',
      })
    },
  })
}

const operationalMarkerOptions = [
  { key: 'exception', label: '异常' },
  { key: 'in_transit', label: '运输中' },
  { key: 'return', label: '退货' },
  { key: 'stockout', label: '缺货' },
]
const markerActive = (key: string) => props.order.operational_markers?.includes(key) ?? false
const toggleMarker = (key: string) => router.post(`/orders/${props.order.id}/operational-markers/${key}`, { active: !markerActive(key) }, { preserveScroll: true })

const shipmentStatusLabel = computed(() => {
  const s = props.order.shipment?.shipment_status
  if (s === 'shipped') return '已发货'
  if (s === 'completed') return '已完结'
  if (s === 'exception') return '异常'
  if (s === 'returned') return '已退回'
  return '待发货'
})

const isSplitFulfillment = computed(() => props.order.fulfillment?.is_split ?? false)
const hasFulfillmentTable = computed(() => Boolean(isSplitFulfillment.value || (props.order.fulfillment?.replacement_count ?? 0) > 0))
const fulfillmentAggregateLabel = computed(() => props.order.fulfillment?.aggregate_status_label ?? shipmentStatusLabel.value)
const fulfillmentBadgeLabel = computed(() => {
  const fulfillment = props.order.fulfillment
  if (!fulfillment) return ''
  if (fulfillment.replacement_count > 0 && !fulfillment.is_split) return `含售后补发 · ${fulfillment.segment_count} 个履约单`
  if (fulfillment.replacement_count > 0) return `已拆分 · ${fulfillment.original_segment_count} 原始 + ${fulfillment.replacement_count} 补发`
  return `已拆分 · ${fulfillment.segment_count} 个履约单`
})
const fulfillmentProgressLabel = computed(() => {
  const fulfillment = props.order.fulfillment
  if (!fulfillment) return ''
  const original = `${fulfillment.original_segment_count} 个原始履约 · ${fulfillment.fulfilled_count}/${fulfillment.original_segment_count} 已进入物流`
  return fulfillment.replacement_count > 0 ? `${original} · 售后补发 ${fulfillment.replacement_count}` : original
})

const fulfillmentSegments = computed(() => props.order.fulfillment?.segments ?? [])
const pendingFulfillmentSegments = computed(() => fulfillmentSegments.value.filter(segment => segment.shipment.shipment_status === 'pending'))
const reopenFulfillmentSegments = computed(() => fulfillmentSegments.value.filter(segment => segment.shipment.shipment_status !== 'pending'))
const singleShipmentPending = computed(() => !['shipped', 'completed'].includes(props.order.shipment?.shipment_status ?? 'pending'))
const reopenFulfillment = (orderId: number) => router.post(`/logistics/orders/${orderId}/reopen`, { logistics_note: '订单详情重开发货' }, { preserveScroll: true })

const reallocationOpen = ref(false)
const allocationEditing = computed(() => Boolean(
  props.allocationUi?.status === 'pending'
  || (props.allocationUi?.status === 'submitted' && props.allocationUi?.can_reallocate && reallocationOpen.value),
))
const showAllocationInventory = computed(() => {
  if (!props.allocationUi) return !isSplitFulfillment.value
  return allocationEditing.value
})

const allocationSelections = ref<Record<number, number | null>>(Object.fromEntries(props.order.items.map(item => [item.id, props.allocationUi?.selected_warehouse_ids?.[item.id] ?? props.allocationUi?.recommended_warehouse_ids?.[item.id] ?? null])))
const allocationSubmitting = ref(false)
const allocationSelectable = (warehouseId: number) => props.allocationUi?.selectable_warehouse_ids?.includes(warehouseId) ?? false
const allocationSelected = (itemId: number) => allocationSelections.value[itemId] ?? null
const allocationRecommended = (itemId: number, warehouseId: number) => props.allocationUi?.recommended_warehouse_ids?.[itemId] === warehouseId
const selectAllocationWarehouse = (itemId: number, warehouseId: number) => { allocationSelections.value = { ...allocationSelections.value, [itemId]: warehouseId } }
const allocationSelectedCount = computed(() => props.order.items.filter(item => allocationSelected(item.id) !== null).length)
const allocationReady = computed(() => props.order.items.length > 0 && allocationSelectedCount.value === props.order.items.length)
const allocationMatchesRecommendation = computed(() => Boolean(props.allocationUi?.can_use_recommendation && props.allocationUi?.recommended_plan_key) && props.order.items.every(item => {
  const recommended = props.allocationUi?.recommended_warehouse_ids?.[item.id] ?? null
  return recommended !== null && allocationSelected(item.id) === recommended
}))
const submitAllocation = () => {
  const canSubmit = Boolean(props.allocationUi?.can_operate || (props.allocationUi?.can_reallocate && reallocationOpen.value))
  if (!canSubmit || props.allocationUi?.requires_full_flow || !allocationReady.value || allocationSubmitting.value) return
  const allocationUi = props.allocationUi
  if (!allocationUi) return
  allocationSubmitting.value = true
  const options = { preserveScroll: true, onFinish: () => { allocationSubmitting.value = false } }
  if (allocationMatchesRecommendation.value && allocationUi.recommended_plan_key) {
    router.post(`/logistics/orders/${props.order.id}/fulfillment-allocation/recommended`, { plan_key: allocationUi.recommended_plan_key, decision_note: null }, options)
    return
  }
  router.post(`/logistics/orders/${props.order.id}/fulfillment-allocation/quick`, { assignments: props.order.items.map(item => ({ order_item_id: item.id, warehouse_id: allocationSelected(item.id) })) }, options)
}


const partialReallocationOpen = ref(false)
const partialReallocationSegment = ref<FulfillmentSegment | null>(null)
const partialReallocationWarehouseId = ref<number | null>(null)
const partialReallocationNote = ref('')
const partialReallocationSaving = ref(false)
const partialReallocationWarehouses = computed(() => (props.warehouses ?? []).filter(warehouse => props.allocationUi?.selectable_warehouse_ids?.includes(warehouse.id)))
const openPartialReallocation = (segment: FulfillmentSegment) => {
  partialReallocationSegment.value = segment
  partialReallocationWarehouseId.value = segment.shipment.warehouse_id
  partialReallocationNote.value = ''
  partialReallocationOpen.value = true
}
const closePartialReallocation = () => {
  if (partialReallocationSaving.value) return
  partialReallocationOpen.value = false
  partialReallocationSegment.value = null
}
const submitPartialReallocation = () => {
  const segment = partialReallocationSegment.value
  const warehouseId = partialReallocationWarehouseId.value
  if (!segment || !warehouseId || warehouseId === segment.shipment.warehouse_id || partialReallocationSaving.value) return
  partialReallocationSaving.value = true
  router.post(`/logistics/orders/${segment.order_id}/reallocate-pending-fulfillment`, {
    warehouse_id: warehouseId,
    note: partialReallocationNote.value || null,
  }, {
    preserveScroll: true,
    onSuccess: () => { partialReallocationOpen.value = false; partialReallocationSegment.value = null },
    onFinish: () => { partialReallocationSaving.value = false },
  })
}

type ShipmentEditorDraft = {
  warehouse_id: number | null
  carrier: string
  service: string
  tracking_number: string
  shipped_at: string
  logistics_note: string
}
const shipmentEditorOpen = ref(false)
const shipmentEditorOrderId = ref<number | null>(null)
const shipmentEditorLabel = ref('')
const shipmentEditorSaving = ref(false)
const shipmentEditorError = ref('')
const localDateTimeNow = () => {
  const now = new Date()
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000)
  return local.toISOString().slice(0, 16)
}
const shipmentEditor = ref<ShipmentEditorDraft>({ warehouse_id: null, carrier: '', service: '', tracking_number: '', shipped_at: localDateTimeNow(), logistics_note: '' })
const openShipmentEditor = (target: {
  order_id: number
  suffix?: string | null
  erp_order_id?: string | null
  shipment?: { warehouse_id?: number | null; carrier?: string | null; service?: string | null; tracking_number?: string | null; shipped_at?: string | null; logistics_note?: string | null }
}) => {
  shipmentEditorOrderId.value = target.order_id
  shipmentEditorLabel.value = target.suffix ? `${target.suffix} · ${target.erp_order_id ?? ''}` : (target.erp_order_id ?? props.order.erp_order_id ?? '履约')
  const shippedAt = target.shipment?.shipped_at ? String(target.shipment.shipped_at).slice(0, 16) : localDateTimeNow()
  const initialWarehouseId = target.shipment?.warehouse_id ?? null
  const automaticRoute = routeForWarehouse(initialWarehouseId)
  const initialCarrier = target.shipment?.carrier ?? automaticRoute?.carrier ?? ''
  shipmentEditor.value = {
    warehouse_id: initialWarehouseId,
    carrier: initialCarrier,
    service: target.shipment?.service ?? automaticRoute?.service ?? contextualDefaultService(initialCarrier, props.order.address?.country),
    tracking_number: target.shipment?.tracking_number ?? '',
    shipped_at: shippedAt,
    logistics_note: target.shipment?.logistics_note ?? '',
  }
  shipmentEditorError.value = ''
  shipmentEditorOpen.value = true
}
const closeShipmentEditor = () => { if (!shipmentEditorSaving.value) shipmentEditorOpen.value = false }
const saveShipmentDraft = () => {
  if (!props.canOperateLogistics || !shipmentEditorOrderId.value || shipmentEditorSaving.value) return
  shipmentEditorSaving.value = true
  shipmentEditorError.value = ''
  router.post(`/logistics/orders/${shipmentEditorOrderId.value}/draft`, shipmentEditor.value, {
    preserveScroll: true,
    onError: () => { shipmentEditorError.value = '保存草稿失败，请检查物流信息。' },
    onFinish: () => { shipmentEditorSaving.value = false },
  })
}
const confirmShipment = () => {
  if (!props.canOperateLogistics || !shipmentEditorOrderId.value || shipmentEditorSaving.value) return
  const draft = shipmentEditor.value
  if (!draft.warehouse_id) { shipmentEditorError.value = '请选择发货仓库'; return }
  if (!draft.carrier.trim()) { shipmentEditorError.value = '请选择物流商'; return }
  if (!draft.service.trim()) { shipmentEditorError.value = '请选择运输 / 服务类型'; return }
  if (!draft.shipped_at) { shipmentEditorError.value = '请选择发货时间'; return }
  shipmentEditorSaving.value = true
  shipmentEditorError.value = ''
  router.post(`/logistics/orders/${shipmentEditorOrderId.value}/ship`, draft, {
    preserveScroll: true,
    onSuccess: () => { shipmentEditorOpen.value = false },
    onError: () => { shipmentEditorError.value = '确认发货失败，请检查物流信息。' },
    onFinish: () => { shipmentEditorSaving.value = false },
  })
}

const replacementOpen = ref(false)
const replacementSubmitting = ref(false)
const replacementReason = ref('lost')
const replacementOriginId = ref<number | null>(null)
const replacementWarehouseId = ref<number | null>(null)
const replacementNote = ref('')
const replacementAddressOverride = ref(false)
const replacementAddress = ref({
  recipient_name: props.order.address?.recipient_name ?? '',
  phone: props.order.address?.phone ?? '',
  country: props.order.address?.country ?? '',
  province_state: props.order.address?.province_state ?? '',
  city: props.order.address?.city ?? '',
  address_line_1: props.order.address?.address_line_1 ?? '',
  address_line_2: props.order.address?.address_line_2 ?? '',
  postal_code: props.order.address?.postal_code ?? '',
})
const replacementQuantities = ref<Record<number, number>>(Object.fromEntries(props.order.items.map(item => [item.id, 0])))
const replacementOriginalSegments = computed(() => (props.order.fulfillment?.segments ?? []).filter(segment => segment.role === 'original' && segment.suffix))
const replacementSelectedItems = computed(() => props.order.items
  .map(item => ({ order_item_id: item.id, quantity: Number(replacementQuantities.value[item.id] ?? 0) }))
  .filter(item => item.quantity > 0))
const replacementReady = computed(() => replacementSelectedItems.value.length > 0 && Boolean(replacementWarehouseId.value) && Boolean(replacementReason.value))
const closeReplacement = () => { replacementOpen.value = false }
const submitReplacement = () => {
  if (!props.canCreateReplacement || !replacementReady.value || replacementSubmitting.value) return
  replacementSubmitting.value = true
  router.post(`/logistics/orders/${props.order.id}/replacement-fulfillments`, {
    reason: replacementReason.value,
    origin_fulfillment_id: replacementOriginId.value,
    warehouse_id: replacementWarehouseId.value,
    note: replacementNote.value || null,
    items: replacementSelectedItems.value,
    address: replacementAddressOverride.value ? replacementAddress.value : null,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      replacementOpen.value = false
      replacementNote.value = ''
      replacementOriginId.value = null
      replacementWarehouseId.value = null
      replacementQuantities.value = Object.fromEntries(props.order.items.map(item => [item.id, 0]))
    },
    onFinish: () => { replacementSubmitting.value = false },
  })
}

</script>

<template>
  <Head :title="`${order.erp_order_id ?? '—'} · BYL ERP`" />
  <AuthenticatedLayout :title="`订单 ${order.erp_order_id ?? '—'}`" :breadcrumbs="breadcrumbs">
    <div class="erp-form-page">
      <div class="erp-form-content">

        <div class="erp-form-header-row">
          <div>
            <p class="ord-kicker">{{ order.order_kind_label }}</p>
            <h1 class="erp-form-title">{{ order.erp_order_id ?? '—' }}</h1>
            <p class="erp-form-subtitle">查看订单事实；进入编辑模式后可维护允许修改的订单字段。</p>
          </div>
          <div class="erp-form-header-actions page-header-actions">
            <button v-if="editMode" type="button" class="erp-btn erp-btn-secondary" @click="cancelEdit">取消编辑</button>
            <a :href="basePath" class="erp-btn erp-btn-secondary">返回列表</a>
            <a v-if="canViewProfit && !isKol" :href="`/accounting/order-costs?search=${encodeURIComponent(order.erp_order_id ?? '')}`" class="erp-btn erp-btn-secondary">查看订单利润</a>
            <a v-if="canCreateProcurement && !order.procurement_order" :href="`/purchase/orders/from-order/${order.id}/create`" class="erp-btn erp-btn-primary">创建采购订单</a>
            <a v-if="order.procurement_order" :href="`/purchase/orders/${order.procurement_order.id}`" class="erp-btn erp-btn-secondary">查看采购订单 · {{ order.procurement_order.procurement_number }}</a>
            <button v-if="!editMode && canEditOrder" type="button" class="erp-btn erp-btn-primary" @click="enterEditMode">编辑订单</button>
            <button v-else type="button" class="erp-btn erp-btn-primary" :disabled="form.processing" @click="submit">保存订单</button>
          </div>
        </div>

        <!-- Identity strip -->
        <div class="ord-identity-strip">
          <div class="ord-identity-main">
            <span class="ord-identity-number">{{ order.erp_order_id ?? '—' }}</span>
            <span class="ord-identity-badge">{{ order.order_kind_label }}</span>
            <span class="ord-identity-badge ord-badge-muted">{{ order.source_label }}</span>
            <span class="ord-identity-badge ord-badge-status">{{ order.order_status_label }}</span>
            <span class="ord-identity-owner">负责人：{{ order.owner?.name ?? '—' }}</span>
          </div>

        </div>

        <!-- Card 1: 履约状态 (always read-only) -->
        <div class="erp-form-card">

          <div class="ord-fulfillment-heading">

            <div class="ord-fulfillment-title-row">
              <h2 class="erp-form-card-title">履约状态</h2>
              <span v-if="hasFulfillmentTable" class="ord-split-badge">{{ fulfillmentBadgeLabel }}</span>
            </div>
            <div class="ord-fulfillment-actions" data-testid="fulfillment-actions">
              <template v-if="hasFulfillmentTable">
                <button v-for="segment in pendingFulfillmentSegments" :key="`ship-${segment.order_id}`" type="button" class="erp-btn erp-btn-primary ord-fulfillment-cta" @click="openShipmentEditor(segment)">{{ segment.suffix ? `${segment.suffix} · ` : '' }}{{ canOperateLogistics ? '履约发货' : '查看履约' }}</button>
                <button v-for="segment in reopenFulfillmentSegments" v-if="canOperateLogistics" :key="`reopen-${segment.order_id}`" type="button" class="erp-btn erp-btn-secondary ord-fulfillment-cta" @click="reopenFulfillment(segment.order_id)">{{ segment.suffix ? `${segment.suffix} · ` : '' }}重开发货</button>
              </template>
              <template v-else>
                <button v-if="singleShipmentPending" type="button" class="erp-btn erp-btn-primary ord-fulfillment-cta" @click="openShipmentEditor({ order_id: order.id, erp_order_id: order.erp_order_id, shipment: order.shipment ?? undefined })">{{ canOperateLogistics ? '履约发货' : '查看履约' }}</button>
                <button v-else-if="canOperateLogistics" type="button" class="erp-btn erp-btn-secondary ord-fulfillment-cta" @click="reopenFulfillment(order.id)">重开发货</button>
              </template>
              <button v-if="canCreateReplacement" type="button" class="erp-btn erp-btn-secondary ord-fulfillment-cta" @click="replacementOpen = true">+ 新增补发</button>
            </div>

          </div>

          <template v-if="hasFulfillmentTable">
            <div class="ord-fulfillment-summary">
              <div>
                <strong>{{ fulfillmentAggregateLabel }}</strong>
                <span>{{ fulfillmentProgressLabel }}</span>
              </div>
              <span v-if="order.fulfillment?.has_exception" class="ord-fulfillment-alert">存在异常履约</span>
              <small>网站同步：{{ order.manual_sync?.status ?? '未进入队列' }}</small>
            </div>
            <div class="ord-fulfillment-table-wrap">
              <table class="ord-fulfillment-table">
                <thead>
                  <tr>
                    <th>履约</th>
                    <th>商品</th>
                    <th>仓库</th>
                    <th>状态</th>
                    <th>承运商 / 渠道</th>
                    <th>追踪号</th>
                    <th>操作</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="segment in order.fulfillment?.segments ?? []" :key="segment.order_id">
                    <td>
                      <div class="ord-fulfillment-segment-id" :class="{ replacement: segment.role === 'replacement' }">
                        <strong>{{ segment.suffix ?? '—' }}</strong>
                        <small>{{ segment.erp_order_id ?? '—' }}</small>
                        <span class="ord-segment-role" :class="segment.role">{{ segment.role_label }}<template v-if="segment.reason_label"> · {{ segment.reason_label }}</template></span>
                        <small v-if="segment.origin_erp_order_id">关联 {{ segment.origin_erp_order_id }}</small>
                      </div>
                    </td>
                    <td>
                      <div class="ord-fulfillment-items">
                        <div v-for="item in segment.items" :key="item.id">
                          <strong>{{ item.sku || item.product_summary || '未绑定 SKU' }}</strong>
                          <span>× {{ item.quantity }}</span>
                        </div>
                      </div>
                    </td>
                    <td>{{ segment.shipment.warehouse_name ?? '未选择' }}</td>
                    <td><span class="ord-segment-status" :class="`status-${segment.shipment.shipment_status}`">{{ segment.shipment.status_label }}</span></td>
                    <td>
                      <div class="ord-carrier-service">
                        <strong>{{ segment.shipment.carrier ?? '—' }}</strong>
                        <small>{{ segment.shipment.service ?? '—' }}</small>
                      </div>
                    </td>
                    <td><span class="ord-tracking-number">{{ segment.shipment.tracking_number ?? '—' }}</span></td>
                    <td>
                      <div class="ord-fulfillment-row-actions">
                        <button v-if="allocationUi?.can_partial_reallocate && segment.can_reallocate" type="button" class="erp-btn erp-btn-secondary ord-ship-action" @click="openPartialReallocation(segment)">调整分仓</button>
                        <span v-else>—</span>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <template v-else>
            <div class="ord-fulfillment-grid">
              <div class="ord-fulfillment-item">
                <small class="ord-fulfillment-label">发货状态</small>
                <strong class="ord-fulfillment-value">{{ shipmentStatusLabel }}</strong>
              </div>
              <div class="ord-fulfillment-item">
                <small class="ord-fulfillment-label">发货仓库</small>
                <strong class="ord-fulfillment-value">{{ order.shipment?.warehouse_name ?? '未选择' }}</strong>
              </div>
              <div class="ord-fulfillment-item">
                <small class="ord-fulfillment-label">承运商</small>
                <strong class="ord-fulfillment-value">{{ order.shipment?.carrier ?? '—' }}</strong>
              </div>
              <div class="ord-fulfillment-item">
                <small class="ord-fulfillment-label">运输 / 服务类型</small>
                <strong class="ord-fulfillment-value">{{ order.shipment?.service ?? '—' }}</strong>
              </div>
              <div class="ord-fulfillment-item">
                <small class="ord-fulfillment-label">追踪号</small>
                <strong class="ord-fulfillment-value">{{ order.shipment?.tracking_number ?? '—' }}</strong>
              </div>
              <div class="ord-fulfillment-item">
                <small class="ord-fulfillment-label">网站同步</small>
                <strong class="ord-fulfillment-value">{{ order.manual_sync?.status ?? '未进入队列' }}</strong>
              </div>
            </div>


          </template>
        </div>

        <!-- Operational markers: explicit human marks, not a second Order status -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">辅助标记</h2>
          <p class="ord-inventory-help">用于异常、运输中、退货、缺货等辅助业务视图，不是发货入口。所有发货、重发、补发操作统一在上方“履约状态”处理；这里仅保留辅助业务标记。</p>
          <div class="ord-marker-row">
            <button v-for="marker in operationalMarkerOptions" :key="marker.key" type="button" class="erp-btn erp-btn-secondary ord-marker" :class="{ active: markerActive(marker.key) }" :disabled="!canMarkOperational" @click="toggleMarker(marker.key)">{{ markerActive(marker.key) ? '✓ ' : '' }}{{ marker.label }}</button>
          </div>
        </div>

        <div v-if="allocationUi?.status === 'submitted' && allocationUi.can_reallocate && !reallocationOpen" class="ord-allocation-reopen-bar">
          <div>
            <strong>分仓已提交 · 尚未进入实际物流</strong>
            <span>当前方案仍可调整；重新分仓会重建尚未发货的履约单，不会新增商业订单。</span>
          </div>
          <button type="button" class="erp-btn erp-btn-secondary" @click="reallocationOpen = true">重新分仓</button>
        </div>

        <!-- Inventory distribution: planning or explicitly reopened re-allocation only. -->
        <template v-if="showAllocationInventory">
        <div v-for="item in order.items" :key="`inventory-${item.id}`" class="erp-form-card ord-inventory-card">
          <h2 class="erp-form-card-title">{{ item.sku || '未绑定 SKU' }} 库存分布</h2>
          <div class="ord-inventory-table-wrap">
            <table class="ord-inventory-table">
              <thead>
                <tr>
                  <th>仓库</th>
                  <th>SKU</th>
                  <th>是否有货</th>
                  <th>可用库存</th>
                  <th>QOH</th>
                  <th>Reserved</th>
                  <th>备注</th>
                  <th v-if="allocationUi">分仓</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in inventoryAvailability?.[item.product_id ?? 0] ?? []" :key="row.warehouse_id">
                  <td class="ord-inventory-warehouse-name">{{ row.warehouse_name }}</td>
                  <td class="ord-inventory-sku-value">{{ item.sku || '—' }}</td>
                  <td>
                    <span class="ord-stock-status" :class="row.quantity_available === null ? 'unknown' : row.quantity_available > 0 ? 'in-stock' : 'out-of-stock'">
                      <span class="ord-stock-status-icon">{{ row.quantity_available === null ? '?' : row.quantity_available > 0 ? '✓' : '×' }}</span>
                      {{ row.quantity_available === null ? '未知' : row.quantity_available > 0 ? '有货' : '无货' }}
                    </span>
                  </td>
                  <td>{{ row.quantity_available ?? '—' }}</td>
                  <td>{{ row.quantity_on_hand ?? '—' }}</td>
                  <td>{{ row.quantity_reserved ?? '—' }}</td>
                  <td class="ord-inventory-note">{{ row.quantity_available === null ? '未建立 SKU 权威库存' : '—' }}</td>
                  <td v-if="allocationUi" class="ord-allocation-cell">
                    <label v-if="allocationEditing && !allocationUi.requires_full_flow && allocationSelectable(row.warehouse_id)" class="ord-allocation-choice" :class="{ selected: allocationSelected(item.id) === row.warehouse_id }">
                      <input type="radio" :name="`allocation-${item.id}`" :checked="allocationSelected(item.id) === row.warehouse_id" @change="selectAllocationWarehouse(item.id, row.warehouse_id)">
                      <span>{{ allocationSelected(item.id) === row.warehouse_id ? '已选' : '选择' }}</span>
                      <small v-if="allocationRecommended(item.id, row.warehouse_id)">推荐</small>
                    </label>
                    <span v-else-if="allocationUi.status === 'submitted' && allocationUi.selected_warehouse_ids?.[item.id] === row.warehouse_id" class="ord-allocation-fixed">✓ 已选仓库</span>
                    <span v-else-if="allocationRecommended(item.id, row.warehouse_id)" class="ord-allocation-recommended">推荐</span>
                    <span v-else>—</span>
                  </td>
                </tr>
                <tr v-if="(inventoryAvailability?.[item.product_id ?? 0] ?? []).length === 0">
                  <td :colspan="allocationUi ? 8 : 7" class="ord-inventory-empty">暂无仓库库存数据</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="allocationUi?.status === 'pending' || (allocationUi?.can_reallocate && reallocationOpen)" class="ord-allocation-action-bar">
          <div>
            <strong>{{ allocationUi?.status === 'submitted' ? '重新分仓' : '待分仓' }} · {{ allocationSelectedCount }}/{{ order.items.length }} SKU 已选择</strong>
            <span v-if="allocationUi.requires_full_flow">该订单包含 gift，需要选择实际履约 SKU。</span>
            <span v-else>库存表即仓库选择器；提交后这里变为只读“已选仓库”。</span>
          </div>
          <div class="ord-allocation-actions">
            <button v-if="allocationUi?.status === 'submitted'" type="button" class="erp-btn erp-btn-secondary" @click="reallocationOpen = false">取消调整</button>
            <a v-else :href="allocationUi.full_allocation_url" class="erp-btn erp-btn-secondary">完整分仓</a>
            <button v-if="!allocationUi.requires_full_flow" type="button" class="erp-btn erp-btn-primary" :disabled="!allocationReady || allocationSubmitting" @click="submitAllocation">{{ allocationSubmitting ? '提交中…' : allocationUi?.status === 'submitted' ? '确认重新分仓' : allocationMatchesRecommendation ? '采用推荐并提交分仓' : '确认分仓' }}</button>
          </div>
        </div>

        </template>

        <!-- Product packaging standard / fulfillment snapshot -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">包装与重量参考</h2>
          <p class="ord-inventory-help">待发货订单读取产品当前包装标准；进入履约后保存为订单商品履约快照，后续产品标准变化不会改写已冻结的发货参考。</p>
          <div v-for="item in order.items" :key="`packaging-${item.id}`" class="ord-packaging-row">
            <div class="ord-packaging-identity">
              <strong>{{ item.sku || '—' }}</strong>
              <small>{{ item.product_summary }} · 数量 {{ item.quantity }}</small>
              <a v-if="canManageProductPackaging && item.product_id" class="ord-packaging-link" :href="`/products/${item.product_id}/packaging`">包装方案 →</a>
            </div>
            <template v-if="packagingReferences?.[item.id]">
              <div class="ord-packaging-metric"><small>包装方式</small><strong>{{ packagingReferences[item.id].packaging_method_label || '未指定' }}</strong></div>
              <div class="ord-packaging-metric"><small>包装尺寸</small><strong>{{ packagingReferences[item.id].packaging_length_cm }} × {{ packagingReferences[item.id].packaging_width_cm }} × {{ packagingReferences[item.id].packaging_height_cm }} cm</strong></div>
              <div class="ord-packaging-metric"><small>打包后重量</small><strong>{{ packagingReferences[item.id].packaged_weight_kg }} kg</strong></div>
              <div class="ord-packaging-source">
                <span>{{ packagingReferences[item.id].source_label }}</span>
                <a v-if="canViewPackagingEvidence && packagingReferences[item.id].measurement_record_id" class="ord-packaging-link" :href="`/inventory/packaging/${packagingReferences[item.id].measurement_record_id}`">查看包装记录 #{{ packagingReferences[item.id].measurement_record_id }} →</a>
                <small v-else>测量记录 #{{ packagingReferences[item.id].measurement_record_id }}</small>
              </div>
            </template>
            <div v-else class="ord-packaging-empty">该产品尚未设定“当前包装标准”</div>
          </div>
        </div>

        <!-- Card 2: 订单信息 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">订单信息</h2>
          <div class="erp-form-grid">
            <div class="erp-form-field">
              <label class="erp-form-label">订单号</label>
              <input :value="order.erp_order_id ?? '—'" class="erp-input" disabled>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">订单类型</label>
              <input :value="order.order_kind_label" class="erp-input" disabled>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">来源</label>
              <input :value="order.source_label" class="erp-input" disabled>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">负责人</label>
              <input :value="order.owner?.name ?? '—'" class="erp-input" disabled>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">订单币种</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.currency }}</div>
              <template v-else>
                <select v-model="form.currency" class="erp-input">
                  <option v-for="option in currencyOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <p v-if="form.errors.currency" class="erp-field-error">{{ form.errors.currency }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">订单日期</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.order_date }}</div>
              <input v-else v-model="form.order_date" type="date" class="erp-input" :class="{ 'has-error': form.errors.order_date }">
              <p v-if="form.errors.order_date" class="erp-field-error">{{ form.errors.order_date }}</p>
            </div>
          </div>
        </div>

        <!-- Card 3: 收件与地址 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">收件与地址</h2>
          <div class="erp-form-grid">
            <div class="erp-form-field">
              <label class="erp-form-label">收件人姓名</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.recipient_name || '—' }}</div>
              <template v-else>
                <input v-model="form.recipient_name" class="erp-input" placeholder="可选">
                <p v-if="form.errors.recipient_name" class="erp-field-error">{{ form.errors.recipient_name }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">联系电话</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.phone || '—' }}</div>
              <template v-else>
                <input v-model="form.phone" class="erp-input" placeholder="可选">
                <p v-if="form.errors.phone" class="erp-field-error">{{ form.errors.phone }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">国家</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.country || '—' }}</div>
              <template v-else>
                <input v-model="form.country" class="erp-input" placeholder="可选">
                <p v-if="form.errors.country" class="erp-field-error">{{ form.errors.country }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">省/州</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.province_state || '—' }}</div>
              <template v-else>
                <input v-model="form.province_state" class="erp-input" placeholder="可选">
                <p v-if="form.errors.province_state" class="erp-field-error">{{ form.errors.province_state }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">城市</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.city || '—' }}</div>
              <template v-else>
                <input v-model="form.city" class="erp-input" placeholder="可选">
                <p v-if="form.errors.city" class="erp-field-error">{{ form.errors.city }}</p>
              </template>
            </div>
            <div class="erp-form-field">
              <label class="erp-form-label">邮政编码</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.postal_code || '—' }}</div>
              <template v-else>
                <input v-model="form.postal_code" class="erp-input" placeholder="可选">
                <p v-if="form.errors.postal_code" class="erp-field-error">{{ form.errors.postal_code }}</p>
              </template>
            </div>
            <div class="erp-form-field ord-span-2">
              <label class="erp-form-label">地址行 1</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.address_line_1 || '—' }}</div>
              <template v-else>
                <input v-model="form.address_line_1" class="erp-input" placeholder="可选">
                <p v-if="form.errors.address_line_1" class="erp-field-error">{{ form.errors.address_line_1 }}</p>
              </template>
            </div>
            <div class="erp-form-field ord-span-2">
              <label class="erp-form-label">地址行 2</label>
              <div v-if="!editMode" class="erp-readonly-input">{{ order.address?.address_line_2 || '—' }}</div>
              <template v-else>
                <input v-model="form.address_line_2" class="erp-input" placeholder="可选">
                <p v-if="form.errors.address_line_2" class="erp-field-error">{{ form.errors.address_line_2 }}</p>
              </template>
            </div>
          </div>
        </div>

        <!-- Card 4: 订单商品 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">订单商品</h2>
          <!-- View mode: read-only item list -->
          <template v-if="!editMode">
            <div v-for="item in order.items" :key="item.id" class="ord-item-row-view">
              <div class="erp-form-field">
                <label class="erp-form-label">SKU / 产品</label>
                <div class="erp-readonly-input">{{ item.sku || '—' }}</div>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">产品摘要</label>
                <div class="erp-readonly-input">{{ item.product_summary || '—' }}</div>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">数量</label>
                <div class="erp-readonly-input">{{ item.quantity }}</div>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">单价</label>
                <div class="erp-readonly-input">{{ order.currency }} {{ item.unit_price }}</div>
              </div>
            </div>
          </template>
          <!-- Edit mode: stable item IDs, SKU variant selection and explicit add/remove. -->
          <template v-else>
            <p class="ord-item-amendment-info">SKU、颜色或数量变化后，必须重新核对库存与分仓。已分仓、预留库存或开始发货的订单暂不允许直接修改商品。</p>
            <p v-if="(form.errors as Record<string,string>).items" class="erp-field-error" role="alert">{{ (form.errors as Record<string,string>).items }}</p>
            <div v-for="(item, index) in form.items" :key="item.id ?? `new-${index}`" class="ord-item-row">
              <div class="ord-item-sku">
                <label class="erp-form-label">SKU / 产品</label>
                <SkuCombobox
                  v-model="item.sku"
                  :product-id="item.product_id"
                  @update:product-id="(id) => { item.product_id = id }"
                />
                <p v-if="(form.errors as Record<string, string>)[`items.${index}.product_id`]" class="erp-field-error">
                  {{ (form.errors as Record<string, string>)[`items.${index}.product_id`] }}
                </p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">产品摘要</label>
                <input v-model="item.product_summary" class="erp-input" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">数量</label>
                <input v-model="item.quantity" type="number" min="1" class="erp-input" required>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">单价（{{ form.currency }}）</label>
                <input v-model="item.unit_price" type="number" min="0" step="0.01" class="erp-input" required>
              </div>
              <button type="button" class="erp-btn erp-btn-secondary" :disabled="form.items.length <= 1" @click="removeOrderItem(index)">删除商品</button>
            </div>
            <button type="button" class="erp-btn erp-btn-secondary ord-add-product" @click="addOrderItem">+ 增加商品 / 赠品</button>
            <p class="ord-item-gift-hint">赠品选择真实 SKU，数量需大于 0，单价可填 0.00。修改前请先确认客户同意。</p>
            <div class="ord-amendment-actions">
              <button type="button" class="erp-btn erp-btn-secondary" :disabled="amendmentPreviewLoading" @click="previewItemAmendment">
                {{ amendmentPreviewLoading ? '正在核对…' : '检查 SKU、库存与分仓影响' }}
              </button>
              <small>只读预检，不会修改订单、库存或发货状态。</small>
            </div>
            <p v-if="amendmentPreviewError" class="erp-field-error" role="alert">{{ amendmentPreviewError }}</p>
            <section v-if="amendmentPreview" class="ord-amendment-preview" aria-label="订单商品变更预检结果">
              <strong>{{ amendmentPreview.can_save_direct ? '预检：可保存商品变更' : '预检：暂不能直接保存商品变更' }}</strong>
              <p>商品金额（{{ amendmentPreview.currency }}）：{{ amendmentPreview.old_items_gross }} → {{ amendmentPreview.new_items_gross }}（差额 {{ amendmentPreview.gross_delta }}）。不代表最终应收/退款金额。</p>
              <p v-for="(issue,i) in amendmentPreview.issues" :key="i" class="erp-field-error" role="alert">{{ issue }}</p>
              <div v-for="(row,i) in amendmentPreview.rows.filter(row => row.operation !== 'unchanged')" :key="i" class="ord-amendment-diff">
                {{ row.operation === 'add' ? '增加' : row.operation === 'remove' ? '移除' : '更换' }}：
                {{ row.before?.sku || '—' }} × {{ row.before?.quantity ?? 0 }} → {{ row.after?.sku || '—' }} × {{ row.after?.quantity ?? 0 }}
              </div>
              <strong>单仓当前库存核对</strong>
              <p v-for="wh in amendmentPreview.stock_by_warehouse" :key="wh.warehouse_id">
                {{ wh.warehouse_name }}：{{ wh.single_warehouse_candidate ? '全部 SKU 当前库存满足' : '存在缺货、未确认库存或不支持自动分配' }}
              </p>
              <small>{{ amendmentPreview.inventory_note }}</small>
              <div v-if="amendmentPreview.can_atomic_single_replan && canEditOrder && canOperateLogistics" class="ord-replan-approval">
                <strong>A5 单仓库存原子重规划</strong>
                <p>此操作只调整订单商品、待发货分仓和库存预留；不会发货，也不会自动修改 Woo 或收退款。</p>
                <label class="erp-form-label">新的发货仓库
                  <select v-model="amendmentReplanWarehouse" class="erp-select">
                    <option value="">请明确选择目标仓库</option>
                    <option v-for="wh in amendmentPreview.stock_by_warehouse.filter(w => w.single_warehouse_candidate)"
                      :key="wh.warehouse_id" :value="wh.warehouse_code">{{ wh.warehouse_name }}</option>
                  </select>
                </label>
                <label class="erp-form-label">商品变更原因
                  <textarea v-model="amendmentReplanReason" class="erp-textarea" rows="2"
                    placeholder="填写与客户沟通后的具体 SKU、颜色或数量调整原因" />
                </label>
                <label class="ord-replan-consent">
                  <input v-model="amendmentReplanApproved" type="checkbox">
                  我已确认客户修改要求，允许一次性释放旧预留并在所选仓库重新预留。
                </label>
                <button type="button" class="erp-btn erp-btn-primary"
                  :disabled="amendmentReplanSaving || !amendmentReplanApproved || !amendmentReplanWarehouse || amendmentReplanReason.trim().length < 4"
                  @click="commitAtomicReplan">
                  {{ amendmentReplanSaving ? '正在原子提交…' : '确认商品变更并重新预留库存' }}
                </button>
              </div>
            </section>
          </template>
        </div>

        <!-- Card 5: 内部备注 -->
        <div class="erp-form-card">
          <h2 class="erp-form-card-title">内部备注</h2>
          <div class="erp-form-field">
            <label class="erp-form-label">备注内容</label>
            <div v-if="!editMode" class="erp-readonly-input ord-readonly-textarea">{{ order.order_note || '—' }}</div>
            <template v-else>
              <textarea v-model="form.order_note" class="erp-textarea" placeholder="可选" />
              <p v-if="form.errors.order_note" class="erp-field-error">{{ form.errors.order_note }}</p>
            </template>
          </div>
        </div>

        <!-- Audit log -->
        <div class="erp-form-card ord-audit-card">
          <h2 class="erp-form-card-title">操作审计</h2>
          <ul class="ord-audit-list">
            <li v-for="event in audit" :key="event.created_at + event.action" class="ord-audit-item">
              <span class="ord-audit-action">{{ event.action }}</span>
              <small class="ord-audit-meta">{{ event.created_at }} · actor {{ event.actor_id ?? 'system' }}</small>
            </li>
          </ul>
        </div>

      </div>
    </div>

    <div v-if="partialReallocationOpen" class="ord-replacement-backdrop" @click.self="closePartialReallocation">
      <section class="ord-replacement-modal ord-partial-reallocation-modal" role="dialog" aria-modal="true" aria-label="调整未发货履约分仓">
        <header class="ord-replacement-header">
          <div><small>PARTIAL REALLOCATION</small><h2>调整 {{ partialReallocationSegment?.suffix }} 分仓</h2><p>只调整尚未进入物流的履约；已经发货的 A/B/C 不会被改动。</p></div>
          <button type="button" class="ord-replacement-close" @click="closePartialReallocation">×</button>
        </header>
        <div class="ord-partial-reallocation-current">
          <span>当前履约</span><strong>{{ partialReallocationSegment?.erp_order_id }}</strong>
          <span>当前仓库</span><strong>{{ partialReallocationSegment?.shipment.warehouse_name ?? '未选择' }}</strong>
        </div>
        <label class="ord-partial-reallocation-field">新仓库
          <select v-model="partialReallocationWarehouseId" class="erp-select">
            <option :value="null">请选择新仓库</option>
            <option v-for="warehouse in partialReallocationWarehouses" :key="warehouse.id" :value="warehouse.id" :disabled="warehouse.id === partialReallocationSegment?.shipment.warehouse_id">{{ warehouse.name }} · {{ warehouse.code }}{{ warehouse.id === partialReallocationSegment?.shipment.warehouse_id ? '（当前）' : '' }}</option>
          </select>
        </label>
        <label class="ord-partial-reallocation-field">调整原因
          <textarea v-model="partialReallocationNote" rows="3" class="erp-textarea" placeholder="例如：B 尚未发货，改由美东纽约仓履约"></textarea>
        </label>
        <div class="ord-partial-reallocation-warning">已进入真实物流的兄弟履约会继续保持锁定；本次只更新 {{ partialReallocationSegment?.suffix }} 的仓库与自动路由。</div>
        <footer class="ord-replacement-actions">
          <button type="button" class="erp-btn erp-btn-secondary" :disabled="partialReallocationSaving" @click="closePartialReallocation">取消</button>
          <button type="button" class="erp-btn erp-btn-primary" :disabled="partialReallocationSaving || !partialReallocationWarehouseId || partialReallocationWarehouseId === partialReallocationSegment?.shipment.warehouse_id" @click="submitPartialReallocation">{{ partialReallocationSaving ? '调整中…' : '确认调整分仓' }}</button>
        </footer>
      </section>
    </div>

    <div v-if="shipmentEditorOpen" class="ord-replacement-backdrop" @click.self="closeShipmentEditor">
      <section class="ord-replacement-modal ord-shipment-modal" role="dialog" aria-modal="true" aria-label="填写物流并发货">
        <header class="ord-replacement-header">
          <div><small>FULFILLMENT EXECUTION</small><h2>填写物流并发货</h2><p>{{ shipmentEditorLabel }} · 当前订单详情页直接完成真实履约。</p></div>
          <button type="button" class="ord-replacement-close" @click="closeShipmentEditor">×</button>
        </header>
        <div class="ord-shipment-form">
          <label>发货仓库
            <select v-model="shipmentEditor.warehouse_id" class="erp-select" :disabled="!canOperateLogistics" @change="applyShipmentWarehouseDefault"><option :value="null">请选择仓库</option><option v-for="warehouse in warehouses ?? []" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }} · {{ warehouse.code }}</option></select>
          </label>
          <label>物流商
            <select v-model="shipmentEditor.carrier" class="erp-select" :disabled="!canOperateLogistics" @change="applyShipmentCarrierDefault"><option value="">请选择承运商</option><option v-for="carrier in carrierOptions ?? []" :key="carrier.value" :value="carrier.value">{{ carrier.label }}</option></select>
          </label>
          <label>运输 / 服务类型<select v-model="shipmentEditor.service" class="erp-select" :disabled="!canOperateLogistics || !shipmentEditor.carrier"><option value="">请选择服务类型</option><option v-for="service in shipmentServiceOptions" :key="service.id" :value="service.value">{{ service.label }}</option></select></label>
          <label>Tracking<input v-model="shipmentEditor.tracking_number" class="erp-input" :disabled="!canOperateLogistics" placeholder="追踪号，可稍后补录"></label>
          <label>发货时间<input v-model="shipmentEditor.shipped_at" class="erp-input" :disabled="!canOperateLogistics" type="datetime-local"></label>
          <label class="ord-shipment-note">物流备注<textarea v-model="shipmentEditor.logistics_note" rows="3" class="erp-textarea" :disabled="!canOperateLogistics" placeholder="可填写包裹、异常或操作备注"></textarea></label>
        </div>
        <p v-if="shipmentEditorError" class="ord-shipment-error">{{ shipmentEditorError }}</p>
        <p v-if="!canOperateLogistics" class="ord-shipment-readonly">当前账号为物流执行只读模式；物流员可以在这里直接填写物流并确认发货。</p>
        <footer class="ord-replacement-actions">
          <button v-if="canOperateLogistics" type="button" class="erp-btn erp-btn-secondary" :disabled="shipmentEditorSaving" @click="saveShipmentDraft">保存草稿</button>
          <button v-if="canOperateLogistics" type="button" class="erp-btn erp-btn-primary" :disabled="shipmentEditorSaving" @click="confirmShipment">{{ shipmentEditorSaving ? '处理中…' : '确认发货' }}</button>
          <button v-else type="button" class="erp-btn erp-btn-secondary" @click="closeShipmentEditor">关闭</button>
        </footer>
      </section>
    </div>

    <div v-if="replacementOpen" class="ord-replacement-backdrop" @click.self="closeReplacement">
      <section class="ord-replacement-modal" role="dialog" aria-modal="true" aria-label="新增售后补发">
        <header class="ord-replacement-header">
          <div><small>REPLACEMENT FULFILLMENT</small><h2>新增售后补发</h2><p>保留原物流事实，创建新的 R1 / R2 补发履约。</p></div>
          <button type="button" class="ord-replacement-close" @click="closeReplacement">×</button>
        </header>
        <div class="ord-replacement-grid">
          <label>补发原因
            <select v-model="replacementReason" class="erp-select">
              <option value="lost">物流丢件</option><option value="damaged">产品损坏</option><option value="after_sales">售后换新</option><option value="maintenance">维护补件</option><option value="missing_item">少发漏发</option><option value="other">其他</option>
            </select>
          </label>
          <label>关联原履约
            <select v-model="replacementOriginId" class="erp-select">
              <option :value="null">不指定 / 原订单</option>
              <option v-for="segment in replacementOriginalSegments" :key="segment.order_id" :value="segment.order_id">{{ segment.suffix }} · {{ segment.erp_order_id }} · {{ segment.shipment.warehouse_name ?? '未选仓' }}</option>
            </select>
          </label>
          <label>补发仓库
            <select v-model="replacementWarehouseId" class="erp-select">
              <option :value="null">请选择仓库</option>
              <option v-for="warehouse in warehouses ?? []" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }} · {{ warehouse.code }}</option>
            </select>
          </label>
        </div>
        <div class="ord-replacement-items">
          <strong>补发商品</strong>
          <div v-for="item in order.items" :key="`replacement-${item.id}`" class="ord-replacement-item">
            <div><b>{{ item.sku || item.product_summary }}</b><small>原订单数量 {{ item.quantity }}</small></div>
            <input v-model.number="replacementQuantities[item.id]" type="number" min="0" :max="item.quantity" step="1" aria-label="补发数量">
          </div>
        </div>
        <label class="ord-replacement-note">备注
          <textarea v-model="replacementNote" rows="3" class="erp-textarea" placeholder="例如：客户反馈到货损坏，补发 1 件"></textarea>
        </label>
        <label class="ord-address-toggle"><input v-model="replacementAddressOverride" type="checkbox"> 修改本次补发收件地址</label>
        <div v-if="replacementAddressOverride" class="ord-replacement-address">
          <input v-model="replacementAddress.recipient_name" class="erp-input" placeholder="收件人">
          <input v-model="replacementAddress.phone" class="erp-input" placeholder="电话">
          <input v-model="replacementAddress.country" class="erp-input" placeholder="国家">
          <input v-model="replacementAddress.province_state" class="erp-input" placeholder="州 / 省">
          <input v-model="replacementAddress.city" class="erp-input" placeholder="城市">
          <input v-model="replacementAddress.postal_code" class="erp-input" placeholder="邮编">
          <input v-model="replacementAddress.address_line_1" class="erp-input ord-address-wide" placeholder="地址 1">
          <input v-model="replacementAddress.address_line_2" class="erp-input ord-address-wide" placeholder="地址 2">
        </div>
        <footer class="ord-replacement-actions">
          <button type="button" class="erp-btn erp-btn-secondary" @click="closeReplacement">取消</button>
          <button type="button" class="erp-btn erp-btn-primary" :disabled="!replacementReady || replacementSubmitting" @click="submitReplacement">{{ replacementSubmitting ? '创建中…' : '创建补发履约' }}</button>
        </footer>
      </section>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.erp-form-header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
}

/* Identity strip — order-specific, no erp-* equivalent */
.ord-identity-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border: 1px solid #e4eaed;
  border-radius: 16px;
  padding: 16px 24px;
  margin-bottom: 20px;
  gap: 16px;
  flex-wrap: wrap;
}

.ord-identity-main {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.ord-identity-number {
  font-size: 18px;
  font-weight: 600;
  color: #142126;
}

.ord-identity-badge {
  font-size: 12px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 6px;
  background: #e4eaed;
  color: #344b53;
}

.ord-badge-muted {
  background: #f4f7f8;
  color: #7a8c94;
}

.ord-badge-status {
  background: #d1fae5;
  color: #065f46;
}

.ord-identity-owner {
  font-size: 13px;
  color: #7a8c94;
}

.ord-identity-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ord-details-disclosure {
  font-size: 12px;
  color: #7a8c94;
}

.ord-details-summary {
  cursor: pointer;
  font-size: 12px;
  color: #7a8c94;
}

.ord-details-value {
  display: block;
  font-size: 12px;
  color: #344b53;
  margin-top: 4px;
  font-family: monospace;
}

/* Fulfillment card layout — no erp-* equivalent */
.ord-fulfillment-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}

.ord-fulfillment-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ord-fulfillment-label {
  font-size: 12px;
  color: #7a8c94;
}

.ord-fulfillment-value {
  font-size: 14px;
  color: #142126;
}

.ord-fulfillment-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}

.ord-fulfillment-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  flex-wrap: wrap;
}
.ord-fulfillment-cta { white-space: nowrap; }

.ord-fulfillment-title-row { display: flex; align-items: center; gap: 10px; min-width: 0; }
.ord-fulfillment-heading .erp-form-card-title { margin-bottom: 0; }
.ord-split-badge { display: inline-flex; align-items: center; padding: 4px 9px; border-radius: 999px; background: #eef7f2; color: #16705f; font-size: 11px; font-weight: 700; }
.ord-fulfillment-summary { display: flex; align-items: center; gap: 12px; padding: 12px 14px; margin-bottom: 12px; border: 1px solid #dce7e3; border-radius: 10px; background: #f8fbfa; }
.ord-fulfillment-summary > div { display: flex; align-items: baseline; gap: 10px; }
.ord-fulfillment-summary strong { color: #173a36; font-size: 15px; }
.ord-fulfillment-summary span,.ord-fulfillment-summary small { color: #65777e; font-size: 12px; }
.ord-fulfillment-summary small { margin-left: auto; }
.ord-fulfillment-alert { display: inline-flex; padding: 3px 8px; border-radius: 999px; background: #fff0ef; color: #b44949 !important; font-weight: 700; }
.ord-fulfillment-table-wrap { width: 100%; overflow-x: auto; }
.ord-fulfillment-table { width: 100%; min-width: 900px; border: 1px solid #dde5e8; border-radius: 10px; border-spacing: 0; border-collapse: separate; overflow: hidden; font-size: 12px; }
.ord-fulfillment-table th,.ord-fulfillment-table td { padding: 10px 12px; border-right: 1px solid #e4eaed; border-bottom: 1px solid #e4eaed; text-align: left; vertical-align: middle; }
.ord-fulfillment-table th:last-child,.ord-fulfillment-table td:last-child { border-right: 0; }
.ord-fulfillment-table tbody tr:last-child td { border-bottom: 0; }
.ord-fulfillment-table th { background: #f5f7f8; color: #52656d; font-weight: 700; white-space: nowrap; }
.ord-fulfillment-segment-id,.ord-carrier-service { display: flex; flex-direction: column; gap: 3px; }
.ord-fulfillment-segment-id strong { width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; background: #e8f4f0; color: #14695d; font-size: 13px; }
.ord-fulfillment-segment-id.replacement strong { background: #fff3df; color: #9a5b00; }
.ord-fulfillment-segment-id small,.ord-carrier-service small { color: #87969c; font-size: 10px; }
.ord-segment-role { width: max-content; display: inline-flex; padding: 2px 6px; border-radius: 999px; font-size: 9px; font-weight: 800; background: #eef4f2; color: #47635d; }
.ord-segment-role.replacement { background: #fff3df; color: #9a5b00; }
.ord-fulfillment-items { display: grid; gap: 4px; min-width: 150px; }
.ord-fulfillment-items > div { display: flex; align-items: center; gap: 6px; }
.ord-fulfillment-items strong { color: #273d45; font-size: 12px; }
.ord-fulfillment-items span { color: #7b8d94; font-size: 11px; }
.ord-carrier-service strong { color: #273d45; font-size: 12px; }
.ord-segment-status { display: inline-flex; padding: 3px 8px; border-radius: 999px; background: #eef1f3; color: #62737b; font-weight: 700; white-space: nowrap; }
.ord-segment-status.status-shipped,.ord-segment-status.status-completed { background: #e8f6ef; color: #176c5d; }
.ord-segment-status.status-exception { background: #fff0ef; color: #b44949; }
.ord-tracking-number { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; color: #3f5660; white-space: nowrap; }

.ord-inventory-help { margin: -4px 0 12px; font-size: 12px; color: #7a8c94; }
.ord-inventory-card { overflow: hidden; }
.ord-inventory-table-wrap { width: 100%; margin-top: 10px; overflow-x: auto; }
.ord-inventory-table { width: 100%; min-width: 820px; border: 1px solid #dde5e8; border-radius: 10px; border-spacing: 0; border-collapse: separate; overflow: hidden; font-size: 13px; }
.ord-inventory-table th,
.ord-inventory-table td { padding: 9px 12px; border-right: 1px solid #e4eaed; border-bottom: 1px solid #e4eaed; text-align: left; vertical-align: middle; white-space: nowrap; }
.ord-inventory-table th:last-child,
.ord-inventory-table td:last-child { border-right: 0; }
.ord-inventory-table tbody tr:last-child td { border-bottom: 0; }
.ord-inventory-table th { background: #f5f7f8; color: #263b43; font-weight: 700; }
.ord-inventory-table td { color: #31464e; }
.ord-inventory-warehouse-name { font-weight: 600; color: #1f333a; }
.ord-inventory-sku-value { font-weight: 600; color: #263b43; }
.ord-stock-status { display: inline-flex; align-items: center; gap: 7px; font-weight: 700; }
.ord-stock-status-icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 999px; color: #fff; font-size: 12px; line-height: 1; }
.ord-stock-status.in-stock { color: #16705f; }
.ord-stock-status.in-stock .ord-stock-status-icon { background: #1f9d7a; }
.ord-stock-status.out-of-stock { color: #df4453; }
.ord-stock-status.out-of-stock .ord-stock-status-icon { background: #ef4454; }
.ord-stock-status.unknown { color: #7b8b93; }
.ord-stock-status.unknown .ord-stock-status-icon { background: #8c9aa1; }
.ord-inventory-note { color: #5f727a; }
.ord-inventory-empty { padding: 18px 12px !important; text-align: center !important; color: #87969c !important; }
.ord-allocation-cell { white-space: nowrap; }
.ord-allocation-choice { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 700; color: #475569; }
.ord-allocation-choice input { margin: 0; }
.ord-allocation-choice.selected { color: #087f5b; }
.ord-allocation-choice small, .ord-allocation-recommended { display: inline-flex; padding: 2px 6px; border-radius: 999px; background: #e8f8ef; color: #087f5b; font-size: 10px; font-weight: 800; }
.ord-allocation-fixed { color: #087f5b; font-weight: 800; }
.ord-allocation-reopen-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 14px; border: 1px solid #dce5df; border-radius: 10px; background: #f8fbf9; }
.ord-allocation-reopen-bar > div { display: grid; gap: 3px; }
.ord-allocation-reopen-bar strong { color: #173a36; font-size: 13px; }
.ord-allocation-reopen-bar span { color: #64748b; font-size: 12px; }
.ord-allocation-action-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: -2px; padding: 10px 14px; border: 1px solid #dce5df; border-radius: 10px; background: #f8fbf9; }
.ord-allocation-action-bar > div:first-child { display: grid; gap: 3px; }
.ord-allocation-action-bar span { color: #64748b; font-size: 12px; }
.ord-allocation-actions { display: flex; align-items: center; gap: 8px; }

.ord-packaging-row { display: grid; grid-template-columns: 1.2fr 1fr 1.35fr .8fr .9fr; gap: 12px; align-items: center; padding: 12px 0; border-bottom: 1px solid #e4eaed; }
.ord-packaging-row:last-child { border-bottom: 0; }
.ord-packaging-identity,.ord-packaging-metric,.ord-packaging-source { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.ord-packaging-identity small,.ord-packaging-metric small,.ord-packaging-source small { color: #7a8c94; font-size: 11px; }
.ord-packaging-metric strong { color: #263b43; font-size: 13px; }
.ord-packaging-source span { width: fit-content; padding: 4px 8px; border-radius: 999px; background: #eef7f2; color: #16705f; font-size: 11px; font-weight: 700; }
.ord-packaging-empty { grid-column: 2 / -1; padding: 10px 12px; border-radius: 8px; background: #f7f9fa; color: #87969c; font-size: 12px; }

/* span-2 within erp-form-grid */
.ord-span-2 {
  grid-column: span 2;
}

/* Item rows — order-specific layout */
.ord-item-amendment-info { margin: 0 0 12px; font-size: 12px; color: #72510f; background: #fff9e9; padding: 8px 10px; border-radius: 8px; }
.ord-item-gift-hint { margin: 8px 0 0; font-size: 12px; color: #52656d; }
.ord-add-product { margin-top: 10px; }
.ord-amendment-actions { display:flex; align-items:center; gap:10px; margin-top:12px; }
.ord-amendment-actions small { font-size:11px; color:#667781; }
.ord-amendment-preview { margin-top:12px; padding:12px; border:1px solid #d1e3de; border-radius:8px; background:#f7fbfa; }
.ord-amendment-preview p { margin:5px 0; font-size:12px; }
.ord-amendment-preview strong { display:block; margin:8px 0; font-size:13px; }
.ord-amendment-diff { font-size:12px; padding:5px 0; border-bottom:1px solid #e7e7e7; }
.ord-replan-approval { margin-top:14px; padding-top:12px; border-top:1px solid #d1e3de; display:grid; gap:10px; }
.ord-replan-approval label { display:grid; gap:6px; font-size:12px; }
.ord-replan-approval .ord-replan-consent { display:flex; align-items:start; gap:8px; }
.ord-replan-approval .ord-replan-consent input { margin-top:3px; }


.ord-item-row {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr 1fr;
  gap: 12px;
  align-items: end;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.ord-item-row-view {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr 1fr;
  gap: 12px;
  align-items: start;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.ord-item-row:first-of-type,
.ord-item-row-view:first-of-type {
  border-top: none;
}

.ord-item-sku {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* Readonly textarea-height variant */
.ord-readonly-textarea {
  min-height: 80px;
  height: auto;
  align-items: flex-start;
  padding: 10px 12px;
}

/* Audit log */
.ord-audit-card {
  margin-top: 0;
}

.ord-audit-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.ord-audit-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #e4eaed;
  font-size: 14px;
}

.ord-audit-item:last-child {
  border-bottom: none;
}

.ord-audit-action {
  color: #344b53;
}

.ord-audit-meta {
  font-size: 12px;
  color: #7a8c94;
}


.ord-replacement-backdrop { position: fixed; inset: 0; z-index: 80; display: grid; place-items: center; padding: 24px; background: rgba(12, 24, 24, .44); backdrop-filter: blur(4px); }
.ord-replacement-modal { width: min(760px, 100%); max-height: calc(100vh - 48px); overflow-y: auto; padding: 20px; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px rgba(18, 48, 44, .24); }
.ord-replacement-header { display: flex; justify-content: space-between; gap: 16px; padding-bottom: 14px; border-bottom: 1px solid #e4eaed; }
.ord-replacement-header small { color: #6f837d; font-size: 10px; font-weight: 800; letter-spacing: .08em; }
.ord-replacement-header h2 { margin: 3px 0 4px; color: #173a36; font-size: 20px; }
.ord-replacement-header p { margin: 0; color: #708087; font-size: 12px; }
.ord-replacement-close { width: 34px; height: 34px; border: 0; border-radius: 10px; background: #f1f4f3; color: #52645f; font-size: 22px; cursor: pointer; }
.ord-replacement-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 12px; margin-top: 16px; }
.ord-replacement-grid label,.ord-replacement-note { display: grid; gap: 6px; color: #52656d; font-size: 11px; font-weight: 700; }
.ord-replacement-items { display: grid; gap: 8px; margin-top: 16px; padding: 12px; border: 1px solid #e1e8e5; border-radius: 12px; background: #fafcfb; }
.ord-replacement-items > strong { color: #173a36; font-size: 12px; }
.ord-replacement-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid #edf1ef; }
.ord-replacement-item:first-of-type { border-top: 0; }
.ord-replacement-item > div { display: grid; gap: 2px; }
.ord-replacement-item b { color: #2b4440; font-size: 12px; }
.ord-replacement-item small { color: #84938f; font-size: 10px; }
.ord-replacement-item input { width: 88px; padding: 8px 10px; border: 1px solid #d5dfdb; border-radius: 9px; }
.ord-replacement-note { margin-top: 14px; }
.ord-address-toggle { display: inline-flex; align-items: center; gap: 7px; margin-top: 14px; color: #536862; font-size: 12px; font-weight: 700; }
.ord-replacement-address { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 10px; margin-top: 10px; }
.ord-address-wide { grid-column: 1 / -1; }
.ord-replacement-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e4eaed; }
.ord-shipment-form { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px; margin-top: 16px; }
.ord-shipment-form label { display: grid; gap: 6px; color: #52656d; font-size: 11px; font-weight: 700; }
.ord-shipment-note { grid-column: 1 / -1; }
.ord-shipment-error { margin: 12px 0 0; color: #b44949; font-size: 12px; font-weight: 700; }
.ord-shipment-readonly { margin: 12px 0 0; padding: 9px 11px; border-radius: 9px; background: #f5f7f8; color: #66777e; font-size: 12px; }
.ord-ship-action { white-space: nowrap; }
.ord-fulfillment-row-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ord-partial-reallocation-current { display: grid; grid-template-columns: 90px 1fr; gap: 6px 12px; margin-top: 16px; padding: 12px; border-radius: 10px; background: #f7faf9; }
.ord-partial-reallocation-current span { color: #7a8c86; font-size: 11px; }
.ord-partial-reallocation-current strong { color: #273f3a; font-size: 12px; }
.ord-partial-reallocation-field { display: grid; gap: 6px; margin-top: 14px; color: #52656d; font-size: 11px; font-weight: 700; }
.ord-partial-reallocation-warning { margin-top: 14px; padding: 10px 12px; border-radius: 10px; background: #fff7e8; color: #8a6200; font-size: 11px; }

@media (max-width: 767px) {
  .ord-fulfillment-heading { align-items: flex-start; flex-direction: column; }
  .ord-fulfillment-actions { width: 100%; justify-content: flex-start; }
  .erp-form-header-actions {
    width: 100%;
    justify-content: flex-start;
  }
  .erp-form-header-actions .erp-btn {
    flex: 1 1 auto;
  }
  .ord-span-2 {
    grid-column: span 1;
  }
  .ord-fulfillment-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .ord-fulfillment-summary {
    align-items: flex-start;
    flex-direction: column;
  }
  .ord-fulfillment-summary small { margin-left: 0; }
  .ord-fulfillment-heading { align-items: flex-start; flex-direction: column; }
  .ord-fulfillment-title-row { flex-wrap: wrap; }
  .ord-replacement-backdrop { padding: 10px; }
  .ord-replacement-modal { max-height: calc(100vh - 20px); padding: 16px; border-radius: 14px; }
  .ord-replacement-grid,.ord-replacement-address { grid-template-columns: 1fr; }
  .ord-shipment-form { grid-template-columns: 1fr; }
  .ord-shipment-note { grid-column: auto; }
  .ord-address-wide { grid-column: auto; }
  .ord-item-row,
  .ord-item-row-view,
  .ord-packaging-row {
    grid-template-columns: 1fr;
  }
  .ord-packaging-empty { grid-column: 1; }
  .ord-identity-strip {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>

<style scoped>
.ord-marker-row{display:flex;gap:8px;flex-wrap:wrap}.ord-marker.active{background:var(--byl-status-bg);color:var(--byl-status-ink);font-weight:700}.ord-marker:disabled{opacity:.55;cursor:not-allowed}
.ord-packaging-link{display:inline-flex;width:max-content;color:#0f766e;text-decoration:none;font-size:12px;font-weight:700}.ord-packaging-link:hover{text-decoration:underline}.ord-packaging-link:focus-visible{outline:2px solid #0f766e;outline-offset:2px;border-radius:4px}
</style>
