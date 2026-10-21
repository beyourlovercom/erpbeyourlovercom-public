import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BindingActivation from './BindingActivation.vue'

const { routerGet, routerPost } = vi.hoisted(() => ({ routerGet: vi.fn(), routerPost: vi.fn() }))
vi.mock('axios', () => ({ default: { put: vi.fn() } }))
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' }, router: { get: routerGet, post: routerPost } }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const activation = {
  id: 11, reconciliation_batch_id: 7, import_preview_batch_id: 9, source_filename: 'P4B-UAT.xlsx', source_checksum: 'a'.repeat(64),
  connection_key: 'test.example.com', candidate_count: 12, created_count: 12, status: 'applied', applied_at: '2026-09-04T01:00:00Z',
}
const item = {
  id: 1, cluster_key: 'b'.repeat(64), canonical_kol_id: 44,
  kol: { id: 44, display_name: 'Creator One', contact_email: 'creator@example.com' },
  affiliate_account_id: 77, affiliate: { id: 77, affiliate_id: 501, display_name: 'Creator One', email: 'creator@example.com', provider_status: 'active', kol_id: null },
  readiness_status: 'ready_for_binding', block_reasons: [], exact_identity: { emails: ['creator@example.com'], profile_urls: ['https://instagram.com/creator'] },
  primary_email: 'creator@example.com', profile_urls: ['https://instagram.com/creator'], affiliate_snapshot: {}, referral_impact_count: 3,
  referral_provider_checksum: 'd'.repeat(64), applied_at: null,
}
const plan = {
  id: 21, status: 'prepared', plan_version: 1, plan_checksum: 'c'.repeat(64), eligible_kol_count: 12,
  ready_count: 7, already_bound_count: 2, no_affiliate_count: 1, blocked_count: 2,
  generated_at: '2026-09-04T01:10:00Z', applied_at: null, blocker_summary: { stale_affiliate_evidence: 1, multiple_affiliates: 1 }, items: [item],
}
const workspace = (enabled=false) => ({ activations:[activation], activation, plan,
  live_summary:{eligible_kol_count:12,ready_count:7,already_bound_count:2,no_affiliate_count:1,blocked_count:2},
  blocker_summary:{stale_affiliate_evidence:1,multiple_affiliates:1}, binding_enabled:enabled, environment:'local' })

describe('KOL Affiliate Binding Activation', () => {
  beforeEach(() => { routerGet.mockReset(); routerPost.mockReset(); vi.spyOn(window,'confirm').mockReturnValue(true) })

  it('renders the approved P4B safety hierarchy and locks apply by default', () => {
    const wrapper=mount(BindingActivation,{props:{workspace:workspace(false),gridPreferenceNamespace:'grid.kol.affiliate_binding_activation.v1',gridPreference:null}})
    expect(wrapper.text()).toContain('P4B · Exact Binding Activation')
    expect(wrapper.text()).toContain('Affiliate 精确绑定')
    expect(wrapper.text()).toContain('0 Provider write')
    expect(wrapper.text()).toContain('Creator One')
    expect(wrapper.text()).toContain('3 条历史 Referral')
    const apply=wrapper.findAll('button').find(b=>b.text().includes('确认执行精确绑定'))!
    expect(apply.attributes('disabled')).toBeDefined()
  })

  it('prepares an evidence-only plan from the exact P4 activation batch', async () => {
    const wrapper=mount(BindingActivation,{props:{workspace:workspace(false),gridPreferenceNamespace:'grid.kol.affiliate_binding_activation.v1',gridPreference:null}})
    const prepare=wrapper.findAll('button').find(b=>b.text().includes('生成 / 刷新绑定计划'))!
    await prepare.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/kol/affiliatewp/binding-activation/prepare',{activation_batch_id:11},expect.objectContaining({preserveScroll:true}))
  })

  it('confirms exact checksum and ready count only when the local binding gate is enabled', async () => {
    const wrapper=mount(BindingActivation,{props:{workspace:workspace(true),gridPreferenceNamespace:'grid.kol.affiliate_binding_activation.v1',gridPreference:null}})
    const apply=wrapper.findAll('button').find(b=>b.text().includes('确认执行精确绑定'))!
    expect(apply.attributes('disabled')).toBeUndefined()
    await apply.trigger('click')
    expect(window.confirm).toHaveBeenCalled()
    expect(routerPost).toHaveBeenCalledWith('/kol/affiliatewp/binding-activation/21/apply',{plan_checksum:'c'.repeat(64),ready_count:7},expect.objectContaining({preserveScroll:true}))
  })

  it('exposes account-synced columns and provider-preservation detail evidence', async () => {
    const wrapper=mount(BindingActivation,{props:{workspace:workspace(false),gridPreferenceNamespace:'grid.kol.affiliate_binding_activation.v1',gridPreference:null}})
    await wrapper.get('.settings-trigger').trigger('click')
    expect(wrapper.text()).toContain('Current Affiliate mirror')
    expect(wrapper.text()).toContain('Provider 事实保护')
    await wrapper.get('.detail-btn').trigger('click')
    expect(wrapper.text()).toContain('永久保护边界')
    expect(wrapper.text()).toContain('AffiliateWP / WordPress / WooCommerce')
  })
})
