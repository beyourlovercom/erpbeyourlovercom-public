import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Create from './Create.vue'

const post = vi.fn()
let form = { name: '', email: '', password: '', password_confirmation: '', role: '', processing: false, errors: {} as Record<string, string>, post }

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head><slot /></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn() },
    usePage: () => ({
        url: '/settings/users/create',
        props: {
            auth: { user: { id: 1, name: 'Admin', email: 'admin@example.test' } },
            shellNavigation: [],
        },
    }),
    useForm: () => form,
}))

describe('user create page', () => {
    beforeEach(() => {
        post.mockClear()
        form = { name: '', email: '', password: '', password_confirmation: '', role: '', processing: false, errors: {}, post }
    })

    it('posts the form to the users endpoint', async () => {
        const wrapper = mount(Create, { props: { rolePresets: [{ value: 'administrator', label: '管理员' }] } })
        await wrapper.get('form').trigger('submit')
        expect(post).toHaveBeenCalledWith('/settings/users')
    })

    it('renders the eight role presets with Chinese labels', () => {
        const rolePresets = [
            { value: 'logistics', label: '物流' },
            { value: 'sales_representative', label: '业务员' },
            { value: 'kol_specialist', label: '网红专员' },
            { value: 'kol_manager', label: '网红经理' },
            { value: 'finance', label: '财务' },
            { value: 'operations', label: '运营' },
            { value: 'writer', label: '写手' },
            { value: 'administrator', label: '管理员' },
        ]
        const wrapper = mount(Create, { props: { rolePresets } })
        const options = wrapper.findAll('option').map((option) => option.text())
        for (const preset of rolePresets) {
            expect(options).toContain(preset.label)
        }
    })

    it('surfaces server-side validation errors in Chinese', () => {
        form = { ...form, errors: { email: '该邮箱已被使用。', password: '密码至少需要 12 个字符。' } }
        const wrapper = mount(Create, { props: { rolePresets: [] } })
        expect(wrapper.text()).toContain('该邮箱已被使用');
        expect(wrapper.text()).toContain('密码至少需要 12 个字符');
    })
})
