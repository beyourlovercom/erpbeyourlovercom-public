import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Login from './Login.vue'

const post = vi.fn()
let form = { email: '', password: '', processing: false, errors: {}, post }

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head><slot /></head>' },
    usePage: () => ({ props: { errors: {} } }),
    useForm: () => form,
}))

describe('login page', () => {
    beforeEach(() => {
        post.mockClear()
        form = { email: '', password: '', processing: false, errors: {}, post }
    })

    it('renders fields, approved account text, no registration and robots policy', () => {
        const wrapper = mount(Login)
        expect(wrapper.get('input[name="email"]').attributes()).toMatchObject({ id: 'email', type: 'text', autocomplete: 'username', placeholder: '用户名或邮箱' })
        expect(wrapper.get('input[name="password"]').attributes()).toMatchObject({ id: 'password', type: 'password', autocomplete: 'current-password' })
        expect(wrapper.get('label[for="email"]').text()).toContain('名称或邮箱')
        expect(wrapper.get('label[for="password"]').text()).toContain('密码')
        expect(wrapper.text()).toContain('登录系统')
        expect(wrapper.text()).toContain('申请开通账户')
        expect(wrapper.text()).toContain('BYL ERP 账户由管理员统一创建，请联系系统管理员开通。')
        expect(wrapper.find('a[href*="register"]').exists()).toBe(false)
        expect(wrapper.find('form[action*="register"]').exists()).toBe(false)
        expect(wrapper.find('input[name="remember"]').exists()).toBe(false)
        expect(wrapper.get('meta[name="robots"]').attributes('content')).toBe('noindex, nofollow')
        expect(wrapper.get('main').attributes('data-byl-theme')).toBe('dark')
    })

    it('preserves password reveal behavior', async () => {
        const wrapper = mount(Login)
        expect(wrapper.get('input[name="password"]').attributes('type')).toBe('password')
        await wrapper.get('[aria-label="显示密码"]').trigger('click')
        expect(wrapper.get('input[name="password"]').attributes('type')).toBe('text')
        expect(wrapper.get('[aria-label="隐藏密码"]').text()).toBe('隐藏')
    })

    it('posts credentials to login with the normal button copy', async () => {
        form = { email: 'user@example.com', password: 'secret', processing: false, errors: {}, post }
        const wrapper = mount(Login)
        const submit = wrapper.get('button[type="submit"]')
        expect(submit.text()).toBe('登录系统')
        expect(submit.attributes('disabled')).toBeUndefined()
        await wrapper.get('form').trigger('submit')
        expect(post).toHaveBeenCalledWith('/login')
        expect(form).not.toHaveProperty('remember')
    })

    it('uses the approved Login loading copy and accessibility state', () => {
        form = { email: '', password: '', processing: true, errors: {}, post }
        const wrapper = mount(Login)
        const submit = wrapper.get('button[type="submit"]')
        expect(submit.text()).toBe('验证中…')
        expect(submit.text()).not.toContain('处理中…')
        expect(submit.attributes('disabled')).toBeDefined()
        expect(submit.attributes('aria-busy')).toBe('true')
    })

    it('shows the generic server validation error through UiAlert', () => {
        form = { email: '', password: '', processing: false, errors: { email: 'Internal detail' }, post }
        const alert = mount(Login).get('[role="alert"]')
        expect(alert.text()).toContain('名称/邮箱或密码不正确，请重试。')
        expect(alert.text()).not.toContain('Internal detail')
    })
})
