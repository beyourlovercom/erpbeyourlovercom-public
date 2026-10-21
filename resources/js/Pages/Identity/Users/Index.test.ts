import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { post, del } = vi.hoisted(() => ({
    post: vi.fn(),
    del: vi.fn(),
}))

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head><slot /></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post, delete: del },
    usePage: () => ({
        url: '/settings/users',
        props: {
            auth: { user: { id: 1, name: 'Admin', email: 'admin@example.test' } },
            shellNavigation: [],
        },
    }),
}))

const baseProps = {
    rolePresets: [{ value: 'administrator', label: '管理员' }],
}

describe('user index page', () => {
    it('shows a create link', () => {
        const wrapper = mount(Index, { props: { ...baseProps, users: [], selfId: 1 } })
        expect(wrapper.find('a[href="/settings/users/create"]').exists()).toBe(true)
    })

    it('renders users with Chinese status labels and an empty-state row when there are none', () => {
        const empty = mount(Index, { props: { ...baseProps, users: [], selfId: 1 } })
        expect(empty.text()).toContain('暂无用户')

        const wrapper = mount(Index, {
            props: {
                ...baseProps,
                selfId: 1,
                users: [
                    { id: 1, name: 'Active User', email: 'active@example.test', is_active: true, role: { key: 'role_preset_administrator', label: '管理员', preset: 'administrator' }, created_at: null },
                    { id: 2, name: 'Disabled User', email: 'disabled@example.test', is_active: false, role: null, created_at: null },
                ],
            },
        })
        expect(wrapper.text()).toContain('Active User')
        expect(wrapper.text()).toContain('已启用')
        expect(wrapper.text()).toContain('已停用')
        expect(wrapper.text()).toContain('管理员')
        expect(wrapper.text()).toContain('未分配')
    })

    it('disables self-disable and self-delete actions for the acting admin row', () => {
        const wrapper = mount(Index, {
            props: {
                ...baseProps,
                selfId: 1,
                users: [
                    { id: 1, name: 'Self', email: 'self@example.test', is_active: true, role: null, created_at: null },
                ],
            },
        })
        const buttons = wrapper.findAll('button')
        const disableButton = buttons.find((button) => button.text() === '停用')
        const deleteButton = buttons.find((button) => button.text() === '删除')
        expect(disableButton?.attributes('disabled')).toBeDefined()
        expect(deleteButton?.attributes('disabled')).toBeDefined()
    })

    it('allows enable/disable and delete actions for other users', () => {
        const wrapper = mount(Index, {
            props: {
                ...baseProps,
                selfId: 1,
                users: [
                    { id: 2, name: 'Other', email: 'other@example.test', is_active: true, role: null, created_at: null },
                ],
            },
        })
        const buttons = wrapper.findAll('button')
        const disableButton = buttons.find((button) => button.text() === '停用')
        const deleteButton = buttons.find((button) => button.text() === '删除')
        expect(disableButton?.attributes('disabled')).toBeUndefined()
        expect(deleteButton?.attributes('disabled')).toBeUndefined()
    })
})
