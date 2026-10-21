import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Dashboard.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

describe('Customer Dashboard support KPI', () => {
  it('renders the customer pending support metric', () => {
    const wrapper = mount(Dashboard, { props: {
      metrics: { total_customers: 1, new_this_month: 0, orders_this_month: 0, revenue_this_month: [], subscriber_identity_links: 0, pending_support_tickets: 3 },
      canOperate: false, canImport: false, canExport: false, canViewOrders: false,
    } })
    expect(wrapper.text()).toContain('待处理工单')
    expect(wrapper.text()).toContain('3')
  })


  it('renders stable Customer Hub workspaces with permission-gated order/import entries', () => {
    const base = {
      metrics: { total_customers: 1, new_this_month: 0, orders_this_month: 0, revenue_this_month: [], subscriber_identity_links: 0, pending_support_tickets: 0 },
      canOperate: false, canExport: false,
    }
    const full = mount(Dashboard, { props: { ...base, canImport: true, canViewOrders: true } })
    for (const href of ['/customers/library', '/customers/groups', '/customers/companies', '/customers/orders', '/customers/import']) {
      expect(full.find(`a[href="${href}"]`).exists()).toBe(true)
    }

    const restricted = mount(Dashboard, { props: { ...base, canImport: false, canViewOrders: false } })
    expect(restricted.find('a[href="/customers/library"]').exists()).toBe(true)
    expect(restricted.find('a[href="/customers/groups"]').exists()).toBe(true)
    expect(restricted.find('a[href="/customers/companies"]').exists()).toBe(true)
    expect(restricted.find('a[href="/customers/orders"]').exists()).toBe(false)
    expect(restricted.find('a[href="/customers/import"]').exists()).toBe(false)
  })
})
