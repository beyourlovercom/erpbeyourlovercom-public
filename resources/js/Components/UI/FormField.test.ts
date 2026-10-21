import { h } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FormField from './FormField.vue'

describe('FormField', () => {
    it('connects its label to the stable control id', () => {
        const wrapper = mount(FormField, {
            props: { id: 'email', label: '邮箱' },
            slots: { default: ({ controlId }: { controlId: string }) => h('input', { id: controlId }) },
        })
        expect(wrapper.get('label').attributes('for')).toBe('email')
        expect(wrapper.get('input').attributes('id')).toBe('email')
    })

    it('composes description and error ids in stable order', () => {
        const wrapper = mount(FormField, {
            props: { id: 'password', label: '密码', description: '至少八位', error: '密码无效' },
            slots: {
                default: ({ controlId, describedBy, invalid }: { controlId: string; describedBy?: string; invalid: boolean }) => h('input', { id: controlId, 'aria-describedby': describedBy, 'aria-invalid': invalid }),
            },
        })
        expect(wrapper.get('input').attributes('aria-describedby')).toBe('password-description password-error')
        expect(wrapper.get('#password-description').text()).toBe('至少八位')
        expect(wrapper.get('#password-error').attributes('role')).toBe('alert')
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true')
    })

    it('renders required semantics and named slot overrides', () => {
        const wrapper = mount(FormField, {
            props: { id: 'name', label: '姓名', description: '说明', error: '错误', required: true },
            attrs: { 'data-test': 'field', class: 'consumer-field' },
            slots: { label: '自定义姓名', description: '自定义说明', error: '自定义错误' },
        })
        expect(wrapper.attributes('data-test')).toBe('field')
        expect(wrapper.classes()).toContain('consumer-field')
        expect(wrapper.text()).toContain('自定义姓名')
        expect(wrapper.text()).toContain('（必填）')
        expect(wrapper.text()).toContain('自定义说明')
        expect(wrapper.text()).toContain('自定义错误')
    })

    it('does not fabricate description or error associations', () => {
        let slotContract: { describedBy?: string; invalid: boolean } | undefined
        mount(FormField, {
            props: { id: 'plain', label: '普通字段' },
            slots: { default: (contract: { describedBy?: string; invalid: boolean }) => { slotContract = contract; return h('input') } },
        })
        expect(slotContract).toMatchObject({ describedBy: undefined, invalid: false })
    })

    it('uses description and error slot presence for the complete field contract', () => {
        const wrapper = mount(FormField, {
            props: { id: 'email', label: '邮箱' },
            slots: {
                default: ({ controlId, describedBy, invalid }: { controlId: string; describedBy?: string; invalid: boolean }) => h('input', { id: controlId, 'aria-describedby': describedBy, 'aria-invalid': invalid }),
                description: '账户邮箱',
                error: '邮箱格式错误',
            },
        })
        expect(wrapper.get('#email-description').text()).toBe('账户邮箱')
        expect(wrapper.get('#email-error').text()).toBe('邮箱格式错误')
        expect(wrapper.get('#email-error').attributes('role')).toBe('alert')
        expect(wrapper.get('input').attributes('aria-describedby')).toBe('email-description email-error')
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true')
    })

    it('forwards only wrapper class, style, data and aria attributes', async () => {
        const click = vi.fn()
        const wrapper = mount(FormField, {
            props: { id: 'field-control', label: '字段' },
            attrs: { id: 'unsafe-wrapper-id', role: 'button', tabindex: '0', title: 'unfrozen', class: 'consumer-field', style: 'display: block;', 'data-test': 'field', 'aria-label': '字段容器', onClick: click },
        })
        expect(wrapper.attributes('id')).toBeUndefined()
        expect(wrapper.attributes('role')).toBeUndefined()
        expect(wrapper.attributes('tabindex')).toBeUndefined()
        expect(wrapper.attributes('title')).toBeUndefined()
        expect(wrapper.classes()).toContain('consumer-field')
        expect(wrapper.attributes('data-test')).toBe('field')
        expect(wrapper.attributes('aria-label')).toBe('字段容器')
        await wrapper.trigger('click')
        expect(click).not.toHaveBeenCalled()
    })
})
