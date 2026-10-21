import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Payouts from './Payouts.vue'

const { postMock, formPostMock } = vi.hoisted(() => ({ postMock: vi.fn(), formPostMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  router: { post: postMock },
  useForm: (initial: Record<string, unknown>) => ({ ...initial, processing: false, errors: {}, post: formPostMock }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const eligible = [
  { referral_id: 11, affiliate_id: 7, kol_id: 5, amount: '4.25', currency: 'USD', order_number: '007-9001', source_order_number: '9001', order_reference: '9001', referral_date: '2026-09-01T00:00:00+00:00' },
  { referral_id: 12, affiliate_id: 7, kol_id: 5, amount: '5.75', currency: 'USD', order_number: '007-9002', source_order_number: '9002', order_reference: '9002', referral_date: '2026-09-01T00:00:00+00:00' },
]
const requests = [
  { id: 101, kol_id: 5, affiliate_id: 7, amount: '10.000000', currency: 'USD', status: 'approved', provider_payout_id: null, payout_recipient: 'creator-paypal@example.test', can_approve: false, can_complete: true, paid_at: null, requested_by_user_id: 2, approved_by_user_id: 3, referral_ids: [11, 12], created_at: '2026-09-01T01:00:00+00:00' },
]

describe('KOL payout workflow', () => {
  beforeEach(() => { postMock.mockReset(); formPostMock.mockReset() })
  it('renders eligible referrals and approved payout requests', () => {
    const wrapper = mount(Payouts, { props: { connectionKey: 'test.example.com', eligible, requests, canRequest: true, canApprove: false, canManage: true } })
    expect(wrapper.text()).toContain('Commission Payout Workflow')
    expect(wrapper.text()).toContain('Affiliate #7 · KOL #5')
    expect(wrapper.text()).toContain('#101 · approved')
    expect(wrapper.text()).toContain('USD 10.000000')
    expect(wrapper.text()).toContain('creator-paypal@example.test')
    expect(wrapper.text()).toContain('007-9001')
    expect(wrapper.text()).not.toContain('来源订单：9001')
    expect(wrapper.text()).not.toContain('internal_order_number')
    expect(wrapper.find('thead th:last-child .column-settings-trigger').exists()).toBe(true)
  })

  it('keeps ERP order number visible while allowing configurable columns to reorder', async () => {
    const wrapper = mount(Payouts, { props: { connectionKey: 'test.example.com', eligible, requests: [], canRequest: true, canApprove: false, canManage: false } })
    await wrapper.get('.column-settings-trigger').trigger('click')
    const required = wrapper.findAll('.column-setting-row').find(row => row.text().includes('ERP订单号'))!
    expect(required.get('input').attributes('disabled')).toBeDefined()
    const rows = wrapper.findAll('.column-setting-row')
    await rows.find(row => row.text().includes('Affiliate / KOL'))!.get('input').setValue(false)
    expect(wrapper.find('tbody tr').text()).not.toContain('Affiliate #7')
  })
  it('manager can select exact referrals and submit approval request', async () => {
    const wrapper = mount(Payouts, { props: { connectionKey: 'test.example.com', eligible, requests: [], canRequest: true, canApprove: false, canManage: false } })
    const checks = wrapper.findAll('input[type="checkbox"]')
    await checks[0].setValue(true); await checks[1].setValue(true)
    const submit = wrapper.findAll('button').find(b => b.text().includes('提交审批'))!
    await submit.trigger('click')
    expect(formPostMock).toHaveBeenCalledWith('/kol/payouts', expect.objectContaining({ preserveScroll: true }))
  })

  it('manager approval is separate from finance completion', async () => {
    const pending = [{ ...requests[0], status: 'pending_approval', approved_by_user_id: null, payout_recipient: null, can_approve: true, can_complete: false }]
    const wrapper = mount(Payouts, { props: { connectionKey: 'test.example.com', eligible: [], requests: pending, canRequest: false, canApprove: true, canManage: false } })
    const button = wrapper.findAll('button').find(b => b.text() === '批准')!
    await button.trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/payouts/101/approve', {}, { preserveScroll: true })
    expect(wrapper.text()).not.toContain('PayPal 收款')
    expect(wrapper.findAll('button').some(b => b.text().includes('确认已付款并写回'))).toBe(false)
  })

  it('finance completion posts manual PayPal evidence only from approved state', async () => {
    const wrapper = mount(Payouts, { props: { connectionKey: 'test.example.com', eligible: [], requests, canRequest: false, canApprove: false, canManage: true } })
    const inputs = wrapper.findAll('input')
    await inputs[0].setValue('TXN-123'); await inputs[1].setValue('2026-09-01')
    const button = wrapper.findAll('button').find(b => b.text().includes('确认已付款并写回'))!
    await button.trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/payouts/101/complete', { paypal_transaction_id: 'TXN-123', paid_at: '2026-09-01' }, { preserveScroll: true })
  })
  it('multi-role user gets no row controls or recipient for an unassigned responsibility', () => {
    const foreign = [{ ...requests[0], payout_recipient: null, can_approve: false, can_complete: false }]
    const wrapper = mount(Payouts, { props: { connectionKey: 'test.example.com', eligible: [], requests: foreign, canRequest: true, canApprove: true, canManage: true } })
    expect(wrapper.text()).not.toContain('PayPal 收款')
    expect(wrapper.findAll('button').some(b => b.text() === '批准')).toBe(false)
    expect(wrapper.findAll('button').some(b => b.text().includes('确认已付款并写回'))).toBe(false)
  })

})
