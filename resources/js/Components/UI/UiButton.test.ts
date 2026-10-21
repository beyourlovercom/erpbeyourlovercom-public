import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import UiButton from './UiButton.vue'

const unavailableStates: Array<{ disabled?: boolean; loading?: boolean }> = [
    { disabled: true },
    { loading: true },
]

describe('UiButton', () => {
    it('renders one native button with defaults and forwards approved attributes', () => {
        const wrapper = mount(UiButton, {
            attrs: { id: 'save', name: 'action', form: 'profile', 'aria-label': '保存', 'data-test': 'save', class: 'consumer-class' },
            slots: { default: '保存' },
        })
        const button = wrapper.get('button')
        expect(wrapper.findAll('button')).toHaveLength(1)
        expect(button.attributes()).toMatchObject({ id: 'save', name: 'action', form: 'profile', type: 'button', 'aria-label': '保存', 'data-test': 'save' })
        expect(button.classes()).toEqual(expect.arrayContaining(['ui-button', 'ui-button--secondary', 'ui-button--md', 'consumer-class']))
    })

    it.each([
        ['primary', 'sm'],
        ['secondary', 'md'],
        ['quiet', 'sm'],
    ] as const)('applies the frozen %s tone and %s size', (tone, size) => {
        const button = mount(UiButton, { props: { tone, size } }).get('button')
        expect(button.classes()).toEqual(expect.arrayContaining([`ui-button--${tone}`, `ui-button--${size}`]))
    })

    it('emits one click when available', async () => {
        const listener = vi.fn()
        const wrapper = mount(UiButton, { attrs: { onClick: listener } })
        await wrapper.get('button').trigger('click')
        expect(listener).toHaveBeenCalledOnce()
        expect(wrapper.emitted('click')).toHaveLength(1)
    })

    it.each(unavailableStates)('prevents activation when unavailable: %o', async (state) => {
        const listener = vi.fn()
        const wrapper = mount(UiButton, { props: state, attrs: { onClick: listener } })
        const button = wrapper.get('button')
        expect(button.attributes('disabled')).toBeDefined()
        expect(button.attributes('aria-disabled')).toBe('true')
        await button.trigger('click')
        expect(listener).not.toHaveBeenCalled()
        expect(wrapper.emitted('click')).toBeUndefined()
    })

    it('renders the generic loading fallback or caller override with aria-busy', () => {
        const fallback = mount(UiButton, { props: { loading: true }, slots: { default: '保存' } })
        expect(fallback.text()).toBe('处理中…')
        expect(fallback.get('button').attributes('aria-busy')).toBe('true')

        const overridden = mount(UiButton, { props: { loading: true }, slots: { default: '登录系统', loading: '验证中…' } })
        expect(overridden.text()).toBe('验证中…')
        expect(overridden.text()).not.toContain('处理中…')
    })

    it('rejects form override and all other unfrozen attributes', async () => {
        const mouseover = vi.fn()
        const button = mount(UiButton, {
            attrs: {
                formaction: '/unsafe',
                formmethod: 'get',
                formenctype: 'text/plain',
                formtarget: '_blank',
                tabindex: '4',
                title: 'unfrozen',
                onMouseover: mouseover,
            },
        }).get('button')
        expect(button.attributes('formaction')).toBeUndefined()
        expect(button.attributes('formmethod')).toBeUndefined()
        expect(button.attributes('formenctype')).toBeUndefined()
        expect(button.attributes('formtarget')).toBeUndefined()
        expect(button.attributes('tabindex')).toBeUndefined()
        expect(button.attributes('title')).toBeUndefined()
        await button.trigger('mouseover')
        expect(mouseover).not.toHaveBeenCalled()
    })

    it('keeps component-owned type and availability attributes authoritative', () => {
        const button = mount(UiButton, {
            props: { type: 'submit', loading: true },
            attrs: { type: 'reset', disabled: false, 'aria-disabled': 'false', 'aria-busy': 'false' },
        }).get('button')
        expect(button.attributes('type')).toBe('submit')
        expect(button.attributes('disabled')).toBeDefined()
        expect(button.attributes('aria-disabled')).toBe('true')
        expect(button.attributes('aria-busy')).toBe('true')
    })
})
