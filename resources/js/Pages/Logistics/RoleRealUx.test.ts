import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AwaitingTracking from './AwaitingTracking.vue'
import InTransit from './InTransit.vue'
import Exceptions from './Exceptions.vue'
import Review from './Review.vue'

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ url: '/logistics' }),
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn(), get: vi.fn() },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('../../Components/Logistics/StatusBar.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../Components/DataGrid/ColumnSettingsHeader.vue', () => ({ default: { template: '<th />' } }))

const statusBar: never[] = []
const shipment = {
  shipment_id: 1,
  order_id: 10,
  external_order_number: 'SOURCE-10',
  erp_order_id: '007-0915-c41010',
  recipient_name: 'Receiver',
  country: 'US',
  warehouse_name: '美东纽约',
  carrier: 'DHL',
  service: 'Express',
  tracking_number: 'DHL-10' as string | null,
  shipped_at: '2026-09-15T08:00:00Z',
  logistics_note: 'Delay',
  shipment_status: 'shipped',
  order_url: '/customers/orders/10',
  paused: false,
}
const page = (row = shipment) => ({ data: [row], current_page: 1, last_page: 1, per_page: 50, total: 1 })
const reviewRow = {
  ...shipment,
  reason: 'Need review',
  trigger: 'redispatch',
  requested_at: '2026-09-15T09:00:00Z',
  items: [{ sku: 'SKU-1', product_summary: 'Product', product_url: '/products/1' }],
}

describe('Logistics role real UX', () => {
  it('makes awaiting-tracking read-only for view-only roles', () => {
    const wrapper = mount(AwaitingTracking, { props: { shipments: page({ ...shipment, tracking_number: null }), statusBar, canOperate: false } })
    expect(wrapper.text()).toContain('当前角色为只读模式')
    expect(wrapper.text()).toContain('待回填')
    expect(wrapper.text()).toContain('只读')
    expect(wrapper.find('input[placeholder="填入追踪号"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === '保存')).toBe(false)
  })

  it('keeps safe copy but removes mutation controls from in-transit for view-only roles', () => {
    const wrapper = mount(InTransit, { props: { shipments: page(), statusBar, canOperate: false } })
    expect(wrapper.text()).toContain('当前角色为只读模式')
    expect(wrapper.findAll('button').some(button => button.text() === '复制追踪')).toBe(true)
    expect(wrapper.findAll('button').some(button => button.text() === '标记异常')).toBe(false)
    expect(wrapper.find('textarea').exists()).toBe(false)
  })

  it('removes exception actions from view-only roles', () => {
    const wrapper = mount(Exceptions, { props: { shipments: page({ ...shipment, shipment_status: 'exception' }), statusBar, canOperate: false } })
    expect(wrapper.text()).toContain('当前角色为只读模式')
    expect(wrapper.text()).toContain('只读')
    for (const label of ['暂停订单', '重新派件', '重新发货', '联系用户', '取消订单（不退款）', '解除异常']) {
      expect(wrapper.findAll('button').some(button => button.text() === label)).toBe(false)
    }
  })

  it('renders review facts without editable decision controls for view-only roles', () => {
    const wrapper = mount(Review, { props: { reviews: { data: [reviewRow], current_page: 1, last_page: 1, total: 1 }, statusBar, canOperate: false } })
    expect(wrapper.text()).toContain('当前角色为只读模式')
    expect(wrapper.text()).toContain('待操作角色处理')
    expect(wrapper.text()).toContain('只读')
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('textarea').exists()).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === '确认复核')).toBe(false)
  })

  it('preserves mutation controls for logistics operators', () => {
    const awaiting = mount(AwaitingTracking, { props: { shipments: page({ ...shipment, tracking_number: null }), statusBar, canOperate: true } })
    const transit = mount(InTransit, { props: { shipments: page(), statusBar, canOperate: true } })
    const exceptions = mount(Exceptions, { props: { shipments: page({ ...shipment, shipment_status: 'exception' }), statusBar, canOperate: true } })
    const review = mount(Review, { props: { reviews: { data: [reviewRow], current_page: 1, last_page: 1, total: 1 }, statusBar, canOperate: true } })

    expect(awaiting.find('input[placeholder="填入追踪号"]').exists()).toBe(true)
    expect(awaiting.findAll('button').some(button => button.text() === '保存')).toBe(true)
    expect(transit.findAll('button').some(button => button.text() === '标记异常')).toBe(true)
    expect(exceptions.findAll('button').some(button => button.text() === '解除异常')).toBe(true)
    expect(review.find('select').exists()).toBe(true)
    expect(review.find('textarea').exists()).toBe(true)
    expect(review.findAll('button').some(button => button.text() === '确认复核')).toBe(true)
  })
})
