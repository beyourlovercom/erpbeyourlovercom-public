import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import OwnerMapping from './OwnerMapping.vue'

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }))
vi.mock('axios', () => ({ default: { post: vi.fn(), delete: vi.fn() } }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  router: { get: routerGet },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const users = [
  { id: 2, name: 'UAT Admin', email: 'admin@local.test' },
  { id: 3, name: 'UAT Owner/Admin', email: 'owner@example.test' },
]

const candidate = {
  id: 12,
  cluster_key: 'a'.repeat(64),
  display_name: 'Creator',
  primary_email: 'creator@example.com',
  social_accounts: [{ platform: 'instagram', handle: 'creator' }],
  source_row_count: 3,
  source_owner_values: ['Emma', 'Young', 'Summer'],
  source_mappings: [
    { source_owner: 'Emma', mapping_id: null, user: null },
    { source_owner: 'Young', mapping_id: null, user: null },
    { source_owner: 'Summer', mapping_id: null, user: null },
  ],
  resolution_status: 'needs_decision',
  resolved: false,
  effective_owner: null,
  decision: null,
  affiliate_account_ids: [870],
}

const workspace = {
  previews: [{ id: 3, reconciliation_batch_id: 7, source_filename: '0902-网红订单.full31-replay.csv', candidate_count: 961, created_at: '2026-09-02T08:14:06+00:00' }],
  preview: { id: 3, reconciliation_batch_id: 7, source_filename: '0902-网红订单.full31-replay.csv', candidate_count: 961, unresolved_source_rows: 12, created_at: '2026-09-02T08:14:06+00:00' },
  summary: { total_candidates: 961, resolved_candidates: 0, unresolved_candidates: 961, multi_owner_candidates: 42, decision_required: 42, source_owner_count: 7 },
  source_owners: [
    { key: 'young', value: 'Young', single_candidate_count: 237, mention_candidate_count: 265, mapping: null },
    { key: 'emma', value: 'Emma', single_candidate_count: 199, mention_candidate_count: 222, mapping: null },
    { key: 'hiko', value: 'Hiko', single_candidate_count: 195, mention_candidate_count: 198, mapping: null },
    { key: 'summer', value: 'Summer', single_candidate_count: 128, mention_candidate_count: 144, mapping: null },
    { key: 'zhixi', value: 'Zhixi', single_candidate_count: 88, mention_candidate_count: 100, mapping: null },
    { key: 'aries', value: 'Aries', single_candidate_count: 71, mention_candidate_count: 78, mapping: null },
    { key: 'kelsey', value: 'Kelsey', single_candidate_count: 1, mention_candidate_count: 1, mapping: null },
  ],
  multi_candidates: [candidate],
  eligible_users: users,
}

describe('KOL Owner Mapping', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset()
    vi.mocked(axios.delete).mockReset()
    routerGet.mockReset()
  })

  it('renders the real-source mapping baseline and permanent safety boundary', () => {
    const wrapper = mount(OwnerMapping, { props: { workspace } })
    expect(wrapper.text()).toContain('P3A · Owner Mapping Closure')
    expect(wrapper.text()).toContain('仅保存 Owner 映射与候选裁决')
    expect(wrapper.text()).toContain('不创建 KOL、不绑定 Affiliate、不写 Provider')
    expect(wrapper.text()).toContain('961')
    expect(wrapper.text()).toContain('42')
    expect(wrapper.text()).toContain('Young')
    expect(wrapper.text()).toContain('237')
    expect(wrapper.text()).toContain('265')
    expect(wrapper.text()).toContain('Creator')
    expect(wrapper.text()).toContain('Emma → 未映射')
    expect(wrapper.text()).toContain('需人工裁决')
    expect(wrapper.findAll('button').map((button) => button.text())).not.toContain('确认导入')
  })

  it('saves a source owner mapping through the bounded mapping route', async () => {
    const nextWorkspace: any = structuredClone(workspace)
    nextWorkspace.summary.resolved_candidates = 199
    nextWorkspace.summary.unresolved_candidates = 762
    nextWorkspace.source_owners[1].mapping = { id: 1, mapped_user_id: 3, user: users[1], status: 'mapped' }
    vi.mocked(axios.post).mockResolvedValueOnce({ data: { workspace: nextWorkspace } })

    const wrapper = mount(OwnerMapping, { props: { workspace } })
    const emmaRow = wrapper.findAll('tbody tr').find((row) => row.text().includes('Emma'))!
    await emmaRow.get('select').setValue('3')
    await emmaRow.findAll('button')[0].trigger('click')
    await flushPromises()

    expect(axios.post).toHaveBeenCalledWith('/kol/owner-mapping/source', {
      preview_batch_id: 3,
      source_owner_value: 'Emma',
      mapped_user_id: 3,
    })
    expect(wrapper.text()).toContain('199')
    expect(wrapper.text()).toContain('已映射')
  })

  it('saves an explicit multi-owner decision without exposing canonical import actions', async () => {
    const nextWorkspace: any = structuredClone(workspace)
    nextWorkspace.summary.resolved_candidates = 1
    nextWorkspace.summary.unresolved_candidates = 960
    nextWorkspace.summary.decision_required = 41
    nextWorkspace.multi_candidates[0].resolution_status = 'decided'
    nextWorkspace.multi_candidates[0].resolved = true
    nextWorkspace.multi_candidates[0].effective_owner = users[1]
    nextWorkspace.multi_candidates[0].decision = { id: 9, mapped_user_id: 3, user: users[1], reason: 'Current owner confirmed', snapshot_matches: true }
    vi.mocked(axios.post).mockResolvedValueOnce({ data: { workspace: nextWorkspace } })

    const wrapper = mount(OwnerMapping, { props: { workspace } })
    const candidateRow = wrapper.find('.candidate-table tbody tr')
    await candidateRow.findAll('select')[0].setValue('3')
    await candidateRow.get('input').setValue('Current owner confirmed')
    await candidateRow.findAll('button')[0].trigger('click')
    await flushPromises()

    expect(axios.post).toHaveBeenCalledWith('/kol/owner-mapping/decision', {
      preview_batch_id: 3,
      cluster_key: 'a'.repeat(64),
      mapped_user_id: 3,
      reason: 'Current owner confirmed',
    })
    await wrapper.get('.filter-select').setValue('all')
    expect(wrapper.text()).toContain('已人工裁决')
    expect(wrapper.text()).toContain('UAT Owner/Admin')
    const actions = wrapper.findAll('button').map((button) => button.text())
    expect(actions).not.toContain('创建 KOL')
    expect(actions).not.toContain('绑定 Affiliate')
    expect(actions).not.toContain('确认导入')
  })
})
