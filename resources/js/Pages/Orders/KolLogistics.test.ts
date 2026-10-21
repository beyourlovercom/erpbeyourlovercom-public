import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import KolLogistics from './KolLogistics.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<div />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn() },
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const row = {
  id: 93, erp_order_id: '007erp-1009-k93',
  external_order_number: 'E2E-KOL-SPECIALIST', order_date: '2026-10-09',
  owner: 'Specialist', shipment_status: 'shipped',
  warehouse: null, carrier: 'DHL', tracking_number: 'E2E-TRACK',
  shipped_at: null as string | null,
}

const propsFor = (shipment_status: string) => ({
  orders: { data: [{ ...row, shipment_status }], current_page: 1, last_page: 1, total: 1 },
  filters: { search: '', status: 'all' },
  isManager: false,
})

describe('KOL Logistics read-only status evidence', () => {
  it('does not claim a shipped order is unshipped if shipped_at is missing', () => {
    const wrapper = mount(KolLogistics, { props: propsFor('shipped') })
    expect(wrapper.find('.mvp-status').text()).toBe('已发货')
    expect(wrapper.find('.card-foot').text()).toContain('发货时间：未记录')
    expect(wrapper.find('.details').text()).toContain('仓库：未记录')
    expect(wrapper.text()).not.toContain('尚未发货')
  })

  it('preserves pending wording when shipment has not started', () => {
    const wrapper = mount(KolLogistics, { props: propsFor('pending') })
    expect(wrapper.find('.mvp-status').text()).toBe('待发货')
    expect(wrapper.find('.card-foot').text()).toContain('发货时间：尚未发货')
    expect(wrapper.find('.details').text()).toContain('仓库：待分配')
  })

  it('shows an actual shipping timestamp unchanged', () => {
    const props = propsFor('shipped')
    props.orders.data[0].shipped_at = '2026-10-09 09:00:00'
    const wrapper = mount(KolLogistics, { props })
    expect(wrapper.find('.card-foot').text()).toContain('2026-10-09 09:00:00')
  })
})
