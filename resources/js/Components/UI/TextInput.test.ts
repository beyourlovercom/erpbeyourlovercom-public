import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TextInput from './TextInput.vue'

describe('TextInput', () => {
    it('implements modelValue and update:modelValue', async () => {
        const wrapper = mount(TextInput, { props: { id: 'email', modelValue: '' } })
        await wrapper.get('input').setValue('owner@example.test')
        expect(wrapper.emitted('update:modelValue')).toEqual([['owner@example.test']])
        expect(wrapper.emitted('input')).toHaveLength(1)
    })

    it('forwards the frozen native input attributes', () => {
        const wrapper = mount(TextInput, {
            props: { id: 'email', modelValue: '', type: 'email' },
            attrs: { name: 'email', autocomplete: 'username', required: true, placeholder: 'name@example.test', maxlength: '191', inputmode: 'email', 'aria-describedby': 'email-help', 'data-test': 'email', class: 'consumer-input' },
        })
        expect(wrapper.get('input').attributes()).toMatchObject({ id: 'email', type: 'email', name: 'email', autocomplete: 'username', required: '', placeholder: 'name@example.test', maxlength: '191', inputmode: 'email', 'aria-describedby': 'email-help', 'data-test': 'email' })
        expect(wrapper.get('input').classes()).toContain('consumer-input')
    })

    it.each(['text', 'email', 'password'] as const)('renders the frozen %s type', (type) => {
        expect(mount(TextInput, { props: { id: 'field', modelValue: '', type } }).get('input').attributes('type')).toBe(type)
    })

    it('renders the trailing slot outside the native input', () => {
        const wrapper = mount(TextInput, { props: { id: 'password', modelValue: '' }, slots: { trailing: '<button type="button">显示</button>' } })
        expect(wrapper.findAll('input')).toHaveLength(1)
        expect(wrapper.get('button').text()).toBe('显示')
        expect(wrapper.get('input').element.contains(wrapper.get('button').element)).toBe(false)
    })

    it('enforces disabled, readonly and invalid props', () => {
        const input = mount(TextInput, { props: { id: 'locked', modelValue: '', disabled: true, readonly: true, invalid: true }, attrs: { 'aria-invalid': 'false' } }).get('input')
        expect(input.attributes('disabled')).toBeDefined()
        expect(input.attributes('readonly')).toBeDefined()
        expect(input.attributes('aria-invalid')).toBe('true')
    })

    it('emits change, focus and blur native events', async () => {
        const handlers = { onChange: vi.fn(), onFocus: vi.fn(), onBlur: vi.fn() }
        const wrapper = mount(TextInput, { props: { id: 'events', modelValue: '' }, attrs: handlers })
        await wrapper.get('input').trigger('focus')
        await wrapper.get('input').trigger('change')
        await wrapper.get('input').trigger('blur')
        expect(handlers.onFocus).toHaveBeenCalledOnce()
        expect(handlers.onChange).toHaveBeenCalledOnce()
        expect(handlers.onBlur).toHaveBeenCalledOnce()
    })

    it('rejects attributes and event listeners outside the frozen input contract', async () => {
        const keydown = vi.fn()
        const input = mount(TextInput, {
            props: { id: 'safe-id', modelValue: '', type: 'email', disabled: true, readonly: true, invalid: true },
            attrs: { id: 'unsafe-id', type: 'text', value: 'unsafe', disabled: false, readonly: false, 'aria-invalid': 'false', form: 'unsafe', formaction: '/unsafe', tabindex: '3', title: 'unfrozen', onKeydown: keydown },
        }).get('input')
        expect(input.attributes('id')).toBe('safe-id')
        expect(input.attributes('type')).toBe('email')
        expect(input.element.value).toBe('')
        expect(input.attributes('disabled')).toBeDefined()
        expect(input.attributes('readonly')).toBeDefined()
        expect(input.attributes('aria-invalid')).toBe('true')
        expect(input.attributes('form')).toBeUndefined()
        expect(input.attributes('formaction')).toBeUndefined()
        expect(input.attributes('tabindex')).toBeUndefined()
        expect(input.attributes('title')).toBeUndefined()
        await input.trigger('keydown')
        expect(keydown).not.toHaveBeenCalled()
    })
})
