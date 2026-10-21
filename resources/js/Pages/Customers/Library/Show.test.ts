import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn() },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const props = {
  customer: { id: 7, customer_number: 'CUS-00000007', name: '测试客户', email: null, phone: null, country_code: 'US', language_code: 'en', source_label: '手工', owner: null, notes: null, archived_at: null, created_at: null },
  billingAddress: null,
  shippingAddress: null,
  metrics: { order_count: 0, first_order_date: null, last_order_date: null, revenue_by_currency: [], shipment_count: 0 },
  recentOrders: [],
  shipments: [],
  subscription: { matched: false, subscription_facts: [] },
  audit: [],
  systemSegments: [],
  manualGroups: [],
  canOperate: true,
  pendingSupportTickets: 1,
  supportTickets: [{ id: 11, ticket_number: 'TKT-00000011', subject: '需要帮助', status: 'waiting_customer', priority: 'high', source_type: 'manual', assignee: null, last_activity_at: null, created_at: null }],
}

describe('Customer360 support integration', () => {
  it('renders linked support truth with Chinese labels and customer-scoped create action', () => {
    const wrapper = mount(Show, { props })
    expect(wrapper.text()).toContain('客户支持工单')
    expect(wrapper.text()).toContain('TKT-00000011')
    expect(wrapper.text()).toContain('等待客户')
    expect(wrapper.text()).toContain('高')
    expect(wrapper.text()).toContain('手工录入')
    expect(wrapper.text()).toContain('待处理工单')
    expect(wrapper.find(`a[href="/customers/library/7/support-tickets/create"]`).exists()).toBe(true)
  })


  it('renders section navigation, group truth, subscriber summary and human-readable activity', () => {
    const wrapper = mount(Show, { props: {
      ...props,
      systemSegments: [{ key: 'active_customer', label: '活跃客户' }],
      manualGroups: [{ id: 9, name: '2026 黑五重点客户', description: null }],
      subscription: { matched: true, subscription_facts: [{ id: 1, channel: 'email', provider: 'wordpress', source_site: 'site-a', status: 'subscribed', consent_source: 'form', subscribed_at: null, unsubscribed_at: null, status_updated_at: null, last_synced_at: null }] },
      audit: [{ action: 'customer.updated', action_label: '更新客户', actor_id: 3, actor_name: 'UAT Admin', created_at: '2026-08-31T12:00:00+00:00', reason: 'customer_profile_corrected' }],
    } })
    for (const href of ['#groups', '#addresses', '#orders', '#shipments', '#subscriptions', '#support', '#activity']) {
      expect(wrapper.find(`a[href="${href}"]`).exists()).toBe(true)
    }
    expect(wrapper.text()).toContain('活跃客户')
    expect(wrapper.text()).toContain('2026 黑五重点客户')
    expect(wrapper.text()).toContain('身份匹配：已匹配 · 订阅事实：1 条')
    expect(wrapper.text()).toContain('更新客户')
    expect(wrapper.text()).toContain('UAT Admin')
    expect(wrapper.text()).not.toContain('2026-08-31T12:00:00+00:00')
  })
})
