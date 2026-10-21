import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Foundation from './Foundation.vue'

describe('Architecture foundation page', () => {
    it('renders typed props and updates state through UiButton', async () => {
        const wrapper = mount(Foundation, { props: { title: 'BYL ERP', status: 'SYSTEM ONLINE' } })
        expect(wrapper.text()).toContain('BYL ERP')
        expect(wrapper.text()).toContain('SYSTEM ONLINE')
        expect(wrapper.get('button').classes()).toContain('ui-button')
        await wrapper.get('button').trigger('click')
        expect(wrapper.text()).toContain('Foundation 已确认')
    })

    it('scopes all semantic colors through the dark BYL theme', () => {
        const wrapper = mount(Foundation, { props: { title: 'BYL ERP', status: 'SYSTEM ONLINE' } })
        expect(wrapper.get('main').attributes('data-byl-theme')).toBe('dark')
        expect(wrapper.get('main').classes()).toContain('foundation-page')
        expect(wrapper.get('section').classes()).toContain('foundation-card')
    })
})
