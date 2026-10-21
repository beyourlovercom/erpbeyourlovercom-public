import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EmptyState from './EmptyState.vue'

describe('EmptyState', () => {
    it.each([2, 3] as const)('renders the requested native h%s', (headingLevel) => {
        const wrapper = mount(EmptyState, { props: { title: '尚未实现', headingLevel }, attrs: { 'aria-labelledby': 'state-title' } })
        expect(wrapper.get(`h${headingLevel}`).attributes('id')).toBe('state-title')
        expect(wrapper.get(`h${headingLevel}`).text()).toBe('尚未实现')
    })

    it('renders either description props or the default slot without inventing copy', () => {
        expect(mount(EmptyState, { props: { title: '空', description: '暂无数据' } }).text()).toContain('暂无数据')
        const slot = mount(EmptyState, { props: { title: '空', description: '不应出现' }, slots: { default: '自定义说明' } })
        expect(slot.text()).toContain('自定义说明')
        expect(slot.text()).not.toContain('不应出现')
    })

    it('renders optional icon and actions and forwards root attributes', () => {
        const wrapper = mount(EmptyState, {
            props: { title: '空', eyebrow: 'APPLICATION SHELL' },
            attrs: { id: 'empty', class: 'consumer-empty', 'data-test': 'empty' },
            slots: { icon: '<span>图标</span>', actions: '<button>继续</button>' },
        })
        expect(wrapper.attributes()).toMatchObject({ id: 'empty', 'data-test': 'empty' })
        expect(wrapper.classes()).toContain('consumer-empty')
        expect(wrapper.text()).toContain('APPLICATION SHELL')
        expect(wrapper.get('button').text()).toBe('继续')
    })

    it('rejects role, tabindex, events and other unfrozen root attributes', async () => {
        const click = vi.fn()
        const wrapper = mount(EmptyState, {
            props: { title: '空' },
            attrs: { role: 'button', tabindex: '0', title: 'unfrozen', 'aria-label': 'unapproved', 'data-test': 'empty', onClick: click },
        })
        expect(wrapper.attributes('role')).toBeUndefined()
        expect(wrapper.attributes('tabindex')).toBeUndefined()
        expect(wrapper.attributes('title')).toBeUndefined()
        expect(wrapper.attributes('aria-label')).toBeUndefined()
        expect(wrapper.attributes('data-test')).toBe('empty')
        await wrapper.trigger('click')
        expect(click).not.toHaveBeenCalled()
    })
})
