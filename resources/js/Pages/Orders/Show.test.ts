import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'
import axios from 'axios'
import { globalFeedback } from '../../lib/globalFeedback'
import { router } from '@inertiajs/vue3'

vi.mock('axios', () => ({
  default: { post:vi.fn(), isAxiosError: (value:unknown) => Boolean(value && typeof value === 'object' && 'response' in value) },
}))

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: vi.fn(), reload: vi.fn() },
  useForm: (initial: Record<string, unknown>) => {
    const data = { ...initial }
    return reactive({
      ...data,
      processing: false,
      errors: {},
      patch: vi.fn(),
      reset: vi.fn(),
      clearErrors: vi.fn(),
    })
  },
  usePage: () => ({ url: '/customers/orders/1', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

vi.mock('../../Components/Products/SkuCombobox.vue', () => ({
  default: { props: ['modelValue', 'productId'], template: '<input class="sku-combobox-stub" />' },
}))

const baseOrder = {
  id: 1,
  erp_order_id: '007-0908-c1',
  internal_order_number: 'INT-001',
  external_order_number: 'BIZ-001',
  source_type: 'manual',
  source_label: '手动创建',
  order_kind: 'customer',
  order_kind_label: '客户订单',
  order_date: '2026-08-25',
  order_status: 'pending_shipment',
  order_status_label: '待发货',
  order_note: '测试备注',
  currency: 'USD',
  owner: { id: 1, name: 'Test Owner' },
  owner_label: 'Owner(Test Owner)',
  address: {
    recipient_name: 'Test Recipient',
    phone: '+1 555 0100',
    country: 'US',
    province_state: 'CA',
    city: 'Los Angeles',
    address_line_1: '100 Main St',
    address_line_2: '',
    postal_code: '90001',
  },
  items: [
    { id: 10, product_id: 5, product_summary: 'Test Product', sku: 'SKU-001', quantity: 2, unit_price: '19.99' },
  ],
  shipment: { shipment_status: 'pending', carrier: null, tracking_number: null, shipped_at: null },
  manual_sync: null,
  operational_markers: [],
  procurement_order: null,
}

const kolOrder = { ...baseOrder, order_kind: 'kol_fulfillment', order_kind_label: '网红履约订单' }

const splitOrder = {
  ...baseOrder,
  shipment: null,
  fulfillment: {
    is_split: true,
    aggregate_status: 'partially_shipped',
    aggregate_status_label: '部分发货',
    segment_count: 2,
    original_segment_count: 2,
    replacement_count: 0,
    fulfilled_count: 1,
    has_exception: false,
    original_logistics_started: true,
    segments: [
      {
        order_id: 11,
        erp_order_id: '007-0908-c1A',
        suffix: 'A',
        role: 'original' as const,
        role_label: '原始履约',
        reason: null,
        reason_label: null,
        origin_fulfillment_id: null,
        origin_erp_order_id: null,
        can_reallocate: false,
        items: [{ id: 101, sku: 'GIFT-RED', product_summary: 'Gift Red', quantity: 1 }],
        shipment: { shipment_status: 'shipped', status_label: '运输中', warehouse_id: 1, warehouse_name: '美西 MorePlus 仓', carrier: 'USPS', service: 'Ground', tracking_number: 'TRACK-A', shipped_at: '2026-09-18T01:00:00Z' },
      },
      {
        order_id: 12,
        erp_order_id: '007-0908-c1B',
        suffix: 'B',
        role: 'original' as const,
        role_label: '原始履约',
        reason: null,
        reason_label: null,
        origin_fulfillment_id: null,
        origin_erp_order_id: null,
        can_reallocate: true,
        items: [{ id: 102, sku: 'SKU-B', product_summary: 'Product B', quantity: 2 }],
        shipment: { shipment_status: 'pending', status_label: '待发货', warehouse_id: 2, warehouse_name: '国内工厂', carrier: '4PX', service: '普通', tracking_number: null, shipped_at: null },
      },
    ],
  },
}

const audit = [
  { action: 'created', actor_id: 1, created_at: '2026-08-25T10:00:00Z' },
]

describe('Orders/Show V2', () => {
  it('renders identity strip with order number', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const strip = wrapper.find('.ord-identity-strip')
    expect(strip.exists()).toBe(true)
    expect(strip.text()).toContain('007-0908-c1')
    expect(wrapper.text()).not.toContain('BIZ-001')
    expect(wrapper.text()).not.toContain('INT-001')
  })

  it('shows order kind label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('客户订单')
  })

  it('shows source label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('手动创建')
  })

  it('shows order status label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('待发货')
  })

  it('shows owner in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('Test Owner')
  })

  it('does not expose source or technical order numbers in view mode', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-details-disclosure').exists()).toBe(false)
    expect(wrapper.text()).toContain('007-0908-c1')
    expect(wrapper.text()).not.toContain('BIZ-001')
    expect(wrapper.text()).not.toContain('INT-001')
  })

  it('renders five business cards: fulfillment, order info, address, items, note', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('履约状态')
    expect(wrapper.text()).toContain('订单信息')
    expect(wrapper.text()).toContain('收件与地址')
    expect(wrapper.text()).toContain('订单商品')
    expect(wrapper.text()).toContain('内部备注')
  })

  it('centralizes every shipping CTA in the fulfillment status action area', () => {
    const shippedOrder = {
      ...baseOrder,
      shipment: { shipment_status: 'shipped', warehouse_id: 1, warehouse_name: '中国工厂', carrier: '4PX', service: 'SD标准挂号-带电', tracking_number: '4PX3003', shipped_at: '2026-09-01T08:00:00Z' },
    }
    const wrapper = mount(Show, { props: { order: shippedOrder, audit, canOperateLogistics: true, canCreateReplacement: true } })
    const actions = wrapper.get('[data-testid="fulfillment-actions"]')
    expect(actions.text()).toContain('重开发货')
    expect(actions.text()).toContain('新增补发')
    expect(wrapper.get('.page-header-actions').text()).not.toContain('履约发货')
    expect(wrapper.find('.ord-fulfillment-action').exists()).toBe(false)
  })

  it('keeps split fulfillment shipping and reopen CTAs out of row actions', () => {
    const wrapper = mount(Show, { props: { order: splitOrder, audit, canOperateLogistics: true, canCreateReplacement: true } })
    const actions = wrapper.get('[data-testid="fulfillment-actions"]')
    expect(actions.text()).toContain('B · 履约发货')
    expect(actions.text()).toContain('A · 重开发货')
    expect(actions.text()).toContain('新增补发')
    const rowActions = wrapper.findAll('.ord-fulfillment-row-actions').map(row => row.text()).join(' ')
    expect(rowActions).not.toContain('履约发货')
    expect(rowActions).not.toContain('重开发货')
  })

  it('auto-fills USPS Priority when the selected fulfillment warehouse is in the US', async () => {
    const order = { ...baseOrder, shipment: { ...baseOrder.shipment, warehouse_id: 1, warehouse_name: '美西 MorePlus 仓' } }
    const wrapper = mount(Show, { props: {
      order,
      audit,
      canOperateLogistics: true,
      warehouses: [{ id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' }],
      warehouseRouting: { 1: { carrier: 'USPS', service: 'USPS Priority Mail', carrier_service_id: 101, automatic: true } },
      carrierOptions: [{ value: 'USPS', label: 'USPS / 美国邮政', default_service_id: 101, default_service_value: 'USPS Priority Mail', services: [{ id: 101, key: 'priority_mail', value: 'USPS Priority Mail', label: 'Priority Mail / 优先件', name_en: 'Priority Mail', name_zh: '优先件' }] }],
    } })
    await wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').find(btn => btn.text().includes('履约发货'))!.trigger('click')
    const selects = wrapper.find('.ord-shipment-form').findAll('select')
    expect((selects[0].element as HTMLSelectElement).value).toBe('1')
    expect((selects[1].element as HTMLSelectElement).value).toBe('USPS')
    expect((selects[2].element as HTMLSelectElement).value).toBe('USPS Priority Mail')
  })

  it('offers direct fulfillment execution on a pending single order', async () => {
    const wrapper = mount(Show, { props: {
      order: baseOrder,
      audit,
      canOperateLogistics: true,
      warehouses: [{ id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' }],
      carrierOptions: [{ value: 'USPS', label: 'USPS / 美国邮政', default_service_id: 101, default_service_value: 'USPS Priority Mail', services: [{ id: 101, key: 'priority_mail', value: 'USPS Priority Mail', label: 'Priority Mail / 优先件', name_en: 'Priority Mail', name_zh: '优先件' }] }],
    } })
    const button = wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').find(btn => btn.text().includes('履约发货'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(wrapper.text()).toContain('填写物流并发货')
    expect(wrapper.find('.ord-shipment-form').exists()).toBe(true)
    expect(wrapper.text()).toContain('所有发货、重发、补发操作统一在上方“履约状态”处理')
  })

  it('offers fulfillment execution per pending split segment without changing shipped siblings', async () => {
    const wrapper = mount(Show, { props: {
      order: splitOrder,
      audit,
      canOperateLogistics: true,
      warehouses: [
        { id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' },
        { id: 2, slug: 'factory', name: '国内工厂', code: 'FACTORY', country_code: 'CN' },
      ],
      carrierOptions: [{ value: '4PX', label: '4PX / 速达', default_service_id: 201, default_service_value: 'SD经济挂号-带电', services: [{ id: 201, key: 'sd_economy_registered_battery', value: 'SD经济挂号-带电', label: 'Economy Registered - Battery / SD经济挂号-带电', name_en: 'Economy Registered - Battery', name_zh: 'SD经济挂号-带电' }] }, { value: 'USPS', label: 'USPS / 美国邮政', default_service_id: 101, default_service_value: 'USPS Priority Mail', services: [{ id: 101, key: 'priority_mail', value: 'USPS Priority Mail', label: 'Priority Mail / 优先件', name_en: 'Priority Mail', name_zh: '优先件' }] }],
    } })
    const shipButtons = wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').filter(btn => btn.text().includes('履约发货'))
    expect(shipButtons).toHaveLength(1)
    expect(wrapper.findAll('button').some(btn => btn.text().includes('重开发货'))).toBe(true)
    await shipButtons[0].trigger('click')
    expect(wrapper.text()).toContain('B · 007-0908-c1B')
    const carrier = wrapper.find('.ord-shipment-form select:nth-of-type(2)')
    expect(wrapper.find('.ord-shipment-form').exists()).toBe(true)
    expect(wrapper.html()).toContain('4PX')
  })

  it('allows only the pending sibling to adjust warehouse after another segment has shipped', async () => {
    const allocationUi = {
      status: 'submitted' as const,
      can_operate: false,
      can_reallocate: false,
      can_partial_reallocate: true,
      logistics_started: true,
      requires_full_flow: false,
      selectable_warehouse_ids: [1, 2, 3],
      recommended_warehouse_ids: {},
      selected_warehouse_ids: {},
      recommended_plan_key: null,
      can_use_recommendation: false,
      full_allocation_url: '/logistics/wait-stock',
    }
    const wrapper = mount(Show, { props: {
      order: splitOrder,
      audit,
      allocationUi,
      warehouses: [
        { id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' },
        { id: 2, slug: 'factory', name: '国内工厂', code: 'FACTORY', country_code: 'CN' },
        { id: 3, slug: 'us-ny', name: '美东纽约仓', code: 'US-NY', country_code: 'US' },
      ],
    } })
    const adjustButtons = wrapper.findAll('button').filter(btn => btn.text().includes('调整分仓'))
    expect(adjustButtons).toHaveLength(1)
    await adjustButtons[0].trigger('click')
    expect(wrapper.text()).toContain('调整 B 分仓')
    expect(wrapper.text()).toContain('已经发货的 A/B/C 不会被改动')
    expect(wrapper.text()).toContain('美东纽约仓')
    expect(wrapper.find('[role="dialog"][aria-label="调整未发货履约分仓"]').exists()).toBe(true)
  })

  it('renders split fulfillment aggregation from child shipments instead of the empty parent shipment', () => {
    const wrapper = mount(Show, { props: { order: splitOrder, audit } })
    expect(wrapper.text()).toContain('已拆分 · 2 个履约单')
    expect(wrapper.text()).toContain('部分发货')
    expect(wrapper.text()).toContain('1/2 已进入物流')
    expect(wrapper.text()).toContain('007-0908-c1A')
    expect(wrapper.text()).toContain('美西 MorePlus 仓')
    expect(wrapper.text()).toContain('USPS')
    expect(wrapper.text()).toContain('TRACK-A')
    expect(wrapper.text()).toContain('007-0908-c1B')
    expect(wrapper.text()).toContain('国内工厂')
    expect(wrapper.text()).toContain('待发货')
    expect(wrapper.find('.ord-fulfillment-table').exists()).toBe(true)
    expect(wrapper.find('.ord-inventory-card').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('库存分布')
  })

  it('does not introduce a delete action', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).not.toContain('删除订单')
    expect(wrapper.text()).not.toContain('Danger Zone')
  })

  it('shows 返回列表 link for customers', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('a[href="/customers/orders"]').exists()).toBe(true)
  })

  it('shows 返回列表 link for kol', () => {
    const wrapper = mount(Show, { props: { order: kolOrder, audit } })
    expect(wrapper.find('a[href="/kol/orders"]').exists()).toBe(true)
  })

  it('shows 履约发货 only inside the fulfillment action area for an authorized pending order', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canOperateLogistics: true } })
    const actions = wrapper.get('[data-testid="fulfillment-actions"]')
    expect(actions.text()).toContain('履约发货')
    expect(wrapper.find('a[href="/logistics/pending?order_id=1"]').exists()).toBe(false)
    expect(wrapper.get('.page-header-actions').text()).not.toContain('履约发货')
  })

  it('shows centralized read-only fulfillment access without logistics execution authority', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canOperateLogistics: false } })
    const actions = wrapper.get('[data-testid="fulfillment-actions"]')
    expect(actions.text()).toContain('查看履约')
    expect(actions.text()).not.toContain('履约发货')
    expect(wrapper.find('a[href="/logistics/pending?order_id=1"]').exists()).toBe(false)
  })

  it('shows procurement creation only for a stockout order without a linked procurement order', () => {
    const wrapper = mount(Show, { props: { order: { ...baseOrder, operational_markers: ['stockout'] }, audit, canCreateProcurement: true } })
    expect(wrapper.find('a[href="/purchase/orders/from-order/1/create"]').text()).toBe('创建采购订单')
    expect(wrapper.find('.page-header-actions').text()).not.toContain('查看采购订单')
  })

  it('replaces procurement creation with the linked procurement action', () => {
    const wrapper = mount(Show, { props: { order: { ...baseOrder, operational_markers: ['stockout'], procurement_order: { id: 4, procurement_number: 'PO-20260904-0001' } }, audit, canCreateProcurement: true } })
    expect(wrapper.find('a[href="/purchase/orders/from-order/1/create"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/purchase/orders/4"]').text()).toContain('PO-20260904-0001')
  })

  it('does not show procurement creation for a non-stockout order', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canCreateProcurement: false } })
    expect(wrapper.find('a[href="/purchase/orders/from-order/1/create"]').exists()).toBe(false)
  })

  // View Mode tests
  it('shows ISO currency beside every item unit price', () => {
    const eurOrder = { ...baseOrder, currency: 'EUR', items: [{ ...baseOrder.items[0], unit_price: '29.00' }] }
    const wrapper = mount(Show, { props: { order: eurOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('订单币种')
    expect(wrapper.text()).toContain('EUR 29.00')
  })

  it('Edit Mode exposes the canonical order currency selector', async () => {
    const eurOrder = { ...baseOrder, currency: 'EUR' }
    const wrapper = mount(Show, { props: { order: eurOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    const selects = wrapper.findAll('select')
    expect(selects.some(select => (select.element as HTMLSelectElement).value === 'EUR')).toBe(true)
    expect(wrapper.text()).toContain('单价（EUR）')
  })

  it('defaults to View Mode: no active submit button', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
  })

  it('preflights proposed item changes, displaying stock comparison without writing an order', async () => {
    vi.mocked(axios.post).mockClear()
    vi.mocked(axios.post).mockResolvedValueOnce({ data: {
      changed:true, can_save_direct:false, requires_replan:true, no_writes:true,
      old_items_gross:'39.98', new_items_gross:'79.96', gross_delta:'39.98', currency:'USD',
      rows:[{ order_item_id:10, operation:'update',
        before:{ sku:'SKU-001', quantity:2 }, after:{ sku:'SKU-001', quantity:4 } }],
      issues:['当前分仓需要重建'],
      stock_by_warehouse:[{ warehouse_id:1, warehouse_name:'美西 MorePlus 仓',
        single_warehouse_candidate:false, details:[] }],
      inventory_note:'只读库存快照',
    }})
    const wrapper = mount(Show, { props:{ order:baseOrder, audit, canEditOrder:true } })
    await wrapper.findAll('button').find(b => b.text() === '编辑订单')!.trigger('click')
    const qty = wrapper.get('.ord-item-row input[type="number"][min="1"]')
    await qty.setValue('4')
    const check = wrapper.findAll('button').find(b => b.text().includes('检查 SKU、库存与分仓影响'))
    await check!.trigger('click')
    await flushPromises()
    expect(axios.post).toHaveBeenCalledWith('/customers/orders/1/items/preview', expect.objectContaining({
      items:[expect.objectContaining({ id:10, quantity:4 })],
    }))
    expect(wrapper.get('.ord-amendment-preview').text()).toContain('当前分仓需要重建')
    expect(wrapper.get('.ord-amendment-preview').text()).toContain('39.98')
    expect(wrapper.get('.ord-amendment-preview').text()).toContain('美西 MorePlus 仓')
    expect(globalFeedback.value.state).toBe('BLOCKED')
    expect(wrapper.find('.ord-item-row').exists()).toBe(true)
  })

  it('requires explicit approval and expected revision for single-warehouse atomic replan', async () => {
    vi.mocked(axios.post).mockClear()
    vi.mocked(router.reload).mockClear()
    vi.mocked(axios.post)
      .mockResolvedValueOnce({data:{
        changed:true, can_save_direct:false, requires_replan:true, can_atomic_single_replan:true,
        expected_revision:'a'.repeat(64), no_writes:true, old_items_gross:'39.98',
        new_items_gross:'59.97', gross_delta:'19.99', currency:'USD',
        rows:[{ order_item_id:10, operation:'update',
          before:{sku:'SKU-001',quantity:2}, after:{sku:'SKU-001',quantity:3} }],
        issues:['库存需要重规划'],
        stock_by_warehouse:[{warehouse_id:1,warehouse_code:'US-LA',
          warehouse_name:'美西 MorePlus 仓',single_warehouse_candidate:true,details:[]}],
        inventory_note:'仅为库存快照',
      }})
      .mockResolvedValueOnce({data:{order_id:1,new_reservation_id:25,warehouse_id:1}})
    const wrapper = mount(Show, { props:{
      order:baseOrder, audit, canEditOrder:true, canOperateLogistics:true,
    } })
    await wrapper.findAll('button').find(b => b.text() === '编辑订单')!.trigger('click')
    await wrapper.get('.ord-item-row input[type="number"][min="1"]').setValue('3')
    await wrapper.findAll('button').find(b => b.text().includes('检查 SKU、库存与分仓影响'))!.trigger('click')
    await flushPromises()
    expect(wrapper.get('.ord-replan-approval').text()).toContain('单仓库存原子重规划')
    const confirm = wrapper.get('.ord-replan-approval button')
    expect(confirm.attributes('disabled')).toBeDefined()
    expect(vi.mocked(axios.post)).toHaveBeenCalledTimes(1)
    await wrapper.get('.ord-replan-approval select').setValue('US-LA')
    await wrapper.get('.ord-replan-approval textarea').setValue('Customer approved blue color')
    await wrapper.get('.ord-replan-approval input[type="checkbox"]').setValue(true)
    await confirm.trigger('click')
    await flushPromises()
    expect(vi.mocked(axios.post)).toHaveBeenCalledTimes(2)
    expect(vi.mocked(axios.post)).toHaveBeenLastCalledWith(
      '/customers/orders/1/items/replan', expect.objectContaining({
        expected_revision:'a'.repeat(64),
        reason:'Customer approved blue color',
        assignments:[{item_index:0,source_code:'US-LA'}],
      }),
    )
    expect(globalFeedback.value.state).toBe('SUCCESS')
    expect(router.reload).toHaveBeenCalledWith()
  })

  it('requires per-item warehouse choices and explicit consent for A5-B multi-warehouse replan', async () => {
    vi.mocked(axios.post).mockReset()
    vi.mocked(router.reload).mockClear()
    vi.mocked(axios.post)
      .mockResolvedValueOnce({data:{
        changed:true, can_save_direct:false, can_atomic_single_replan:false,
        can_atomic_multi_replan:true, requires_replan:true, no_writes:true,
        expected_revision:'b'.repeat(64), old_items_gross:'39.98',
        new_items_gross:'69.97', gross_delta:'29.99', currency:'USD',
        rows:[{order_item_id:11,operation:'update',
          before:{sku:'SKU-B',quantity:1},after:{sku:'SKU-B',quantity:3}}],
        issues:['多仓子履约需要重新确认'],
        stock_by_warehouse:[
          {warehouse_id:1,warehouse_code:'US-LA',warehouse_name:'美西仓',
            single_warehouse_candidate:false,
            details:[{product_id:5,required:2,available:10,enough:true},
              {product_id:22,required:3,available:0,enough:false}]},
          {warehouse_id:2,warehouse_code:'US-SM',warehouse_name:'世迈仓',
            single_warehouse_candidate:false,
            details:[{product_id:5,required:2,available:0,enough:false},
              {product_id:22,required:3,available:8,enough:true}]},
        ],
        inventory_note:'只读库存',
      }})
      .mockResolvedValueOnce({data:{parent_order_id:1,new_reservations:[{order_id:11},{order_id:12}]}})
    const order = {...baseOrder,items:[
      ...baseOrder.items,
      {id:11,product_id:22,product_summary:'Product B',sku:'SKU-B',quantity:1,unit_price:'29.99'},
    ]}
    const wrapper = mount(Show, {props:{order,audit,canEditOrder:true,canOperateLogistics:true}})
    await wrapper.findAll('button').find(b=>b.text()==='编辑订单')!.trigger('click')
    await wrapper.findAll('.ord-item-row input[type="number"][min="1"]')[1].setValue('3')
    await wrapper.findAll('button').find(b=>b.text().includes('检查 SKU、库存与分仓影响'))!.trigger('click')
    await flushPromises()
    expect(wrapper.get('.ord-multi-replan-approval').text()).toContain('多仓库存原子重规划')
    const confirm = wrapper.get('.ord-multi-replan-approval button')
    expect(confirm.attributes('disabled')).toBeDefined()
    expect(vi.mocked(axios.post)).toHaveBeenCalledTimes(1)
    const selects=wrapper.findAll('.ord-multi-replan-approval select')
    await selects[0].setValue('US-LA')
    await selects[1].setValue('US-SM')
    await wrapper.get('.ord-multi-replan-approval textarea').setValue('Customer confirmed replacement colors')
    await wrapper.get('.ord-multi-replan-approval input[type="checkbox"]').setValue(true)
    await confirm.trigger('click')
    await flushPromises()
    expect(vi.mocked(axios.post)).toHaveBeenCalledTimes(2)
    expect(vi.mocked(axios.post)).toHaveBeenLastCalledWith(
      '/customers/orders/1/items/replan-multi',expect.objectContaining({
        expected_revision:'b'.repeat(64),
        assignments:[
          {item_index:0,source_code:'US-LA'},
          {item_index:1,source_code:'US-SM'},
        ],
      }),
    )
    expect(globalFeedback.value.state).toBe('SUCCESS')
    expect(router.reload).toHaveBeenCalledWith()
  })

  it('does not show the order edit entry to a user without orders.edit', () => {
    const wrapper = mount(Show, { props:{ order:baseOrder, audit, canEditOrder:false } })
    expect(wrapper.findAll('button').some(b => b.text() === '编辑订单')).toBe(false)
  })

  it('allows adding and removing a zero-price gift SKU line in edit mode', async () => {
    const wrapper = mount(Show, { props:{ order:baseOrder, audit, canEditOrder:true } })
    const edit = wrapper.findAll('button').find(b => b.text() === '编辑订单')
    await edit!.trigger('click')
    const addButton = wrapper.findAll('button').find(b => b.text().includes('增加商品 / 赠品'))
    await addButton!.trigger('click')
    // The test's lightweight useForm mock is a plain object (not Inertia's
    // reactive useForm); explicitly request a render after its array mutation.
    wrapper.vm.$forceUpdate()
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('.ord-item-row')).toHaveLength(2)
    const rows = wrapper.findAll('.ord-item-row')
    expect((rows[1].find('input[type="number"][min="0"]').element as HTMLInputElement).value).toBe('0.00')
    const removeButton = rows[1].findAll('button').find(b => b.text() === '删除商品')
    await removeButton!.trigger('click')
    wrapper.vm.$forceUpdate()
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('.ord-item-row')).toHaveLength(1)
  })

  it('defaults to View Mode: 编辑订单 button is visible', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    expect(editBtn).toBeTruthy()
  })

  it('defaults to View Mode: no active date input', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('input[type="date"]').exists()).toBe(false)
  })

  it('defaults to View Mode: no active textarea for note', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('textarea').exists()).toBe(false)
  })

  it('defaults to View Mode: no SKU combobox visible', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(false)
  })

  it('defaults to View Mode: 保存订单 action not shown', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).not.toContain('保存订单')
  })

  // Edit Mode tests
  it('clicking 编辑订单 enters Edit Mode: shows 保存订单 and 取消编辑', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.text()).toContain('保存订单')
    expect(wrapper.text()).toContain('取消编辑')
  })

  it('Edit Mode shows date input', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
  })

  it('Edit Mode shows textarea for note', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('textarea').exists()).toBe(true)
  })

  it('Edit Mode shows SKU combobox for items', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(true)
  })

  it('clicking 取消编辑 returns to View Mode', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    const cancelBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '取消编辑')
    await cancelBtn!.trigger('click')
    expect(wrapper.find('input[type="date"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('保存订单')
    const editBtnAgain = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    expect(editBtnAgain).toBeTruthy()
  })

  it('renders explicit operational markers without replacing order status', async () => {
    const marked = { ...baseOrder, operational_markers: ['exception'] }
    const wrapper = mount(Show, { props: { order: marked, audit, canMarkOperational: true } })
    expect(wrapper.text()).toContain('辅助标记')
    expect(wrapper.text()).toContain('✓ 异常')
    expect(wrapper.text()).toContain('待发货')
  })

  // Immutable identity always read-only
  it('identity fields are immutable in View Mode (disabled inputs)', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const disabledInputs = wrapper.findAll('input[disabled]')
    const values = disabledInputs.map(i => (i.element as HTMLInputElement).value)
    expect(values).toContain('007-0908-c1')
    expect(values).toContain('客户订单')
    expect(values).toContain('手动创建')
    expect(values).toContain('Test Owner')
  })

  it('identity fields remain immutable in Edit Mode (disabled inputs)', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    const disabledInputs = wrapper.findAll('input[disabled]')
    const values = disabledInputs.map(i => (i.element as HTMLInputElement).value)
    expect(values).toContain('007-0908-c1')
    expect(values).toContain('客户订单')
    expect(values).toContain('手动创建')
    expect(values).toContain('Test Owner')
  })

  // ERP V2 VI contract
  it('uses erp-form-page outer container', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.erp-form-page').exists()).toBe(true)
  })

  it('uses erp-form-card for section cards', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const cards = wrapper.findAll('.erp-form-card')
    expect(cards.length).toBeGreaterThanOrEqual(5)
  })

  it('uses erp-form-label — no mvp-label class', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.erp-form-label').exists()).toBe(true)
    expect(wrapper.find('.mvp-label').exists()).toBe(false)
  })

  it('uses erp-btn — no mvp-button class', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.erp-btn').exists()).toBe(true)
    expect(wrapper.find('.mvp-button').exists()).toBe(false)
  })

  it('View Mode shows erp-readonly-input for mutable fields', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const readonlyDivs = wrapper.findAll('.erp-readonly-input')
    expect(readonlyDivs.length).toBeGreaterThan(0)
  })

  it('Edit Mode shows erp-input controls — no mvp-input class', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('.mvp-input').exists()).toBe(false)
    expect(wrapper.findAll('.erp-input').length).toBeGreaterThan(0)
  })

  // Fulfillment and audit
  it('shows fulfillment status in fulfillment card', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('待发货')
  })

  it('shows reopen button when tracking number is present and logistics execution is allowed', () => {
    const orderWithTracking = {
      ...baseOrder,
      shipment: { shipment_status: 'shipped', carrier: 'DHL', tracking_number: 'TRACK123', shipped_at: null },
    }
    const wrapper = mount(Show, { props: { order: orderWithTracking, audit, canOperateLogistics: true } })
    const reopenBtn = wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').find(b => b.text().includes('重开发货'))
    expect(reopenBtn).toBeTruthy()
  })

  it('does not show reopen button without tracking number', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const reopenBtn = wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').find(b => b.text().includes('重开发货'))
    expect(reopenBtn).toBeFalsy()
  })

  it('shows audit log section', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('操作审计')
    expect(wrapper.text()).toContain('created')
  })

  it('shows address fields labels', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('收件人姓名')
    expect(wrapper.text()).toContain('联系电话')
  })

  it('existing item product_id preserved for existing order items', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    // item.product_id = 5 from baseOrder — shown in SKU field (view mode shows SKU text)
    expect(wrapper.text()).toContain('SKU-001')
  })

  it('uses the inventory matrix itself as the pending warehouse selector', async () => {
    const inventoryAvailability = {
      5: [
        { warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西 MorePlus 仓', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 8, quantity_reserved: 1, quantity_available: 7 },
        { warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约仓', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 4, quantity_reserved: 0, quantity_available: 4 },
      ],
    }
    const allocationUi = {
      status: 'pending' as const, can_operate: true, requires_full_flow: false,
      selectable_warehouse_ids: [1, 2], recommended_warehouse_ids: { 10: 1 }, selected_warehouse_ids: { 10: null },
      recommended_plan_key: 'plan-us-la', can_use_recommendation: true, full_allocation_url: '/logistics/wait-stock',
    }
    const wrapper = mount(Show, { props: { order: baseOrder, audit, inventoryAvailability, allocationUi } })
    const choices = wrapper.findAll('input[type="radio"][name="allocation-10"]')
    expect(choices).toHaveLength(2)
    expect((choices[0].element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.text()).toContain('推荐')
    expect(wrapper.text()).toContain('采用推荐并提交分仓')

    await choices[1].trigger('change')
    expect(wrapper.text()).toContain('确认分仓')
    const confirm = wrapper.findAll('button[type="button"]').find(button => button.text() === '确认分仓')
    await confirm!.trigger('click')
    expect(router.post).toHaveBeenCalledWith('/logistics/orders/1/fulfillment-allocation/quick', {
      assignments: [{ order_item_id: 10, warehouse_id: 2 }],
    }, expect.any(Object))
  })

  it('closes inventory allocation UI once warehouse allocation is committed', () => {
    const inventoryAvailability = {
      5: [{ warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约仓', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 4, quantity_reserved: 0, quantity_available: 4 }],
    }
    const allocationUi = {
      status: 'submitted' as const, can_operate: false, requires_full_flow: false,
      selectable_warehouse_ids: [2], recommended_warehouse_ids: { 10: null }, selected_warehouse_ids: { 10: 2 },
      recommended_plan_key: null, can_use_recommendation: false, full_allocation_url: '/logistics/wait-stock',
    }
    const wrapper = mount(Show, { props: { order: baseOrder, audit, inventoryAvailability, allocationUi } })
    expect(wrapper.find('.ord-inventory-card').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('库存分布')
    expect(wrapper.find('input[type="radio"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('确认分仓')
  })

})
