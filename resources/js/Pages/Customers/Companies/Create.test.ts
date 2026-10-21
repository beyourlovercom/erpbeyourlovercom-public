import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Create from './Create.vue'

const post = vi.fn()
let form = { name: '', legal_name: '', website_url: '', country_code: '', registration_number: '', industry: '', owner_id: '', processing: false, errors: {} as Record<string, string>, post }

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head><slot /></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn() },
    usePage: () => ({
        url: '/customers/companies/create',
        props: {
            auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } },
            shellNavigation: [],
        },
    }),
    useForm: () => form,
}))

describe('company create page', () => {
    beforeEach(() => {
        post.mockClear()
        form = { name: '', legal_name: '', website_url: '', country_code: '', registration_number: '', industry: '', owner_id: '', processing: false, errors: {}, post }
    })

    it('posts the form to the companies endpoint', async () => {
        const wrapper = mount(Create, { props: { owners: [] } })
        await wrapper.get('form').trigger('submit')
        expect(post).toHaveBeenCalledWith('/customers/companies')
    })

    it('renders a required name field', () => {
        const wrapper = mount(Create, { props: { owners: [] } })
        expect(wrapper.get('input').attributes('required')).toBeDefined()
    })

    it('surfaces server-side validation errors for website_url and country_code', () => {
        form = { ...form, errors: { website_url: '网址无效，请提供包含协议（如 https://）的有效网址。', country_code: '国家代码需为两位字母。' } }
        const wrapper = mount(Create, { props: { owners: [] } })
        expect(wrapper.text()).toContain('网址无效')
        expect(wrapper.text()).toContain('国家代码需为两位字母')
    })
})
