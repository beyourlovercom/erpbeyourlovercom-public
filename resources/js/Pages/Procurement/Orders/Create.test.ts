import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Create from './Create.vue'

const post = vi.fn()
let form = {
  items: { 10: { purchase_url: '', purchase_size: '', purchase_quantity: 2 } },
  processing: false,
  errors: {} as Record<string, string>,
  post,
}

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  useForm: () => form,
}))

vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const order = {
  id: 7,
  erp_order_id: '007-0908-c7',
  external_order_number: 'ORD-007',
  order_status: 'pending_shipment',
  items: [{ id: 10, product_id: 3, sku: 'SKU-10', product_summary: '测试产品', quantity: 2 }],
}

describe('Procurement/Orders/Create', () => {
  beforeEach(() => {
    post.mockClear()
    form = { items: { 10: { purchase_url: '', purchase_size: '', purchase_quantity: 2 } }, processing: false, errors: {}, post }
  })

  it('shows source facts and keeps the create action in the page header', () => {
    const wrapper = mount(Create, { props: { order } })
    expect(wrapper.text()).toContain('007-0908-c7')
    expect(wrapper.text()).not.toContain('ORD-007')
    expect(wrapper.text()).toContain('SKU-10')
    expect(wrapper.text()).toContain('源订单数量：2')
    expect(wrapper.find('.page-header-actions button').text()).toBe('创建采购订单')
    expect(wrapper.findAll('input[type="number"]')).toHaveLength(1)
  })

  it('posts the complete source-order line payload', async () => {
    const wrapper = mount(Create, { props: { order } })
    await wrapper.find('.page-header-actions button').trigger('click')
    expect(post).toHaveBeenCalledWith('/purchase/orders/from-order/7')
  })
})
