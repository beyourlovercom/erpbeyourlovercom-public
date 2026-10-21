import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<title />' }, Link: { template: '<a><slot /></a>' } }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

describe('Support ticket show', () => {
  it('renders frozen Chinese status, priority and source labels', () => {
    const wrapper = mount(Show, { props: { customer: { id: 7, name: '客户', customer_number: 'CUS-00000007' }, ticket: { id: 11, ticket_number: 'TKT-00000011', subject: '问题', status: 'waiting_internal', priority: 'urgent', source_type: 'manual', assignee: null, last_activity_at: null, description: null }, canOperate: false } })
    expect(wrapper.text()).toContain('内部处理中')
    expect(wrapper.text()).toContain('紧急')
    expect(wrapper.text()).toContain('手工录入')
    expect(wrapper.find('a').attributes('href')).toContain('/customers/library/7')
  })
})
