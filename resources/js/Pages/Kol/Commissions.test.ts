import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Commissions from './Commissions.vue'

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: () => ({ processing: false, errors: {}, post: postMock }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const summary = {
  referrals: 2, commission_amount: '17.50', currency: 'USD',
  by_status: { paid: { count: 1, amount: '12.50' }, unpaid: { count: 1, amount: '5.00' } },
  affiliate_unresolved: 0, order_unresolved: 0,
}
const referrals = [
  { id: 1, referral_id: 101, affiliate_id: 42, order_reference: '9001',
    kol: { id: 7, display_name: 'Creator A' }, order: { id: 8, erp_order_id: '007-0901-c9001', internal_order_number: 'ERP-9001', external_order_number: '9001' },
    commission_amount: '12.500000', currency: 'USD', provider_status: 'paid', context: 'woocommerce',
    referral_date: '2026-09-01T00:00:00+00:00', payout_id: 77,
    affiliate_resolution_status: 'bound', order_resolution_status: 'resolved', reconciliation_status: 'present' },
]

describe('Referral commission workspace', () => {
  beforeEach(() => postMock.mockReset())
  it('renders provider referral, canonical KOL/order and payout evidence', () => {
    const wrapper = mount(Commissions, { props: { canSync: true, canRequestPayouts: true, canManagePayouts: false, connectionKey: 'test.example.com', referrals, summary } })
    expect(wrapper.text()).toContain('Referral / Commission')
    expect(wrapper.text()).toContain('Creator A')
    expect(wrapper.text()).toContain('007-0901-c9001')
    expect(wrapper.text()).not.toContain('ERP-9001')
    expect(wrapper.text()).toContain('USD 12.500000')
    expect(wrapper.text()).toContain('#77')
  })
  it('manager sync calls the Referral sync endpoint', async () => {
    const wrapper = mount(Commissions, { props: { canSync: true, canRequestPayouts: true, canManagePayouts: false, connectionKey: 'test.example.com', referrals: [], summary } })
    await wrapper.get('button').trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/commissions/sync', { preserveScroll: true })
  })
  it('read-only user keeps sync disabled', () => {
    const wrapper = mount(Commissions, { props: { canSync: false, canRequestPayouts: false, canManagePayouts: false, connectionKey: 'test.example.com', referrals: [], summary } })
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
  })
})
