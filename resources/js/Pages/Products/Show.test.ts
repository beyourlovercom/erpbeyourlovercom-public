import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }))

const baseProps = {
  product: { id: 1, name: 'Demo Product', sku: 'DEMO-1', source_type: 'woocommerce', status: 'active', product_type: 'variable', woocommerce_product_id: 77, woo_url: null, image_url: null, weight_kg: null, shipping_weight_kg: null, length_cm: null, width_cm: null, height_cm: null, standard_production_cost: null, standard_packaging_cost: null, standard_purchase_cost: null, cost_currency: null, cost_note: null, country_of_origin: null, hs_code: null },
  externalReferences: [
    { provider: 'woocommerce', connection_key: 'shop-a', reference: '101', reference_type: 'product' },
    { provider: 'woocommerce', connection_key: 'shop-a', reference: '102', reference_type: 'variation' },
  ],
  skuAliases: [],
  sales: { units_30d: 0, order_count_30d: 0, units_90d: 0, order_count_90d: 0, lifetime_units: 0, lifetime_order_count: 0, revenue_by_currency: [], recent_orders: [] },
  kolShipping: { units_30d: 0, shipment_count_30d: 0, units_90d: 0, shipment_count_90d: 0, lifetime_units: 0, lifetime_shipment_count: 0 },
  inventory: [], shipments: [], collaborations: [], audit: [], canOperate: false, canSync: false, hasWooProductReference: false,
  media: [], woo: null, wooSyncHistory: [],
}

describe('Products/Show Product360', () => {
  it('renders nine real tabs with overview as the only default panel', () => {
    const wrapper = mount(Show, { props: baseProps })
    expect(wrapper.findAll('[role="tab"]')).toHaveLength(9)
    expect(wrapper.find('#product360-0').exists()).toBe(true)
    expect(wrapper.find('#product360-1').exists()).toBe(false)
    expect(wrapper.text()).toContain('暂无图片')
  })

  it('renders the Owner-approved KOL shipping summary and sales comparison on overview', () => {
    const wrapper = mount(Show, { props: {
      ...baseProps,
      sales: { ...baseProps.sales, units_30d: 2, order_count_30d: 1, units_90d: 5, order_count_90d: 2, lifetime_units: 9, lifetime_order_count: 3 },
      kolShipping: { units_30d: 12, shipment_count_30d: 3, units_90d: 28, shipment_count_90d: 7, lifetime_units: 66, lifetime_shipment_count: 15 },
    } })
    const overview = wrapper.find('#product360-0').text()
    expect(overview).toContain('网红发货摘要')
    expect(overview).toContain('2件')
    expect(overview).toContain('9件')
    expect(overview).toContain('12件')
    expect(overview).toContain('3 笔发货')
    expect(overview).toContain('28件')
    expect(overview).toContain('7 笔发货')
    expect(overview).toContain('66件')
    expect(overview).toContain('15 笔发货')
    expect(overview).toContain('销售 / 网红发货 对比')
    expect(overview).toContain('7.33 倍')
  })

  it('does not fabricate an infinite KOL-shipping ratio when lifetime sales are zero', () => {
    const wrapper = mount(Show, { props: { ...baseProps, kolShipping: { ...baseProps.kolShipping, lifetime_units: 5, lifetime_shipment_count: 1 } } })
    const comparison = wrapper.find('.product360-comparison').text()
    expect(comparison).toContain('发货 / 销售比例：—')
    expect(comparison).not.toContain('Infinity')
  })

  it('switches panels without expanding the full workspace', async () => {
    const wrapper = mount(Show, { props: baseProps })
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === '销售')!.trigger('click')
    expect(wrapper.find('#product360-0').exists()).toBe(false)
    expect(wrapper.find('#product360-1').exists()).toBe(true)
    expect(wrapper.find('#product360-2').exists()).toBe(false)
  })

  it('supports keyboard tab navigation with button semantics', async () => {
    const wrapper = mount(Show, { props: baseProps, attachTo: document.body })
    const overviewTab = wrapper.find('[role="tab"]')
    expect(overviewTab.attributes('type')).toBe('button')
    expect(overviewTab.attributes('aria-selected')).toBe('true')
    await overviewTab.trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.find('#product360-1').exists()).toBe(true)
    const activeTab = wrapper.find('[role="tab"][aria-selected="true"]')
    expect(activeTab.text()).toBe('销售')
    expect(activeTab.element).toBe(document.activeElement)
    wrapper.unmount()
  })

  it('keeps product identity only once and merges page actions into the Hero', () => {
    const wrapper = mount(Show, { props: { ...baseProps, canOperate: true } })
    expect(wrapper.find('.mvp-page-heading').exists()).toBe(false)
    expect(wrapper.find('.product360-hero-actions').exists()).toBe(true)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.find('.product360-hero-actions').text()).toContain('返回列表')
    expect(wrapper.find('.product360-hero-actions').text()).toContain('编辑产品')
  })

  it('shows compact 30/90/lifetime sales metrics and keeps revenue grouped by currency', async () => {
    const wrapper = mount(Show, { props: {
      ...baseProps,
      product: { ...baseProps.product, sale_price: '25.00', regular_price: '30.00' },
      sales: { units_30d: 2, order_count_30d: 1, units_90d: 5, order_count_90d: 2, lifetime_units: 9, lifetime_order_count: 3, revenue_by_currency: [{ currency: 'EUR', revenue: 90 }, { currency: 'USD', revenue: 170 }], recent_orders: [] },
    } })
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === '销售')!.trigger('click')
    const panel = wrapper.find('#product360-1').text()
    expect(panel).toContain('近 30 天')
    expect(panel).toContain('近 90 天')
    expect(panel).toContain('累计')
    expect(panel).toContain('售价 25.00')
    expect(panel).toContain('原价 30.00')
    expect(panel).toContain('€90.00')
    expect(panel).toContain('US$170.00')
    expect(panel).toContain('收入（按原始币种）')
  })

  it('renders local Product media and exposes refresh only for an authorized configured Product ref', () => {
    const wrapper = mount(Show, { props: {
      ...baseProps,
      product: { ...baseProps.product, image_url: '/products/media/9', woo_sync_status: 'synced' },
      media: [
        { id: 9, url: '/products/media/9', alt_text: 'Primary', position: 0, is_primary: true },
        { id: 10, url: '/products/media/10', alt_text: 'Second', position: 1, is_primary: false },
      ],
      canSync: true,
      hasWooProductReference: true,
    } })
    expect(wrapper.find('.product360-image img').attributes('src')).toBe('/products/media/9')
    expect(wrapper.findAll('.product360-thumbnails img')).toHaveLength(2)
    expect(wrapper.text()).toContain('Woo 同步：已同步')
    expect(wrapper.find('a[href="/products/1/sync-woocommerce"]').exists()).toBe(true)

    const noProductRef = mount(Show, { props: { ...baseProps, canSync: true, hasWooProductReference: false } })
    expect(noProductRef.find('a[href="/products/1/sync-woocommerce"]').exists()).toBe(false)
  })

  it('distinguishes mapped-not-synced from unconfigured Woo state', () => {
    const mapped = mount(Show, { props: { ...baseProps, product: { ...baseProps.product, woo_sync_status: 'not_synced' }, hasWooProductReference: true } })
    expect(mapped.text()).toContain('Woo 同步：未同步')
    const manual = mount(Show, { props: { ...baseProps, product: { ...baseProps.product, woo_sync_status: 'not_configured' }, externalReferences: [] } })
    expect(manual.text()).toContain('Woo 同步：未配置')
    expect(manual.text()).toContain('暂无 WooCommerce 产品数据')
  })

  it('shows provider snapshot evidence and sync history while preserving M1.4 external refs', async () => {
    const wrapper = mount(Show, { props: {
      ...baseProps,
      woo: { provider_status: 'publish', categories: ['Category A', 'Category B'], description: 'Provider description', short_description: 'Short provider description', variation_count: 2, provider_created_at: '2026-08-01T10:00:00Z', provider_modified_at: '2026-09-03T08:00:00Z', last_synced_at: '2026-09-03T09:00:00Z', connection_key: 'shop-a' },
      wooSyncHistory: [{ status: 'succeeded', result_code: 'updated', error_message: null, completed_at: '2026-09-03T09:00:00Z', run_id: 15 }],
    } })
    expect(wrapper.find('#product360-0').text()).toContain('Short provider description')
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === 'Woo 信息')!.trigger('click')
    const panel = wrapper.find('#product360-5')
    expect(panel.text()).toContain('shop-a')
    expect(panel.text()).toContain('Product 101')
    expect(panel.text()).toContain('Variation 102')
    expect(panel.text()).toContain('Category A、Category B')
    expect(panel.text()).toContain('变体数：2')
    expect(panel.text()).toContain('Provider description')
    expect(panel.text()).toContain('最近同步历史')
    expect(panel.text()).toContain('updated')
  })

  it('uses M1.4 external references as the Hero Woo identity', () => {
    const wrapper = mount(Show, { props: baseProps })
    expect(wrapper.text()).toContain('shop-a · 产品 101 / 变体 102')
    expect(wrapper.text()).not.toContain('Woo：77')
  })

  it('groups Woo Product and Variation refs by connection', async () => {
    const wrapper = mount(Show, { props: {
      ...baseProps,
      externalReferences: [
        { provider: 'woocommerce', connection_key: 'shop-a', reference: '101', reference_type: 'product' },
        { provider: 'woocommerce', connection_key: 'shop-a', reference: '102', reference_type: 'variation' },
        { provider: 'woocommerce', connection_key: 'shop-b', reference: '201', reference_type: 'product' },
        { provider: 'woocommerce', connection_key: 'shop-b', reference: '202', reference_type: 'variation' },
      ],
    } })
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === 'Woo 信息')!.trigger('click')
    const groups = wrapper.findAll('.product360-woo-group')
    expect(groups).toHaveLength(2)
    expect(groups[0].text()).toContain('shop-a')
    expect(groups[0].text()).toContain('Product 101')
    expect(groups[0].text()).toContain('Variation 102')
    expect(groups[1].text()).toContain('shop-b')
    expect(groups[1].text()).toContain('Product 201')
    expect(groups[1].text()).toContain('Variation 202')
  })

  it('shows truthful incoming stock and unknown actor labels', async () => {
    const wrapper = mount(Show, { props: {
      ...baseProps,
      inventory: [{ warehouse_id: 1, warehouse_name: 'US West', warehouse_slug: 'us-west', inventory_state: 'manual', quantity_on_hand: 8, quantity_reserved: 2, quantity_available: 6, expected_restock_qty: 12 }],
      audit: [{ action: 'product.updated', action_label: '更新产品', actor_label: null, source: 'web', created_at: '2026-09-02T05:00:00Z', reason: null }],
    } })
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === '库存')!.trigger('click')
    expect(wrapper.find('#product360-2').text()).toContain('12')
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === '审计')!.trigger('click')
    expect(wrapper.find('#product360-8').text()).toContain('—')
    expect(wrapper.find('#product360-8').text()).not.toContain('系统')
  })

  it('shows adopted M1.3 evidence sources without inventing volumetric weight', async () => {
    const wrapper = mount(Show, { props: { ...baseProps, product: { ...baseProps.product, weight_kg: '0.4200', shipping_weight_kg: '0.5800', weight_evidence_source_type: 'warehouse_measurement', shipping_weight_evidence_source_type: 'historical_logistics', length_cm: '18.00', width_cm: '9.00', height_cm: '6.00' } } })
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === '物流')!.trigger('click')
    const panel = wrapper.find('#product360-3').text()
    expect(panel).toContain('净重：0.4200 kg')
    expect(panel).toContain('证据：仓库实测')
    expect(panel).toContain('运重：0.5800 kg')
    expect(panel).toContain('证据：历史物流')
    expect(panel).toContain('18.00 × 9.00 × 6.00 cm')
    expect(panel).toContain('体积重参考：—')
  })

  it('renders SKU aliases as a compact read-only identity table with source evidence', async () => {
    const wrapper = mount(Show, { props: { ...baseProps, skuAliases: [{ alias_sku: 'LEGACY-001', source_type: 'legacy_import', source_reference: 'legacy-sheet' }, { alias_sku: 'WOO-VAR-001', source_type: 'woocommerce', source_reference: 'shop-a:102' }] } })
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === 'SKU 别名')!.trigger('click')
    const panel = wrapper.find('#product360-6')
    expect(panel.text()).toContain('Canonical SKU')
    expect(panel.text()).toContain('DEMO-1')
    expect(panel.text()).toContain('LEGACY-001')
    expect(panel.text()).toContain('历史导入')
    expect(panel.text()).toContain('legacy-sheet')
    expect(panel.text()).toContain('WOO-VAR-001')
    expect(panel.text()).toContain('WooCommerce')
    expect(panel.findAll('input')).toHaveLength(0)
    expect(panel.findAll('button')).toHaveLength(0)
  })

  it('renders canonical Order, Shipment, and KOL relations as compact read-only tables', async () => {
    const wrapper = mount(Show, { props: { ...baseProps, sales: { ...baseProps.sales, recent_orders: [{ id: 8, erp_order_id: '007-0825-c0008', external_order_number: 'M15-S4-30D', number: 'M15-S4-30D', order_date: '2026-08-25', status: 'pending_shipment', quantity: 2, revenue: 50, currency: 'USD' }] }, shipments: [{ id: 5, order_id: 8, erp_order_id: '007-0825-c0008', external_order_number: 'M15-S4-30D', order_number: 'M15-S4-30D', carrier: 'UPS', tracking_number: 'M15-S9-TRACK-001', shipped_at: '2026-09-01', status: 'shipped' }], collaborations: [{ id: 1, title: 'M1.5 Product360 UAT Collaboration', status: 'active', kol_id: 1, kol_name: 'M1.5 UAT Creator' }] } })
    await wrapper.findAll('[role="tab"]').find(tab => tab.text() === '业务关联')!.trigger('click')
    const panel = wrapper.find('#product360-7')
    expect(panel.text()).toContain('007-0825-c0008')
    expect(panel.text()).not.toContain('来源订单：M15-S4-30D')
    expect(panel.text()).toContain('M15-S9-TRACK-001')
    expect(panel.text()).toContain('UPS')
    expect(panel.text()).toContain('M1.5 UAT Creator')
    expect(panel.find('a[href="/customers/orders/8"]').exists()).toBe(true)
    expect(panel.find('a[href="/kol/library/1"]').exists()).toBe(true)
    expect(panel.findAll('input')).toHaveLength(0)
  })

  it('keeps edit operator-only and preserves the volumetric-weight boundary', async () => {
    const viewer = mount(Show, { props: baseProps })
    expect(viewer.find('a[href="/products/1/edit"]').exists()).toBe(false)
    await viewer.findAll('[role="tab"]').find(tab => tab.text() === '物流')!.trigger('click')
    expect(viewer.text()).toContain('体积重参考：—')
    const operator = mount(Show, { props: { ...baseProps, canOperate: true } })
    expect(operator.find('a[href="/products/1/edit"]').exists()).toBe(true)
  })
})
