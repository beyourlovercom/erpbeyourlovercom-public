import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import FulfillmentAllocation from './FulfillmentAllocation.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { template: '<main><slot /></main>' },
}))

const warehouses = [
  { id: 5, slug: 'factory', code: 'FACTORY', name: '中国工厂', country_code: 'CN', can_auto_allocate: false },
  { id: 1, slug: 'us-la', code: 'US-LA', name: '美西MorePlus', country_code: 'US', can_auto_allocate: true },
  { id: 2, slug: 'us-ny', code: 'US-NY', name: '美东纽约', country_code: 'US', can_auto_allocate: true },
  { id: 7, slug: 'us-3sm', code: 'US-SM', name: '美国3世迈仓', country_code: 'US', can_auto_allocate: true },
  { id: 3, slug: 'de', code: 'DE', name: '德国天运', country_code: 'DE', can_auto_allocate: true },
  { id: 4, slug: 'ca', code: 'CA', name: '加拿大仓', country_code: 'CA', can_auto_allocate: true },
]

const carrierOptions = [
  { value: 'USPS', label: 'USPS / 美国邮政' },
  { value: '4PX', label: '4PX / 速达' },
  { value: 'DHL', label: 'DHL' },
  { value: 'Canada Post', label: 'Canada Post / 加拿大邮政' },
]
const carrierRoutes = {
  FACTORY: { carrier: '4PX', carrier_label: '4PX / 速达', service: 'SD经济挂号-带电', automatic: true },
  'US-LA': { carrier: 'USPS', carrier_label: 'USPS / 美国邮政', service: null, automatic: true },
  'US-NY': { carrier: 'USPS', carrier_label: 'USPS / 美国邮政', service: null, automatic: true },
  'US-SM': { carrier: 'USPS', carrier_label: 'USPS / 美国邮政', service: null, automatic: true },
  DE: { carrier: null, carrier_label: null, service: null, automatic: false },
  CA: { carrier: null, carrier_label: null, service: null, automatic: false },
}

const itemA = {
  id: 101, product_id: 11, product_url: '/products/11', sku: 'MF414A', source_sku: 'MF414A', resolved_fulfillment_sku: null, product_summary: 'Product A', quantity: 1, is_gift: false, gift_options_by_warehouse: {},
  availability: [{ warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西MorePlus', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 5, quantity_reserved: 0, quantity_available: 5 }],
}
const itemB = {
  id: 102, product_id: 12, product_url: '/products/12', sku: 'SV205', source_sku: 'SV205', resolved_fulfillment_sku: null, product_summary: 'Product B', quantity: 1, is_gift: false, gift_options_by_warehouse: {},
  availability: [{ warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西MorePlus', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 }],
}

const giftItem = {
  id: 103, product_id: 13, product_url: '/products/13', sku: 'gift', source_sku: 'gift', resolved_fulfillment_sku: null, product_summary: 'Website Gift', quantity: 1, is_gift: true,
  availability: [],
  gift_options_by_warehouse: {
    'US-SM': [{ mapping_id: 501, warehouse_id: 7, warehouse_code: 'US-SM', warehouse_name: '美国3世迈仓', fulfillment_product_id: 31, fulfillment_sku: 'GIFT-SM-01', fulfillment_name: '世迈礼品', quantity_available: 8, can_fulfill: true, priority: 1 }],
    'US-LA': [{ mapping_id: 502, warehouse_id: 1, warehouse_code: 'US-LA', warehouse_name: '美西MorePlus', fulfillment_product_id: 32, fulfillment_sku: 'GIFT-MP-02', fulfillment_name: 'MorePlus 礼品', quantity_available: 0, can_fulfill: false, priority: 1 }],
  },
}

const splitPlan = {
  plan_key: 'split:abc', plan_type: 'split' as const, is_split: true, shipment_count: 2, label: '拆分发货', effective_cost: 83,
  cost: { status: 'ready', amount: 78, currency: 'CNY', source: 'test' }, execution_status: 'preview_only_multi_shipment_schema_required',
  segments: [
    { source_type: 'warehouse' as const, source_code: 'US-LA', warehouse_id: 1, warehouse_name: '美西MorePlus', carrier: 'USPS', service: null, carrier_selection_source: 'automatic', items: [{ product_id: 11, quantity: 1, sku: 'MF414A', product_summary: 'Product A' }] },
    { source_type: 'factory' as const, source_code: 'FACTORY', warehouse_id: 5, warehouse_name: '中国工厂', carrier: '4PX', service: 'SD经济挂号-带电', carrier_selection_source: 'automatic', items: [{ product_id: 12, quantity: 1, sku: 'SV205', product_summary: 'Product B' }] },
  ],
}
const factoryPlan = {
  plan_key: 'factory:def', plan_type: 'factory' as const, is_split: false, shipment_count: 1, label: '整单中国工厂发货', effective_cost: 106,
  cost: { status: 'ready', amount: 106, currency: 'CNY', source: 'test' }, execution_status: 'manual_review_only',
  segments: [{ source_type: 'factory' as const, source_code: 'FACTORY', warehouse_id: 5, warehouse_name: '中国工厂', carrier: '4PX', service: 'SD经济挂号-带电', carrier_selection_source: 'automatic', items: [{ product_id: 11, quantity: 1, sku: 'MF414A' }, { product_id: 12, quantity: 1, sku: 'SV205' }] }],
}

const systemOrder = {
  id: 10, erp_order_id: '007-0911-40414', order_url: '/customers/orders/10', recipient_name: 'John Smith', phone: '+1 555 1000', country: 'US', province_state: 'CA', city: 'Los Angeles', address_line_1: '123 West Test Ave', address_line_2: 'Apt 2', postal_code: '90001',
  us_fulfillment_zone: { status: 'matched', zone: 'west' as const, zone_label: '美西区', postal_code: '90001', zip3: 900, state: 'CA', warehouse_priority: ['US-SM', 'US-LA'] },
  carrier_routes: carrierRoutes,
  item_count: 2, items: [itemA, itemB],
  allocation_decision: {
    status: 'system_recommendation' as const, status_label: '系统推荐', reason_code: 'lowest_complete_cost_plan', manual_reasons: [], recommended_plan_key: splitPlan.plan_key,
    recommended_plan: splitPlan, baseline_plan: factoryPlan, estimated_cost: 83, currency: 'CNY', estimated_saving: 23, plans: [splitPlan, factoryPlan], can_use_recommendation: true,
    policy: { system_is_advisory: true, human_confirmation_required: true, missing_data_falls_back_to_manual: true, same_order_item_quantity_split_allowed: false, max_sources: 6, split_pattern: 'multi_warehouse_or_factory' },
  },
  fulfillment_allocation: null,
}
const manualOrder = {
  ...systemOrder, id: 11, erp_order_id: '007-0911-40415',
  allocation_decision: {
    ...systemOrder.allocation_decision,
    status: 'manual_review' as const, status_label: '需要人工判断', manual_reasons: ['产品包装成本数据不足', '拆单附加成本尚未配置'], recommended_plan_key: null, recommended_plan: null, baseline_plan: null,
    estimated_cost: null, currency: null, estimated_saving: null, can_use_recommendation: false,
  },
}

const giftOrder = {
  ...manualOrder, id: 12, erp_order_id: '007-0911-40416', item_count: 2, items: [itemA, giftItem],
  allocation_decision: {
    ...manualOrder.allocation_decision,
    manual_reasons: ['订单包含 gift 礼品占位 SKU，需要 Vita 确认实际履约 SKU'],
  },
}

const processedOrder = {
  ...manualOrder,
  fulfillment_allocation: {
    status: 'submitted',
    decision_source: 'manual' as const,
    selected_plan_key: 'manual:split',
    decision_note: '人工拆分 UAT',
    submitted_at: '2026-09-11T16:00:00+08:00',
    plan_snapshot: {
      ...splitPlan,
      derived_orders: [
        { order_id: 201, erp_order_id: '007-0911-40415A', derived: true, suffix: 'A', warehouse_id: 5, warehouse_code: 'FACTORY', warehouse_name: '中国工厂', carrier: '4PX', service: 'SD经济挂号-带电', carrier_selection_source: 'automatic', items: [{ sku: 'MF414A', product_summary: 'Product A', quantity: 1 }] },
        { order_id: 202, erp_order_id: '007-0911-40415B', derived: true, suffix: 'B', warehouse_id: 1, warehouse_code: 'US-LA', warehouse_name: '美西MorePlus', carrier: 'USPS', service: null, carrier_selection_source: 'automatic', items: [{ sku: 'SV205', product_summary: 'Product B', quantity: 1 }] },
      ],
    },
  },
}

const props = {
  orders: { data: [systemOrder, manualOrder], current_page: 1, last_page: 1, per_page: 50, total: 2 },
  warehouses,
  carrierOptions,
  pendingTotal: 2,
  canOperate: true,
}

function mountPage(customProps: any = props) {
  return mount(FulfillmentAllocation, { props: customProps, attachTo: document.body })
}

afterEach(() => {
  document.body.innerHTML = ''
  vi.unstubAllGlobals()
})

describe('Vita Fulfillment Allocation V1.1', () => {
  it('renders canonical table and opens 查看方案 as a modal with recommendation evidence', async () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('待分仓')
    expect(wrapper.text()).toContain('V1.1')
    expect(wrapper.text()).toContain('123 West Test Ave, Apt 2, Los Angeles, CA, 90001, US')
    expect(wrapper.text()).toContain('美西区')
    expect(wrapper.text()).toContain('美国3世迈仓 → 美西MorePlus')
    expect(wrapper.text()).toContain('007-0911-40414')
    expect(wrapper.text()).toContain('拆分发货')

    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('履约方案详情')
    expect(document.body.textContent).toContain('系统候选方案：拆分发货')
    expect(document.body.textContent).toContain('美西MorePlus')
    expect(document.body.textContent).toContain('中国工厂')
    expect(document.body.textContent).toContain('采用推荐并提交分仓')
    expect(document.body.textContent).toContain('估算成本：¥83.00')
    expect(document.body.textContent).toContain('地图区域划分')
    expect(document.body.textContent).toContain('90001')
    expect(document.body.textContent).toContain('美西区')
  })

  it('keeps warehouse and carrier controls at the top and previews A/B grouping', async () => {
    const wrapper = mountPage()
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()

    expect(document.body.textContent).toContain('SKU 履约分配')
    expect(document.body.textContent).toContain('先确认发货仓，再确认物流承运商')
    expect(document.body.textContent).toContain('当前将生成 2 个履约订单')
    expect(document.body.textContent).toContain('007-0911-40414A')
    expect(document.body.textContent).toContain('007-0911-40414B')
    expect(document.body.textContent).toContain('USPS / 美国邮政')
    expect(document.body.textContent).toContain('4PX / 速达')
    const optionTexts = Array.from(document.body.querySelectorAll('option')).map(option => option.textContent)
    expect(optionTexts).toContain('中国工厂')
    expect(optionTexts).toContain('美西MorePlus')
    expect(optionTexts.join(' ')).not.toContain('007')
  })

  it('previews A/B/C grouping for three independent warehouse sources', async () => {
    const itemC = {
      ...itemB,
      id: 104,
      product_id: 14,
      sku: 'ABC-C',
      source_sku: 'ABC-C',
      product_summary: 'Product C',
    }
    const abcOrder = {
      ...manualOrder,
      id: 14,
      erp_order_id: '007-0911-ABC01',
      item_count: 3,
      items: [itemA, itemB, itemC],
    }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [abcOrder], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()

    const rows = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr')) as HTMLTableRowElement[]
    expect(rows).toHaveLength(3)
    for (const [index, source] of ['US-LA', 'US-NY', 'US-SM'].entries()) {
      const select = rows[index].querySelectorAll('select')[0] as HTMLSelectElement
      select.value = source
      select.dispatchEvent(new Event('change', { bubbles: true }))
      await flushPromises()
    }

    expect(document.body.textContent).toContain('当前将生成 3 个履约订单')
    expect(document.body.textContent).toContain('007-0911-ABC01A')
    expect(document.body.textContent).toContain('007-0911-ABC01B')
    expect(document.body.textContent).toContain('007-0911-ABC01C')
    expect(document.body.textContent).toContain('最多 6 个履约来源')
    expect(document.body.textContent).not.toContain('一个海外仓 + 中国工厂')
  })

  it('opens data-insufficient order directly in human judgment mode with explicit reasons', async () => {
    const wrapper = mountPage()
    const buttons = wrapper.findAll('button').filter(button => button.text() === '查看方案')
    await buttons[1].trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('需要人工判断')
    expect(document.body.textContent).toContain('产品包装成本数据不足')
    expect(document.body.textContent).toContain('拆单附加成本尚未配置')
    expect(document.body.textContent).toContain('确认人工分配并提交')
  })


  it('shows gift resolution in the SKU row and filters actual gift SKUs by selected warehouse', async () => {
    const giftProps = {
      ...props,
      orders: { ...props.orders, data: [giftOrder], total: 1 },
      pendingTotal: 1,
    }
    const wrapper = mountPage(giftProps)
    expect(wrapper.text()).toContain('gift × 1')
    expect(wrapper.text()).toContain('待解析')
    expect(wrapper.text()).toContain('礼品待确认 1')

    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('实际履约 SKU')
    expect(document.body.textContent).toContain('美国3世迈仓：有货 8')

    const giftRow = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr')).find(row => row.textContent?.includes('gift')) as HTMLTableRowElement
    expect(giftRow).toBeTruthy()
    const selects = giftRow.querySelectorAll('select')
    expect(selects.length).toBe(3)
    const warehouseSelect = selects[0] as HTMLSelectElement
    warehouseSelect.value = 'US-SM'
    warehouseSelect.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()
    const giftSelect = giftRow.querySelectorAll('select')[2] as HTMLSelectElement
    expect(Array.from(giftSelect.options).map(option => option.textContent).join(' ')).toContain('GIFT-SM-01')
    expect(Array.from(giftSelect.options).map(option => option.textContent).join(' ')).not.toContain('GIFT-MP-02')
  })


  it('shows the exact empty state when selected warehouse has no clearance gift pool', async () => {
    const noPoolGiftItem = { ...giftItem, gift_options_by_warehouse: {} }
    const noPoolOrder = { ...giftOrder, id: 13, items: [itemA, noPoolGiftItem] }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [noPoolOrder], total: 1 }, pendingTotal: 1 })

    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const giftRow = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr')).find(row => row.textContent?.includes('gift')) as HTMLTableRowElement
    const warehouseSelect = giftRow.querySelectorAll('select')[0] as HTMLSelectElement
    warehouseSelect.value = 'US-SM'
    warehouseSelect.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()

    expect(document.body.textContent).toContain('该仓当前没有可用清仓礼品')
    const giftSelect = giftRow.querySelectorAll('select')[2] as HTMLSelectElement
    expect(giftSelect.options.length).toBe(1)
  })

  it('shows the exact out-of-stock state and does not offer zero-stock clearance SKUs', async () => {
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [giftOrder], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const giftRow = Array.from(document.body.querySelectorAll('.primary-allocation tbody tr')).find(row => row.textContent?.includes('gift')) as HTMLTableRowElement
    const warehouseSelect = giftRow.querySelectorAll('select')[0] as HTMLSelectElement
    warehouseSelect.value = 'US-LA'
    warehouseSelect.dispatchEvent(new Event('change', { bubbles: true }))
    await flushPromises()

    expect(document.body.textContent).toContain('礼品库存在，但当前全部缺货')
    const giftSelect = giftRow.querySelectorAll('select')[2] as HTMLSelectElement
    expect(Array.from(giftSelect.options).map(option => option.textContent).join(' ')).not.toContain('GIFT-MP-02')
  })


  it('replays submitted split as A/B derived logistics orders with fixed warehouses', async () => {
    const processedProps = {
      ...props,
      orders: { ...props.orders, data: [processedOrder], total: 1 },
      pendingTotal: 1,
    }
    const wrapper = mountPage(processedProps)
    await wrapper.findAll('button').find(button => button.text().startsWith('已处理'))!.trigger('click')
    await wrapper.findAll('button').find(button => button.text() === '查看记录')!.trigger('click')
    await flushPromises()

    expect(document.body.textContent).toContain('分仓已完成')
    expect(document.body.textContent).toContain('衍生订单 A')
    expect(document.body.textContent).toContain('007-0911-40415A')
    expect(document.body.textContent).toContain('007-0911-40415B')
    expect(document.body.textContent).toContain('中国工厂')
    expect(document.body.textContent).toContain('美西MorePlus')
    expect(document.body.textContent).not.toContain('确认人工分配并提交')
  })

  it('submits a system recommendation by JSON without Inertia navigation', async () => {
    const csrf = document.createElement('meta')
    csrf.name = 'csrf-token'
    csrf.content = 'test-token'
    document.head.appendChild(csrf)
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ decision_source: 'system_recommendation', submitted_at: '2026-09-11T09:00:00+08:00' }) })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPage()
    await wrapper.findAll('button').find(button => button.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const submit = Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === '采用推荐并提交分仓') as HTMLButtonElement
    submit.click()
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock.mock.calls[0][0]).toBe('/logistics/orders/10/fulfillment-allocation/recommended')
    expect(fetchMock.mock.calls[0][1]).toMatchObject({ method: 'POST', credentials: 'same-origin' })
    expect(document.body.textContent).not.toContain('履约方案详情')
    expect(wrapper.text()).toContain('已处理 1')
    await wrapper.findAll('button').find(button => button.text().startsWith('已处理'))!.trigger('click')
    expect(wrapper.text()).toContain('已分仓')
    csrf.remove()
  })

  it('shows 27 units of US-SM as a cross-region manual alternative, not green US-NY', async () => {
    const item = {
      ...itemA, id: 205, product_id: 205, sku: 'TEST-205', quantity: 2,
      availability: [
        { warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 },
        { warehouse_id: 7, warehouse_slug: 'us-3sm', warehouse_name: '美国3世迈仓', inventory_state: 'manual', can_auto_allocate: false, quantity_on_hand: 27, quantity_reserved: 0, quantity_available: 27 },
      ],
    }
    const order = {
      ...manualOrder, id: 20, country: 'US', province_state: 'VA', postal_code: '24141',
      us_fulfillment_zone: { status: 'matched', zone: 'east', zone_label: '美东区', postal_code: '24141', zip3: 241, state: 'VA', warehouse_priority: ['US-NY'] },
      items: [item], item_count: 2,
    }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [order], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(b => b.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const advice = document.body.querySelector('.primary-allocation .warehouse-advice') as HTMLElement
    expect(advice.classList.contains('manual')).toBe(true)
    expect(advice.textContent).toContain('跨区人工备选 · 美国3世迈仓')
    expect(advice.textContent).toContain('可用 27 件')
    expect(advice.textContent).toContain('Full Snapshot')
    expect(advice.textContent).toContain('不是系统自动推荐')
    expect(document.body.querySelector('.primary-allocation .warehouse-advice.recommended')).toBeNull()
    expect(document.body.textContent).toContain('区域优先仓（不代表有货）')
    expect((document.body.querySelector('.primary-allocation tbody select') as HTMLSelectElement).value).toBe('')
  })

  it('shows no recommendation when all US warehouses are out of stock', async () => {
    const item = {
      ...itemA, id: 206, product_id: 206, sku: 'TEST-206', quantity: 2,
      availability: [
        { warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 },
        { warehouse_id: 7, warehouse_slug: 'us-3sm', warehouse_name: '美国3世迈仓', inventory_state: 'manual', can_auto_allocate: false, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0 },
      ],
    }
    const order = {
      ...manualOrder, id: 21, country: 'US', province_state: 'VA', postal_code: '24141',
      us_fulfillment_zone: { status: 'matched', zone: 'east', zone_label: '美东区', postal_code: '24141', zip3: 241, state: 'VA', warehouse_priority: ['US-NY'] },
      items: [item], item_count: 2,
    }
    const wrapper = mountPage({ ...props, orders: { ...props.orders, data: [order], total: 1 }, pendingTotal: 1 })
    await wrapper.findAll('button').find(b => b.text() === '查看方案')!.trigger('click')
    await flushPromises()
    const advice = document.body.querySelector('.primary-allocation .warehouse-advice') as HTMLElement
    expect(advice.classList.contains('none')).toBe(true)
    expect(advice.textContent).toContain('无有效推荐')
    expect(advice.textContent).not.toContain('美东纽约')
    expect(document.body.querySelector('.primary-allocation .warehouse-advice.recommended')).toBeNull()
  })

})
