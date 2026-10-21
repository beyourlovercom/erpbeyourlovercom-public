import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TicketForm from './TicketForm.vue'

const { post, patch } = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  Link: { template: '<a><slot /></a>' },
  router: { post, patch },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const props = { customer: { id: 7, name: '客户', customer_number: 'CUS-00000007' }, ticket: null, statuses: ['open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed'], priorities: ['low', 'normal', 'high', 'urgent'], users: [{ id: 2, name: 'Alice' }] }

describe('Support ticket form', () => {
  it('uses Chinese labels and renders every writable field error', async () => {
    const wrapper = mount(TicketForm, { props: { ...props, editing: true } })
    expect(wrapper.find('#ticket-priority').text()).toContain('普通')
    expect(wrapper.find('#ticket-status').text()).toContain('已解决')
    patch.mockImplementationOnce((_url: string, _data: unknown, options: { onError: (errors: Record<string, string>) => void }) => options.onError({ description: '描述错误', priority: '优先级错误', assigned_user_id: '负责人错误', status: '状态错误' }))
    await wrapper.get('button.erp-btn-primary').trigger('click')
    expect(wrapper.text()).toContain('描述错误')
    expect(wrapper.text()).toContain('优先级错误')
    expect(wrapper.text()).toContain('负责人错误')
    expect(wrapper.text()).toContain('状态错误')
  })
})
