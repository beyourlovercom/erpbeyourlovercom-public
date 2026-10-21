import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Placeholder from './Placeholder.vue'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head><slot /></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn() },
    usePage: () => ({ url: '/logistics', props: { auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } }, shellNavigation: [{ key: 'logistics', label: '物流', path: '/logistics', route: 'shell.logistics', placeholder: true, children: [] }] } }),
}))

describe('shell placeholder', () => {
    it('preserves authorized copy and heading association through EmptyState', () => {
        const wrapper = mount(Placeholder, { props: { module: { key: 'logistics', label: '物流' } } })
        const section = wrapper.get('.shell-placeholder')
        expect(section.attributes('aria-labelledby')).toBe('placeholder-title')
        expect(section.get('#placeholder-title').text()).toBe('物流模块尚未开始实现')
        expect(section.text()).toContain('后续授权工作包')
    })

    it('does not fabricate business data or actions', () => {
        const wrapper = mount(Placeholder, { props: { module: { key: 'logistics', label: '物流' } } })
        expect(wrapper.text()).not.toMatch(/订单号|待发货数量|成本|利润/)
        expect(wrapper.find('.empty-state__actions').exists()).toBe(false)
    })
})
