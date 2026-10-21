import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ url: '/logistics/carriers' }),
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (initial: Record<string, unknown>) => ({ ...initial, processing: false, errors: {}, post: vi.fn(), reset: vi.fn() }),
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const carrier = { id: 1, name: 'DHL', display_name: 'DHL', is_active: true, shipment_count: 3, chargeable_weight_kg: 4.25, chargeable_weight_coverage: 2, costs_by_currency: [{ currency: 'USD', total: 42, average: 21, shipments: 2 }], this_month_count: 2, last_shipped_at: '2026-08-28T08:00:00Z' }

describe('Carrier master workspace', () => {
  it('renders carrier master rows and canonical detail links', () => {
    const wrapper = mount(Index, { props: { carriers: [carrier], canOperate: false } })
    expect(wrapper.text()).toContain('Carrier Master')
    expect(wrapper.text()).toContain('DHL')
    expect(wrapper.text()).toContain('USD 42.00')
    expect(wrapper.find('a[href="/logistics/carriers/DHL"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('新增承运商')
  })

  it('shows the top-right add carrier control only to operators', async () => {
    const wrapper = mount(Index, { props: { carriers: [carrier], canOperate: true } })
    const button = wrapper.findAll('button').find((item) => item.text().includes('新增承运商'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(wrapper.find('form.carrier-create').exists()).toBe(true)
    expect(wrapper.find('input[placeholder="例如 UniUni"]').exists()).toBe(true)
  })
})
