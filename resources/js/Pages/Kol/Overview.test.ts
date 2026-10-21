import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Overview from './Overview.vue'

const { getMock } = vi.hoisted(() => ({ getMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: getMock },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
const overview = { total: 4, mine: null, monthly_new: 2, collaborated: 1, long_term: 1, hundred_k_plus: 1, by_stage: { cooperating: 1 }, by_level: {}, by_content_format: {}, by_platform: { instagram: 2 }, owner_ranking: [], pending_publication_count: null, pending_commission_amount: null }
const operations = { period: 'week' as const, period_label: '本周', period_start: '2026-08-31', period_end: '2026-09-01', scope: 'all' as const, new_kols: 2, approved_kols: 1, rejected_kols: 0, collaboration_records: 3, publication_records: 2, affiliate: [{ currency: 'USD', sales_count: 2, commission_total: '20.000000' }], eligible_commission: [{ currency: 'USD', referral_count: 1, commission_total: '5.000000' }], payout_period_statuses: { pending_approval: 1 }, payout_current_statuses: { pending_approval: 1, approved: 2 }, manager_tasks: { kols: 1, collaborations: 2, payouts: 1 } }
const baseProps = { overview, operations, filters: { period: 'week' }, ownerOptions: [], canOperate: true, canManageViews: true, canManageApprovals: true, canAccessPayouts: true }

describe('KOL operational overview', () => {
  beforeEach(() => getMock.mockReset())
  it('shows canonical manager tasks and currency-separated commission facts', () => {
    const wrapper = mount(Overview, { props: baseProps })
    expect(wrapper.text()).toContain('Manager 待办')
    expect(wrapper.text()).toContain('Affiliate 成交 Referral')
    expect(wrapper.text()).toContain('USD · 2 单')
    expect(wrapper.find('a[href="/kol/approvals/kols"]').exists()).toBe(true)
  })
  it('switches the canonical period to month', async () => {
    const wrapper = mount(Overview, { props: baseProps })
    await wrapper.findAll('button').find(button => button.text() === '本月')!.trigger('click')
    expect(getMock).toHaveBeenCalledWith('/kol', expect.objectContaining({ period: 'month' }), { preserveState: true, replace: true })
  })
  it('specialist view hides manager approval center', () => {
    const specialistOps = { ...operations, scope: 'mine' as const, manager_tasks: null }
    const wrapper = mount(Overview, { props: { ...baseProps, operations: specialistOps, canManageViews: false, canManageApprovals: false } })
    expect(wrapper.text()).toContain('我的负责人范围')
    expect(wrapper.find('a[href="/kol/approvals/kols"]').exists()).toBe(false)
  })
})
