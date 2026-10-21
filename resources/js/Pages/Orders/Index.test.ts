import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get, post, delete: del },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }))

const props = {
  orderKind: 'all',
  orderScope: 'all',
  orders: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 },
  filters: { view: 'all' },
  sourceTypes: [{ value: 'manual', label: '手动创建' }],
  savedViews: [{ id: 7, name: '我的待发', filters_json: { view: 'pending', source_type: 'manual' }, is_pinned: true, created_by: 1 }],
  canExport: true,
}

describe('Orders Saved Views', () => {
  beforeEach(() => { get.mockClear(); post.mockClear(); del.mockClear() })

  it('renders the nine frozen business views plus custom view creation', () => {
    const wrapper = mount(Index, { props })
    for (const label of ['全部订单', '待发订单', '异常订单', '运输中', '已发货', '退货单', '亏本单', '超重单', '缺货单', '＋ 新建视图']) {
      expect(wrapper.text()).toContain(label)
    }
    expect(wrapper.text()).toContain('我的待发')
  })

  it('applies overweight system view without inventing a copied order set', async () => {
    const wrapper = mount(Index, { props })
    const button = wrapper.findAll('button').find(b => b.text() === '超重单')!
    await button.trigger('click')
    expect(get).toHaveBeenCalledWith('/orders', expect.objectContaining({ view: 'overweight' }), { preserveState: true, replace: true })
  })

  it('applies a saved filter definition from the shared saved_views authority', async () => {
    const wrapper = mount(Index, { props })
    await wrapper.findAll('button').find(b => b.text() === '我的待发')!.trigger('click')
    expect(get).toHaveBeenCalledWith('/orders', expect.objectContaining({ view: 'pending', source_type: 'manual' }), { preserveState: true, replace: true })
  })

  it('saves the current filters as a definition', async () => {
    const wrapper = mount(Index, { props })
    await wrapper.findAll('button').find(b => b.text() === '＋ 新建视图')!.trigger('click')
    await wrapper.find('.new-view-panel input').setValue('美国待发')
    await wrapper.find('.new-view-panel').trigger('submit')
    expect(post).toHaveBeenCalledWith('/orders/views', expect.objectContaining({
      name: '美国待发',
      filters_json: expect.objectContaining({ view: 'all' }),
      is_pinned: true,
    }), expect.objectContaining({ preserveScroll: true }))
  })

  it('renders the ERP order identity with the external order as secondary text', () => {
    const wrapper = mount(Index, { props: {
      ...props,
      orders: { ...props.orders, data: [{ id: 9, erp_order_id: '007-0009', external_order_number: 'WEB-9', internal_order_number: 'INT-9', source_label: 'WooCommerce', order_date: '2026-09-08', order_status_label: '待发货', address: { recipient_name: 'Tester', country: 'US' }, owner_label: null }] },
    } })
    expect(wrapper.text()).toContain('007-0009')
    expect(wrapper.text()).toContain('来源订单：WEB-9')
    expect(wrapper.text()).not.toContain('INT-9')
    expect(wrapper.find('thead th:last-child .column-settings-trigger').exists()).toBe(true)
  })
})
