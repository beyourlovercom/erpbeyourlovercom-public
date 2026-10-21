import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head><slot /></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn() },
    usePage: () => ({
        url: '/customers/companies',
        props: {
            auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } },
            shellNavigation: [{ key: 'customers', label: '客户', path: '/customers', route: 'shell.customers', placeholder: true, children: [] }],
        },
    }),
}))

const baseProps = {
    filters: {},
    owners: [{ id: 1, name: 'Owner' }],
}

describe('company index page', () => {
    it('shows the create link when the viewer can operate', () => {
        const wrapper = mount(Index, { props: { ...baseProps, companies: [], canOperate: true } })
        expect(wrapper.find('a[href="/customers/companies/create"]').exists()).toBe(true)
    })

    it('hides the create link for read-only viewers', () => {
        const wrapper = mount(Index, { props: { ...baseProps, companies: [], canOperate: false } })
        expect(wrapper.find('a[href="/customers/companies/create"]').exists()).toBe(false)
    })

    it('renders companies with archived status and an empty-state row when there are none', () => {
        const empty = mount(Index, { props: { ...baseProps, companies: [], canOperate: true } })
        expect(empty.text()).toContain('暂无符合条件的公司')

        const wrapper = mount(Index, {
            props: {
                ...baseProps,
                canOperate: true,
                companies: [
                    { id: 1, name: 'Active Co', legal_name: null, website_domain_normalized: 'active.example', country_code: 'US', registration_number: null, owner: null, status: 'prospect', archived_at: null },
                    { id: 2, name: 'Archived Co', legal_name: null, website_domain_normalized: null, country_code: null, registration_number: null, owner: null, status: 'prospect', archived_at: '2026-08-23T00:00:00Z' },
                ],
            },
        })
        expect(wrapper.text()).toContain('Active Co')
        expect(wrapper.text()).toContain('已归档')
    })

    it('shows the restore action only for archived companies when the viewer can operate', () => {
        const wrapper = mount(Index, {
            props: {
                ...baseProps,
                canOperate: true,
                companies: [
                    { id: 1, name: 'Active Co', legal_name: null, website_domain_normalized: null, country_code: null, registration_number: null, owner: null, status: 'prospect', archived_at: null },
                    { id: 2, name: 'Archived Co', legal_name: null, website_domain_normalized: null, country_code: null, registration_number: null, owner: null, status: 'prospect', archived_at: '2026-08-23T00:00:00Z' },
                ],
            },
        })
        const buttons = wrapper.findAll('button').filter((button) => button.text() === '取消归档')
        expect(buttons).toHaveLength(1)
    })

    it('hides the restore action for read-only viewers even on archived companies', () => {
        const wrapper = mount(Index, {
            props: {
                ...baseProps,
                canOperate: false,
                companies: [
                    { id: 2, name: 'Archived Co', legal_name: null, website_domain_normalized: null, country_code: null, registration_number: null, owner: null, status: 'prospect', archived_at: '2026-08-23T00:00:00Z' },
                ],
            },
        })
        expect(wrapper.findAll('button').some((button) => button.text() === '取消归档')).toBe(false)
    })
})
