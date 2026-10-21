import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ImportActivation from './ImportActivation.vue'

const { routerGet, routerPost } = vi.hoisted(() => ({ routerGet: vi.fn(), routerPost: vi.fn() }))
vi.mock('axios', () => ({ default: { put: vi.fn() } }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  router: { get: routerGet, post: routerPost },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const preview = {
  id: 3, reconciliation_batch_id: 7, source_filename: '0902-网红订单.xlsx', source_checksum: 'a'.repeat(64),
  source_rows: 1077, candidate_count: 961, unresolved_source_rows: 12, created_at: '2026-09-03T10:00:00+00:00',
}

const readyItem = {
  id: 1, cluster_key: 'b'.repeat(64), display_name: 'creator', primary_email: 'creator@example.com',
  profile_urls: ['https://instagram.com/creator'], preview_status: 'needs_review', readiness_status: 'ready', block_reasons: [],
  effective_owner: { id: 9, name: 'ERP Emma', email: 'emma@example.test' },
  canonical_proposal: { display_name: 'creator', contact_email: 'creator@example.com', country_code: 'US', owner_id: 9, status: 'active', relationship_stage: 'prospect' },
  social_proposal: [{ platform: 'instagram', handle: 'creator', profile_url: 'https://instagram.com/creator' }],
  exact_identity: { emails: ['creator@example.com'], profile_urls: ['https://instagram.com/creator'], variations: {} },
  existing_guard: { status: 'none', kol_ids: [] },
  source_owner_evidence: { source_values: ['Emma'], resolution_status: 'mapped' },
  affiliate_evidence: { account_ids: [123] }, history_summary: { source_row_count: 2, source_order_count: 1 }, created_kol: null, applied_at: null,
}

const plan = {
  id: 8, status: 'prepared', plan_version: 1, plan_checksum: 'c'.repeat(64), candidate_count: 961,
  ready_count: 1, blocked_count: 960, existing_count: 0, skipped_count: 12, generated_at: '2026-09-03T10:20:00+00:00', applied_at: null,
  blocker_summary: { owner_unresolved: 918, owner_decision_required: 42 }, items: [readyItem],
}

const workspace = (enabled = false) => ({
  previews: [preview], preview, plan,
  live_summary: { candidate_count: 961, ready_count: 0, blocked_count: 961, existing_count: 0, skipped_count: 12 },
  blocker_summary: { owner_unresolved: 919, owner_decision_required: 42 },
  import_enabled: enabled, environment: 'local',
})

describe('KOL Import Activation', () => {
  beforeEach(() => {
    routerGet.mockReset()
    routerPost.mockReset()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('renders the approved high-risk plan UI and keeps apply disabled while environment is locked', () => {
    const wrapper = mount(ImportActivation, { props: { workspace: workspace(false), gridPreferenceNamespace: 'grid.kol.import_activation.v1', gridPreference: null } })
    expect(wrapper.text()).toContain('P4 · Canonical Import')
    expect(wrapper.text()).toContain('KOL 正式导入')
    expect(wrapper.text()).toContain('Affiliate、订单、合作、内容、佣金、付款都不会在 P4 被写入')
    expect(wrapper.text()).toContain('961')
    expect(wrapper.text()).toContain('12')
    expect(wrapper.text()).toContain('creator')
    expect(wrapper.text()).toContain('ERP Emma')
    expect(wrapper.text()).toContain('P4 仅保留证据，不绑定')
    const apply = wrapper.findAll('button').find((button) => button.text().includes('确认导入到 KOL Master'))!
    expect(apply.attributes('disabled')).toBeDefined()
  })

  it('prepares a plan without invoking apply', async () => {
    const wrapper = mount(ImportActivation, { props: { workspace: workspace(false), gridPreferenceNamespace: 'grid.kol.import_activation.v1', gridPreference: null } })
    const prepare = wrapper.findAll('button').find((button) => button.text().includes('生成 / 刷新导入计划'))!
    await prepare.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/kol/import-activation/prepare', { preview_batch_id: 3 }, expect.objectContaining({ preserveScroll: true }))
  })

  it('confirms exact plan checksum and ready count when local import gate is explicitly enabled', async () => {
    const wrapper = mount(ImportActivation, { props: { workspace: workspace(true), gridPreferenceNamespace: 'grid.kol.import_activation.v1', gridPreference: null } })
    const apply = wrapper.findAll('button').find((button) => button.text().includes('确认导入到 KOL Master'))!
    expect(apply.attributes('disabled')).toBeUndefined()
    await apply.trigger('click')
    expect(window.confirm).toHaveBeenCalled()
    expect(routerPost).toHaveBeenCalledWith('/kol/import-activation/8/apply', {
      plan_checksum: 'c'.repeat(64), ready_count: 1,
    }, expect.objectContaining({ preserveScroll: true }))
  })

  it('exposes account-synced column settings and canonical detail evidence', async () => {
    const wrapper = mount(ImportActivation, { props: { workspace: workspace(false), gridPreferenceNamespace: 'grid.kol.import_activation.v1', gridPreference: null } })
    await wrapper.get('.settings-trigger').trigger('click')
    expect(wrapper.text()).toContain('列设置')
    expect(wrapper.text()).toContain('ERP Owner')
    await wrapper.get('.detail-btn').trigger('click')
    expect(wrapper.text()).toContain('Canonical 写入快照')
    expect(wrapper.text()).toContain('精确 Email')
    expect(wrapper.text()).toContain('Affiliate：1 个候选 · #123')
  })
})
