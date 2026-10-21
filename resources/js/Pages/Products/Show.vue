<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type ExternalReference = { provider: string; connection_key: string; reference: string; reference_type: string }
type Order = { id: number; erp_order_id?: string | null; external_order_number?: string | null; number?: string | null; order_date: string | null; status: string; quantity: number; revenue: number; currency: string }
type Shipment = { id: number; order_id: number; erp_order_id?: string | null; external_order_number?: string | null; order_number?: string | null; carrier: string | null; tracking_number: string | null; shipped_at: string | null; status: string | null }
type Inventory = { warehouse_id: number; warehouse_name: string; warehouse_slug: string; inventory_state: string; quantity_on_hand: number | null; quantity_reserved: number | null; quantity_available: number | null; expected_restock_qty: number | null }
type Audit = { action: string; action_label: string; actor_name?: string | null; actor_label?: string | null; source: string | null; created_at: string | null; reason: string | null }
type OrderColumn = 'order_number' | 'date' | 'quantity' | 'revenue' | 'status'
type ShipmentColumn = 'order_number' | 'carrier' | 'tracking' | 'shipped_at' | 'status'
type RelationColumn = 'type' | 'order_number' | 'details' | 'status'
const orderDefinitions: GridColumnDefinition<OrderColumn>[] = [
  { key: 'order_number', label: 'ERP订单号' }, { key: 'date', label: '日期' }, { key: 'quantity', label: '数量' }, { key: 'revenue', label: '收入' }, { key: 'status', label: '状态' },
]
const shipmentDefinitions: GridColumnDefinition<ShipmentColumn>[] = [
  { key: 'order_number', label: 'ERP订单号' }, { key: 'carrier', label: '承运商' }, { key: 'tracking', label: '追踪号' }, { key: 'shipped_at', label: '发货日期' }, { key: 'status', label: '状态' },
]
const relationDefinitions: GridColumnDefinition<RelationColumn>[] = [
  { key: 'type', label: '类型' }, { key: 'order_number', label: 'ERP订单号 / 关联对象' }, { key: 'details', label: '关键信息' }, { key: 'status', label: '状态' },
]
const orderGrid = reactive(useAccountGridPreferences<OrderColumn>({ namespace: 'grid.products.show.orders.v1', definitions: orderDefinitions, requiredKeys: ['order_number'] }))
const shipmentGrid = reactive(useAccountGridPreferences<ShipmentColumn>({ namespace: 'grid.products.show.shipments.v1', definitions: shipmentDefinitions, requiredKeys: ['order_number'] }))
const relationGrid = reactive(useAccountGridPreferences<RelationColumn>({ namespace: 'grid.products.show.relations.v1', definitions: relationDefinitions, requiredKeys: ['order_number'] }))
type ProductMedia = { id: number; url: string; alt_text: string | null; position: number; is_primary: boolean }

const props = defineProps<{
  product: Record<string, any>
  externalReferences: ExternalReference[]
  skuAliases: { alias_sku: string; source_type: string; source_reference: string | null }[]
  sales: { units_30d: number; order_count_30d: number; units_90d: number; order_count_90d: number; lifetime_units: number; lifetime_order_count: number; revenue_by_currency: { currency: string; revenue: number }[]; recent_orders: Order[] }
  kolShipping: { units_30d: number; shipment_count_30d: number; units_90d: number; shipment_count_90d: number; lifetime_units: number; lifetime_shipment_count: number }
  inventory: Inventory[]
  shipments: Shipment[]
  collaborations: { id: number; title: string | null; status: string; kol_id: number; kol_name: string | null }[]
  audit: Audit[]
  canOperate: boolean
  canSync?: boolean
  hasWooProductReference?: boolean
  media?: ProductMedia[]
  woo?: { provider_status: string | null; categories: string[]; description: string | null; short_description: string | null; variation_count: number | null; provider_created_at: string | null; provider_modified_at: string | null; last_synced_at: string | null; connection_key: string } | null
  wooSyncHistory?: { status: string; result_code: string | null; error_message: string | null; completed_at: string | null; run_id: number }[]
}>()

const tabs = ['概览', '销售', '库存', '物流', '成本与海关', 'Woo 信息', 'SKU 别名', '业务关联', '审计'] as const
type Tab = typeof tabs[number]
const activeTab = ref<Tab>('概览')
const display = (value: unknown) => value === null || value === undefined || value === '' ? '—' : String(value)
const date = (value: string | null) => value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'
const money = (value: number, currency: string) => new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(value)
const source = (value: string | null) => ({ manual: '手动', woocommerce: 'WooCommerce' } as Record<string, string>)[value ?? ''] ?? display(value)
const aliasSource = (value: string | null) => ({ manual: '手动', woocommerce: 'WooCommerce', legacy_import: '历史导入' } as Record<string, string>)[value ?? ''] ?? display(value)
const auditSource = (value: string | null) => ({ web: '网页', system: '系统', 'sync:outbox': '同步队列', 'woocommerce:inbound': 'Woo 入站', fortify: '登录' } as Record<string, string>)[value ?? ''] ?? display(value)
const inventoryState = (value: string) => ({ confirmed: '已确认', partial_only: '部分同步', manual: '手工维护', never_imported: '未导入' } as Record<string, string>)[value] ?? value
const cost = (value: unknown) => value === null || value === undefined || value === '' ? '—' : `${value} ${display(props.product.cost_currency)}`
const weight = (value: unknown) => value === null || value === undefined || value === '' ? '—' : `${value} kg`
const evidenceSource = (value: string | null | undefined) => ({ woocommerce_reference: 'WooCommerce 参考', manual_entry: '手工录入', manual_measurement: '手工测量', supplier_document: '供应商文件', warehouse_measurement: '仓库实测', historical_logistics: '历史物流', other_documented: '其他已记录资料' } as Record<string, string>)[value ?? ''] ?? '—'
const kolShippingRatio = computed(() => props.sales.lifetime_units > 0 ? props.kolShipping.lifetime_units / props.sales.lifetime_units : null)
const wooGroups = computed(() => Object.values(props.externalReferences.reduce((groups, item) => {
  if (item.provider !== 'woocommerce') return groups
  const group = groups[item.connection_key] ?? { connectionKey: item.connection_key, products: [], variations: [] }
  ;(item.reference_type === 'variation' ? group.variations : group.products).push(item.reference)
  groups[item.connection_key] = group
  return groups
}, {} as Record<string, { connectionKey: string; products: string[]; variations: string[] }>)))
const mediaItems = computed(() => props.media ?? [])
const selectRelative = async (tab: Tab, delta: number) => {
  const index = tabs.indexOf(tab)
  activeTab.value = tabs[(index + delta + tabs.length) % tabs.length]
  await nextTick()
  document.querySelector<HTMLButtonElement>(`[data-product360-tab="${activeTab.value}"]`)?.focus()
}
</script>

<template>
  <Head :title="`${product.name} · Product360`" />
  <AuthenticatedLayout title="产品360" :breadcrumbs="['BYL ERP', '产品', product.name]">
    <section class="mvp-page product360-page">
      <article class="erp-form-card product360-hero"><div><div class="product360-image" aria-label="产品图片占位"><img v-if="product.image_url" :src="product.image_url" :alt="product.name"><span v-else>暂无图片</span></div><div v-if="mediaItems.length > 1" class="product360-thumbnails"><img v-for="item in mediaItems" :key="item.id" :src="item.url" :alt="item.alt_text || product.name"></div></div><div class="product360-identity"><p class="mvp-kicker">{{ product.product_type }}</p><h1>{{ product.name }}</h1><p class="product360-sku">SKU {{ display(product.sku) }}</p><div class="product360-meta"><span>产品 ID：{{ product.id }}</span><span>来源：{{ source(product.source_type) }}</span><span>状态：{{ product.status === 'active' ? '启用' : '停用' }}</span><span>类型：{{ display(product.product_type) }}</span><span v-if="product.woo_sync_status">Woo 同步：{{ product.woo_sync_status === 'synced' ? '已同步' : (product.woo_sync_status === 'not_synced' ? '未同步' : '未配置') }}</span></div><div v-if="wooGroups.length" class="product360-external-summary"><span v-for="group in wooGroups" :key="group.connectionKey">{{ group.connectionKey }} · 产品 {{ group.products.join(', ') || '—' }}<template v-if="group.variations.length"> / 变体 {{ group.variations.join(', ') }}</template></span></div><p v-else class="product360-external-summary">Woo 外部引用：—</p></div><div class="product360-hero-actions"><Link href="/products/list" class="mvp-button mvp-button-secondary">返回列表</Link><a v-if="product.woo_url" :href="product.woo_url" target="_blank" rel="noreferrer" class="mvp-button mvp-button-secondary">查看 Woo</a><Link v-if="canSync && hasWooProductReference" :href="`/products/${product.id}/sync-woocommerce`" method="post" as="button" class="mvp-button mvp-button-secondary">刷新 Woo</Link><button type="button" class="mvp-button mvp-button-secondary" disabled title="当前没有可用的更多操作">更多</button><Link v-if="canOperate" :href="`/products/${product.id}/packaging`" class="mvp-button mvp-button-secondary">包装方案</Link><Link v-if="canOperate" :href="`/products/${product.id}/edit`" class="mvp-button mvp-button-primary">编辑产品</Link></div></article>
      <div class="product360-tabs" role="tablist" aria-label="产品360标签页"><button v-for="tab in tabs" :key="tab" type="button" role="tab" :data-product360-tab="tab" :aria-selected="activeTab === tab" :aria-controls="`product360-${tabs.indexOf(tab)}`" :class="{ active: activeTab === tab }" @click="activeTab = tab" @keydown.left.prevent="selectRelative(tab, -1)" @keydown.right.prevent="selectRelative(tab, 1)">{{ tab }}</button></div>

      <div v-if="activeTab === '概览'" id="product360-0" class="product360-overview" role="tabpanel" tabindex="0">
        <div class="product360-overview-top">
          <article class="erp-form-card product360-summary-card product360-summary-sales"><div class="product360-summary-head"><span class="product360-summary-icon">售</span><h3>销售摘要</h3></div><div class="product360-kpis"><div><small>近 30 天</small><strong>{{ sales.units_30d }}<small class="product360-unit">件</small></strong><span>{{ sales.order_count_30d }} 笔订单</span></div><div><small>近 90 天</small><strong>{{ sales.units_90d }}<small class="product360-unit">件</small></strong><span>{{ sales.order_count_90d }} 笔订单</span></div><div><small>累计</small><strong>{{ sales.lifetime_units }}<small class="product360-unit">件</small></strong><span>{{ sales.lifetime_order_count }} 笔订单</span></div></div></article>
          <article class="erp-form-card product360-summary-card product360-summary-kol"><div class="product360-summary-head"><span class="product360-summary-icon">发</span><h3>网红发货摘要</h3><span class="product360-summary-help" title="仅统计已实际发货的 KOL 履约订单，按发货日期与当前产品数量聚合">ⓘ</span></div><div class="product360-kpis"><div><small>近 30 天</small><strong>{{ kolShipping.units_30d }}<small class="product360-unit">件</small></strong><span>{{ kolShipping.shipment_count_30d }} 笔发货</span></div><div><small>近 90 天</small><strong>{{ kolShipping.units_90d }}<small class="product360-unit">件</small></strong><span>{{ kolShipping.shipment_count_90d }} 笔发货</span></div><div><small>累计</small><strong>{{ kolShipping.lifetime_units }}<small class="product360-unit">件</small></strong><span>{{ kolShipping.lifetime_shipment_count }} 笔发货</span></div></div></article>
        </div>
        <div class="product360-comparison" aria-label="销售与网红发货对比"><strong>销售 / 网红发货 对比</strong><span>销售累计：<b>{{ sales.lifetime_units }}</b></span><span>网红发货累计：<b>{{ kolShipping.lifetime_units }}</b></span><span>发货 / 销售比例：<b>{{ kolShippingRatio === null ? '—' : `${kolShippingRatio.toFixed(2)} 倍` }}</b></span></div>
        <div class="product360-overview-bottom">
          <article class="erp-form-card product360-summary-card"><div class="product360-summary-head"><span class="product360-summary-icon product360-summary-icon-logistics">物</span><h3>物流摘要</h3></div><p>净重：{{ weight(product.weight_kg) }} · 运重：{{ weight(product.shipping_weight_kg) }}</p><p>尺寸：{{ [product.length_cm, product.width_cm, product.height_cm].every(v => v !== null && v !== undefined) ? `${product.length_cm} × ${product.width_cm} × ${product.height_cm} cm` : '—' }}</p></article>
          <article class="erp-form-card product360-summary-card"><div class="product360-summary-head"><span class="product360-summary-icon product360-summary-icon-stock">库</span><h3>库存摘要</h3></div><div v-if="inventory.length" class="product360-stock-summary"><span v-for="row in inventory" :key="row.warehouse_id">{{ row.warehouse_name }}：{{ row.quantity_available === null ? '库存未知' : `${row.quantity_available} 可用` }}</span></div><p v-else class="mvp-empty">暂无活跃仓库库存数据</p></article>
        </div>
        <article class="erp-form-card product360-woo-overview"><div class="product360-summary-head"><span class="product360-summary-icon">W</span><h3>WooCommerce 摘要</h3></div><template v-if="woo"><p>{{ display(woo.short_description || woo.description) }}</p><p>状态：{{ display(woo.provider_status) }} · 分类：{{ woo.categories.join('、') || '—' }} · 最近同步：{{ date(woo.last_synced_at) }}</p></template><p v-else class="mvp-empty">暂无 WooCommerce 产品数据</p></article>
      </div>
      <article v-else-if="activeTab === '销售'" id="product360-1" class="erp-form-card" role="tabpanel" tabindex="0"><div class="product360-panel-head"><h3>销售</h3><div class="product360-price-line"><span>售价 {{ display(product.sale_price) }}</span><span>原价 {{ display(product.regular_price) }}</span></div></div><div class="product360-kpis product360-kpis-sales"><div><small>近 30 天</small><strong>{{ sales.units_30d }}<small class="product360-unit">件</small></strong><span>{{ sales.order_count_30d }} 笔订单</span></div><div><small>近 90 天</small><strong>{{ sales.units_90d }}<small class="product360-unit">件</small></strong><span>{{ sales.order_count_90d }} 笔订单</span></div><div><small>累计</small><strong>{{ sales.lifetime_units }}<small class="product360-unit">件</small></strong><span>{{ sales.lifetime_order_count }} 笔订单</span></div></div><p class="product360-section-label">收入（按原始币种）</p><div v-if="sales.revenue_by_currency.length" class="product360-inline-list"><span v-for="row in sales.revenue_by_currency" :key="row.currency">{{ money(row.revenue, row.currency) }}</span></div><p v-else class="mvp-empty">暂无客户订单收入</p><p class="product360-section-label">最近订单</p><table v-if="sales.recent_orders.length" class="mvp-table"><thead><tr><th v-for="column in orderGrid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="orderGrid.columns" :required-keys="['order_number']" :sync-state="orderGrid.syncState" @toggle="orderGrid.toggle" @start-drag="orderGrid.startDrag" @drop="orderGrid.drop" @reset="orderGrid.reset" /></tr></thead><tbody><tr v-for="order in sales.recent_orders" :key="order.id"><td v-for="column in orderGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link :href="`/customers/orders/${order.id}`" class="mvp-link">{{ display(order.erp_order_id) }}</Link></template><template v-else-if="column.key === 'date'">{{ display(order.order_date) }}</template><template v-else-if="column.key === 'quantity'">{{ order.quantity }}</template><template v-else-if="column.key === 'revenue'">{{ money(order.revenue, order.currency) }}</template><template v-else>{{ order.status }}</template></td></tr></tbody></table><p v-else class="mvp-empty">暂无相关客户订单</p></article>
      <article v-else-if="activeTab === '库存'" id="product360-2" class="erp-form-card" role="tabpanel" tabindex="0"><h3>库存</h3><table v-if="inventory.length" class="mvp-table"><thead><tr><th>仓库</th><th>状态</th><th>在手</th><th>预留</th><th>可用</th><th>入库</th></tr></thead><tbody><tr v-for="row in inventory" :key="row.warehouse_id"><td>{{ row.warehouse_name }}</td><td>{{ inventoryState(row.inventory_state) }}</td><td>{{ row.quantity_on_hand ?? '未知' }}</td><td>{{ row.quantity_reserved ?? '未知' }}</td><td>{{ row.quantity_available ?? '未知' }}</td><td>{{ row.expected_restock_qty ?? '未知' }}</td></tr></tbody></table><p v-else class="mvp-empty">暂无活跃仓库</p></article>
      <article v-else-if="activeTab === '物流'" id="product360-3" class="erp-form-card" role="tabpanel" tabindex="0"><h3>物流</h3><div class="product360-detail-grid"><p>净重：{{ weight(product.weight_kg) }} <small class="product360-evidence">证据：{{ evidenceSource(product.weight_evidence_source_type) }}<template v-if="product.weight_evidence_source_reference"> · {{ product.weight_evidence_source_reference }}</template></small></p><p>运重：{{ weight(product.shipping_weight_kg) }} <small class="product360-evidence">证据：{{ evidenceSource(product.shipping_weight_evidence_source_type) }}<template v-if="product.shipping_weight_evidence_source_reference"> · {{ product.shipping_weight_evidence_source_reference }}</template></small></p><p>尺寸：{{ [product.length_cm, product.width_cm, product.height_cm].every(v => v !== null && v !== undefined) ? `${product.length_cm} × ${product.width_cm} × ${product.height_cm} cm` : '—' }}</p><p>体积重参考：—</p></div><table v-if="shipments.length" class="mvp-table"><thead><tr><th v-for="column in shipmentGrid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="shipmentGrid.columns" :required-keys="['order_number']" :sync-state="shipmentGrid.syncState" @toggle="shipmentGrid.toggle" @start-drag="shipmentGrid.startDrag" @drop="shipmentGrid.drop" @reset="shipmentGrid.reset" /></tr></thead><tbody><tr v-for="shipment in shipments" :key="shipment.id"><td v-for="column in shipmentGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="shipment.erp_order_id" :href="`/customers/orders/${shipment.order_id}`" class="mvp-link">{{ display(shipment.erp_order_id) }}</Link><span v-else>—</span></template><template v-else-if="column.key === 'carrier'">{{ display(shipment.carrier) }}</template><template v-else-if="column.key === 'tracking'">{{ display(shipment.tracking_number) }}</template><template v-else-if="column.key === 'shipped_at'">{{ display(shipment.shipped_at) }}</template><template v-else>{{ display(shipment.status) }}</template></td></tr></tbody></table><p v-else class="mvp-empty">暂无相关发货记录</p></article>
      <article v-else-if="activeTab === '成本与海关'" id="product360-4" class="erp-form-card" role="tabpanel" tabindex="0"><h3>成本与海关</h3><div class="product360-detail-grid"><p>生产成本：{{ cost(product.standard_production_cost) }}</p><p>包材成本：{{ cost(product.standard_packaging_cost) }}</p><p>采购参考成本：{{ cost(product.standard_purchase_cost) }}</p><p>原产国：{{ display(product.country_of_origin) }}</p><p>HS Code：{{ display(product.hs_code) }}</p><p>成本备注：{{ display(product.cost_note) }}</p></div></article>
      <article v-else-if="activeTab === 'Woo 信息'" id="product360-5" class="erp-form-card" role="tabpanel" tabindex="0"><h3>Woo 信息</h3><div v-if="wooGroups.length" class="product360-woo-groups"><section v-for="group in wooGroups" :key="group.connectionKey" class="product360-woo-group"><div class="product360-woo-group-head"><strong>{{ group.connectionKey }}</strong><span>WooCommerce</span></div><div class="product360-woo-group-refs"><span v-for="reference in group.products" :key="group.connectionKey + '-product-' + reference">Product {{ reference }}</span><span v-for="reference in group.variations" :key="group.connectionKey + '-variation-' + reference">Variation {{ reference }}</span></div></section></div><div v-if="woo" class="product360-detail-grid"><p>状态：{{ display(woo.provider_status) }}</p><p>分类：{{ woo.categories.join('、') || '—' }}</p><p>变体数：{{ woo.variation_count ?? '—' }}</p><p>创建：{{ date(woo.provider_created_at) }}</p><p>修改：{{ date(woo.provider_modified_at) }}</p><p>最近同步：{{ date(woo.last_synced_at) }}</p></div><p v-if="woo?.description" class="product360-woo-description">{{ woo.description }}</p><p v-else-if="!woo" class="mvp-empty">暂无 Woo 外部引用</p><div v-if="wooSyncHistory?.length" class="product360-sync-history"><h4>最近同步历史</h4><p v-for="entry in wooSyncHistory" :key="entry.run_id">{{ entry.result_code || entry.status }} · {{ date(entry.completed_at) }}<span v-if="entry.error_message"> · {{ entry.error_message }}</span></p></div><p class="product360-compat-note">Legacy Woo ID（兼容证据）：{{ product.woocommerce_product_id ?? '—' }}</p></article>
      <article v-else-if="activeTab === 'SKU 别名'" id="product360-6" class="erp-form-card product360-alias-panel" role="tabpanel" tabindex="0"><div class="product360-alias-canonical"><span>Canonical SKU</span><strong>{{ display(product.sku) }}</strong></div><table v-if="skuAliases.length" class="mvp-table product360-alias-table"><thead><tr><th>Alias SKU</th><th>来源</th><th>来源引用</th></tr></thead><tbody><tr v-for="alias in skuAliases" :key="alias.alias_sku"><td><strong>{{ alias.alias_sku }}</strong></td><td>{{ aliasSource(alias.source_type) }}</td><td>{{ display(alias.source_reference) }}</td></tr></tbody></table><p v-else class="mvp-empty">暂无 SKU 别名</p></article>
      <article v-else-if="activeTab === '业务关联'" id="product360-7" class="erp-form-card product360-relations" role="tabpanel" tabindex="0"><h3>业务关联</h3><table v-if="sales.recent_orders.length || shipments.length || collaborations.length" class="mvp-table product360-compact-table product360-relations-table"><thead><tr><th v-for="column in relationGrid.visibleColumns" :key="column.key">{{ column.label }}</th><ColumnSettingsHeader :columns="relationGrid.columns" :required-keys="['order_number']" :sync-state="relationGrid.syncState" @toggle="relationGrid.toggle" @start-drag="relationGrid.startDrag" @drop="relationGrid.drop" @reset="relationGrid.reset" /></tr></thead><tbody><tr v-for="order in sales.recent_orders" :key="'rel-order-' + order.id"><td v-for="column in relationGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'type'">订单</template><template v-else-if="column.key === 'order_number'"><Link :href="'/customers/orders/' + order.id" class="mvp-link">{{ display(order.erp_order_id) }}</Link></template><template v-else-if="column.key === 'details'">{{ display(order.order_date) }} · 数量 {{ order.quantity }}</template><template v-else>{{ order.status }}</template></td></tr><tr v-for="shipment in shipments" :key="'rel-shipment-' + shipment.id"><td v-for="column in relationGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'type'">发货</template><template v-else-if="column.key === 'order_number'"><Link v-if="shipment.erp_order_id" :href="'/customers/orders/' + shipment.order_id" class="mvp-link">{{ display(shipment.erp_order_id) }}</Link><span v-else>—</span></template><template v-else-if="column.key === 'details'">{{ display(shipment.tracking_number) }} · {{ display(shipment.carrier) }}</template><template v-else>{{ display(shipment.status) }}</template></td></tr><tr v-for="collaboration in collaborations" :key="'rel-kol-' + collaboration.id"><td v-for="column in relationGrid.visibleColumns" :key="column.key"><template v-if="column.key === 'type'">KOL</template><template v-else-if="column.key === 'order_number'"><Link :href="'/kol/library/' + collaboration.kol_id" class="mvp-link">{{ display(collaboration.kol_name) }}</Link></template><template v-else-if="column.key === 'details'">{{ display(collaboration.title) }}</template><template v-else>{{ collaboration.status }}</template></td></tr></tbody></table><p v-else class="mvp-empty">暂无业务关联</p></article>
      <article v-else-if="activeTab === '审计'" id="product360-8" class="erp-form-card" role="tabpanel" tabindex="0"><h3>审计</h3><table v-if="audit.length" class="mvp-table"><thead><tr><th>动作</th><th>操作者</th><th>来源</th><th>时间</th></tr></thead><tbody><tr v-for="record in audit" :key="`${record.created_at}-${record.action}`"><td>{{ record.action_label }}</td><td>{{ record.actor_label ?? '—' }}</td><td>{{ auditSource(record.source) }}</td><td>{{ date(record.created_at) }}</td></tr></tbody></table><p v-else class="mvp-empty">暂无产品审计记录</p></article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.product360-page { max-width: 1440px; }
.product360-hero { display: flex; gap: 20px; align-items: center; margin-bottom: 12px; padding: 18px 20px; }
.product360-image { width: 104px; height: 104px; flex: 0 0 104px; border-radius: 10px; background: #eef1f4; color: #78838e; display: grid; place-items: center; font-size: 13px; }
.product360-image img { width: 100%; height: 100%; border-radius: 10px; object-fit: cover; }
.product360-thumbnails { display: flex; gap: 4px; margin-top: 6px; max-width: 104px; overflow: auto; }
.product360-thumbnails img { width: 30px; height: 30px; flex: 0 0 30px; border-radius: 4px; object-fit: cover; }
.product360-woo-overview { margin-bottom: 0 !important; }
.product360-woo-description { white-space: pre-wrap; line-height: 1.6; }
.product360-sync-history { margin-top: 18px; border-top: 1px solid #e3e7ea; padding-top: 12px; }
.product360-sync-history h4 { margin: 0 0 8px; }
.product360-sync-history p { margin: 4px 0; color: #59636e; font-size: 13px; }
.product360-identity { min-width: 0; flex: 1 1 auto; }
.product360-hero-actions { margin-left: auto; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex: 0 0 auto; }
.product360-identity h1 { margin: 2px 0 4px; font-size: 22px; }
.product360-sku { color: #59636e; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.product360-meta, .product360-external-summary { display: flex; flex-wrap: wrap; gap: 8px 18px; color: #59636e; font-size: 13px; }
.product360-external-summary { margin-top: 6px; color: #52646c; font-size: 12px; }
.product360-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid #d9dee3; margin-bottom: 14px; }
.product360-tabs button { border: 0; border-bottom: 2px solid transparent; background: transparent; padding: 9px 12px; color: #59636e; font: inherit; font-size: 13px; cursor: pointer; }
.product360-tabs button:hover, .product360-tabs button:focus-visible { color: #1f5f8b; background: #f4f7f9; outline: none; }
.product360-tabs button.active { color: #1f5f8b; border-bottom-color: currentColor; font-weight: 600; }
.product360-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.product360-overview { display: grid; gap: 14px; }
.product360-overview-top, .product360-overview-bottom { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.product360-summary-card { margin-bottom: 0 !important; }
.product360-summary-head { display: flex; align-items: center; gap: 9px; margin-bottom: 14px; }
.product360-summary-head h3 { margin: 0; }
.product360-summary-icon { width: 30px; height: 30px; border-radius: 999px; display: grid; place-items: center; background: #e5f6ef; color: #187b62; font-size: 12px; font-weight: 700; }
.product360-summary-kol .product360-summary-icon { background: #f1eafa; color: #7548a8; }
.product360-summary-icon-logistics { background: #eaf3fc; color: #2b69a1; }
.product360-summary-icon-stock { background: #fff2e6; color: #a85d20; }
.product360-summary-help { color: #8a96a1; font-size: 12px; cursor: help; }
.product360-summary-sales .product360-kpis div { background: #f1faf7; }
.product360-summary-kol .product360-kpis div { background: #f8f5fc; }
.product360-comparison { display: grid; grid-template-columns: 1.25fr repeat(3, 1fr); align-items: center; gap: 12px; padding: 12px 18px; border: 1px solid #d5e6f6; border-radius: 10px; background: #f2f8fe; font-size: 13px; }
.product360-comparison span { padding-left: 16px; border-left: 1px solid #d5e2ee; text-align: center; }
.product360-comparison b { margin-left: 5px; font-size: 16px; color: #205f91; }
.product360-summary-kol + * { min-width: 0; }
.product360-stock-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 18px; }
.product360-stock-summary span { min-width: 0; }
.product360-page article { margin-bottom: 16px; }
.product360-page h3 { margin: 0 0 14px; font-size: 17px; }
.product360-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.product360-kpis div { padding: 12px; background: #f7f8f9; border-radius: 8px; }
.product360-kpis small, .product360-kpis span { display: block; color: #68737e; font-size: 12px; }
.product360-kpis strong { display: block; margin: 4px 0; font-size: 24px; }
.product360-kpis .product360-unit { display: inline; margin-left: 4px; color: #68737e; font-size: 12px; font-weight: 600; }
.product360-panel-head { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
.product360-price-line { display: flex; gap: 16px; color: #68737e; font-size: 12px; }
.product360-kpis-sales { margin-bottom: 12px; }
.product360-panel-head { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
.product360-price-line { display: flex; gap: 16px; color: #68737e; font-size: 12px; }
.product360-kpis-sales { margin-bottom: 12px; }
.product360-woo-groups { display: grid; gap: 10px; }
.product360-woo-group { border-top: 1px solid #e3e7ea; padding-top: 10px; }
.product360-woo-group:first-child { border-top: 0; padding-top: 0; }
.product360-woo-group-head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 6px; }
.product360-woo-group-head span { color: #68737e; font-size: 12px; }
.product360-woo-group-refs { display: flex; flex-wrap: wrap; gap: 6px; }
.product360-woo-group-refs span { padding: 5px 8px; background: #f1f5f7; border-radius: 6px; font-size: 12px; }
.product360-inline-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.product360-inline-list span { padding: 7px 10px; background: #f1f5f7; border-radius: 6px; }
.product360-detail-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px 20px; }
.product360-detail-grid p { margin: 0 0 10px; }
.product360-evidence { margin-left: 6px; color: #78838e; font-weight: 400; }
.product360-section-label { font-weight: 600; margin: 14px 0 7px; }
.product360-relations { padding-top: 18px; padding-bottom: 18px; }
.product360-compact-table { margin-bottom: 4px; }
.product360-alias-panel { padding: 16px 20px; }
.product360-alias-canonical { display: flex; align-items: baseline; gap: 12px; margin-bottom: 10px; }
.product360-alias-canonical span { color: #68737e; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
.product360-alias-canonical strong { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 15px; }
.product360-alias-table th, .product360-alias-table td { padding-top: 8px; padding-bottom: 8px; }
.product360-alias-table td:first-child { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.product360-compat-note { margin-top: 12px; color: #68737e; font-size: 12px; }
@media (max-width: 900px) { .product360-hero { align-items: flex-start; flex-wrap: wrap; } .product360-hero-actions { width: 100%; justify-content: flex-start; margin-left: 124px; } }
@media (max-width: 760px) { .product360-grid, .product360-detail-grid, .product360-overview-top, .product360-overview-bottom { grid-template-columns: 1fr; } .product360-kpis, .product360-stock-summary { grid-template-columns: 1fr; } .product360-comparison { grid-template-columns: 1fr; } .product360-comparison span { border-left: 0; padding-left: 0; text-align: left; } .product360-hero-actions { margin-left: 0; } }
</style>
