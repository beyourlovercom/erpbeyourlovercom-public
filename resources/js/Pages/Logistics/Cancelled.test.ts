import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Cancelled from './Cancelled.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ url: '/logistics/cancelled' }),
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const row = { order_url: '/customers/orders/7', erp_order_id: '007-0914-c40777', order_date: '2026-09-14', recipient_name: 'Receiver', country: 'US', items: [{ sku: 'SKU-1', product_summary: 'Product', product_url: '/products/1', quantity: 2 }] }
const statusBar = [{ key: 'cancelled', label: '已取消', url: '/logistics/cancelled', count: 542 }]

describe('Cancelled logistics archive', () => {
  beforeEach(() => get.mockReset())

  it('renders canonical order context and explicit refund semantics', () => {
    const wrapper = mount(Cancelled, { props: { orders: { data: [row], current_page: 1, last_page: 11, per_page: 50, total: 542 }, statusBar } })
    expect(wrapper.text()).toContain('007-0914-c40777')
    expect(wrapper.text()).toContain('2026-09-14')
    expect(wrapper.text()).toContain('Receiver')
    expect(wrapper.text()).toContain('US')
    expect(wrapper.text()).toContain('SKU-1 × 2')
    expect(wrapper.text()).toContain('订单取消状态不等同于退款结果')
    expect(wrapper.text()).toContain('查看订单')
    expect(wrapper.text()).toContain('共 542 条')
  })

  it('navigates across cancelled pages without a business-data mutation', async () => {
    const wrapper = mount(Cancelled, { props: { orders: { data: [row], current_page: 1, last_page: 11, per_page: 50, total: 542 }, statusBar } })
    await wrapper.findAll('button').find((button) => button.text() === '下一页')?.trigger('click')
    expect(get).toHaveBeenCalledWith('/logistics/cancelled', { page: 2 }, { preserveState: true, replace: true })
  })
})
