import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import HistoricalImport from './HistoricalImport.vue'

const { routerGet, routerPost } = vi.hoisted(() => ({ routerGet: vi.fn(), routerPost: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: routerGet, post: routerPost },
}))
vi.mock('axios', () => ({ default: { post: vi.fn() } }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const batch = {
  id: 9, filename: '0902-网红订单.xlsx', checksum: 'a'.repeat(64), sheet: '工作表1', reconciliation_batch_id: 1,
  status: 'preview', total_rows: 1077, ready_count: 820, review_count: 185, skip_count: 72,
  rows: [{ id: 1, source_row_number: 6, source_order_no: 'KOL120204', kol: { id: 920, display_name: 'Creator', url: '/kol/library/920' }, carrier: '速达', tracking: '4PX3002296509905CN', tracking_raw: '4PX3002296509905CN', warehouse: 'Factory 国内仓', classification: 'ready' as const, reason: 'exact_tracking_and_kol_match' }],
  pagination: { current_page: 1, last_page: 22, per_page: 50, total: 1077 },
}


const controlCenter = {
  summary: { source_rows: 1077, evidences: 1065, historical_orders: 886, original_attempts: 765, rescue_orders: 121, rescue_tracking_legs: 244, permanent_review: 20, historical_attempts_total: 765, active_shipment_contamination: 0 },
  r5: { batch_id: null, status: 'not_previewed', total: 244, ready: 244, review: 0, applied: 0, manifest_sha256: 'c'.repeat(64), stored_manifest_sha256: null, fresh_sha_matches: true, global_apply_gate: false, can_apply: false, confirmation: null, warehouse: { exact: 243, unresolved: 1, master_missing: 0 }, unique_tracking: 244, review_reasons: [] },
  attempts: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0, filters: { q: '', decision: 'all', warehouse: 'all' } },
  permanent_review_rows: [{ id: 9, source_row_number: 87, source_order_no: 'KOL010501', reason: 'sku_unmatched_or_invalid', tracking_leg_count: 2 }],
  latest_backup: { id: 36, uuid: 'backup-36', state: 'ready', created_at: '2026-09-07T01:45:15Z', checksum: 'd'.repeat(64), size: 3501629 },
}

const reviewRescue = {
  rescueable_rows: 141, candidate_evidence_count: 277, existing_evidence_count: 0, pending_evidence_count: 277, manual_review_rows: 3,
  groups: [
    { reason: 'multiple_tracking_candidates', rows: 75, evidence: 153 },
    { reason: 'suspicious_compound_tracking', rows: 54, evidence: 108 },
    { reason: 'duplicate_source_order_no', rows: 9, evidence: 10 },
    { reason: 'replacement_or_transfer_ambiguity', rows: 2, evidence: 5 },
    { reason: 'carrier_requires_review', rows: 1, evidence: 1 },
  ],
}

describe('Historical shipment evidence workspace', () => {
  it('renders exact KOL links and makes the non-canonical impact explicit', () => {
    const wrapper = mount(HistoricalImport, { props: { batch, commitEnabled: false, reviewRescue, rescueCommitEnabled: false, controlCenter, canOperate: false, canViewOrders: false } })
    expect(wrapper.text()).toContain('历史发货证据导入')
    expect(wrapper.text()).toContain('Active Shipment 保持不变')
    expect(wrapper.text()).toContain('OFF · 默认')
    expect(wrapper.find('a[href="/kol/library/920"]').exists()).toBe(true)
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Historical Logistics 总账')
    expect(wrapper.text()).toContain('886')
    expect(wrapper.text()).toContain('244')
    expect(wrapper.text()).toContain('运营隔离 PASS')
    expect(wrapper.text()).toContain('Review 多段物流证据救回')
    expect(wrapper.text()).toContain('141 行')
    expect(wrapper.text()).toContain('277')
    expect(wrapper.text()).toContain('仍需人工')
    expect(wrapper.text()).toContain('3 行')
  })

  it('uses server pagination and only exposes import controls to operators', async () => {
    routerGet.mockClear()
    const wrapper = mount(HistoricalImport, { props: { batch, commitEnabled: true, reviewRescue, rescueCommitEnabled: true, controlCenter, canOperate: true, canViewOrders: true } })
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    const next = wrapper.findAll('button').find((button) => button.text().includes('下一页'))
    expect(next).toBeTruthy()
    await next!.trigger('click')
    expect(routerGet).toHaveBeenCalledWith('/logistics/historical-import', { page: 2 }, { preserveState: true, replace: true, preserveScroll: true })
    routerPost.mockClear()
    const freeze = wrapper.findAll('button').find((button) => button.text().includes('冻结 244 条 Preview'))
    expect(freeze).toBeTruthy()
    await freeze!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/historical-import/9/rescue-attempts/preview', {}, expect.objectContaining({ preserveScroll: true }))
  })

  it('requires an explicit owner confirmation payload before R5 apply', async () => {
    routerPost.mockClear()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const previewCenter = {
      ...controlCenter,
      r5: { ...controlCenter.r5, batch_id: 3, status: 'preview', can_apply: true, confirmation: 'APPLY 244' },
      attempts: { ...controlCenter.attempts, total: 1, data: [{ id: 1, order_id: 10, order_url: '/kol/orders/10', erp_order_id: '007erp-0101-k1', source_order_no: 'KOL010101', source_row_number: 24, attempt_number: 1, tracking_number: 'TRACK-1', warehouse_code: 'FACTORY', warehouse_raw: 'Factory 国内仓', warehouse_resolution: 'exact', raw_agent: 'Agent', decision: 'ready', reason: 'deterministic_rescue_historical_attempt' }] },
    }
    const wrapper = mount(HistoricalImport, { props: { batch, commitEnabled: false, reviewRescue, rescueCommitEnabled: false, controlCenter: previewCenter, canOperate: true, canViewOrders: true } })
    expect(wrapper.find('a[href="/kol/orders/10"]').exists()).toBe(true)
    const apply = wrapper.findAll('button').find((button) => button.text().includes('批准并写入 244 条'))
    expect(apply).toBeTruthy()
    await apply!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/historical-import/9/rescue-attempts/3/apply', {
      ready_manifest_sha256: 'c'.repeat(64), confirmation: 'APPLY 244',
    }, expect.objectContaining({ preserveScroll: true }))
  })

})
