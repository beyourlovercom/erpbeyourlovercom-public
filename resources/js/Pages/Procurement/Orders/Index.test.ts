import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { getMock } = vi.hoisted(() => ({ getMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: getMock },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const orders = [{
  id: 4,
  procurement_number: 'PO-20260904-0001',
  status: 'ordered',
  status_label: '已下单',
  source_order_id: 7,
  source_order_number: 'ORD-007',
  source_order_erp_id: '007-0904-c0007',
  item_summary: '测试产品',
  item_count: 1,
  purchase_quantity: 2,
  carrier: '顺丰',
  tracking_number: 'SF123456',
  updated_at: '2026-09-04T08:00:00Z',
}]
const statusOptions = [{ value: 'pending', label: '待采购' }, { value: 'ordered', label: '已下单' }]

describe('Procurement/Orders/Index', () => {
  it('renders procurement status, logistics and canonical links', () => {
    const wrapper = mount(Index, { props: { orders, filters: {}, statusOptions, canOperate: true } })
    expect(wrapper.text()).toContain('PO-20260904-0001')
    expect(wrapper.text()).toContain('007-0904-c0007')
    expect(wrapper.text()).not.toContain('来源订单：ORD-007')
    expect(wrapper.text()).toContain('已下单')
    expect(wrapper.text()).toContain('顺丰 · SF123456')
    expect(wrapper.find('a[href="/customers/orders/7"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/purchase/orders/4"]').exists()).toBe(true)
  })

  it('applies search and status through the canonical purchase index', async () => {
    getMock.mockClear()
    const wrapper = mount(Index, { props: { orders, filters: {}, statusOptions, canOperate: true } })
    await wrapper.find('input').setValue('SF123')
    await wrapper.find('select').setValue('ordered')
    await wrapper.find('form').trigger('submit')
    expect(getMock).toHaveBeenCalledWith('/purchase', { search: 'SF123', status: 'ordered' }, { preserveState: true, replace: true })
  })
})
