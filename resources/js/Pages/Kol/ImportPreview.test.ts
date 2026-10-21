import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import ImportPreview from './ImportPreview.vue'

vi.mock('axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' } }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const batch = {
  id: 4,
  original_filename: '0902-网红订单.xlsx',
  checksum: 'a'.repeat(64),
  total_rows: 1077,
  distinct_source_identities: 970,
  import_preview: null,
}

describe('Kol ImportPreview', () => {
  it('renders P1 batch selector without canonical mutation actions', () => {
    const wrapper = mount(ImportPreview, { props: { batches: [batch] } })
    expect(wrapper.text()).toContain('数据来源（P1 身份对齐结果）')
    expect(wrapper.text()).toContain('0902-网红订单.xlsx')
    const actions = wrapper.findAll('button').map((button) => button.text())
    expect(actions).not.toContain('确认导入')
    expect(actions).not.toContain('创建 KOL')
    expect(actions).not.toContain('绑定 Affiliate')
  })

  it('shows Owner mapping as the next bounded step only to authorized managers', () => {
    const previewBatch = { ...batch, import_preview: {
      id: 3, reconciliation_batch_id: 4, source_filename: '0902-网红订单.xlsx', total_source_rows: 1077, candidate_count: 961, unresolved_source_rows: 12,
      summary: { ready_new_kol: 0, needs_review: 961, existing_kol: 0, conflict: 0 }, candidates: [],
    } }
    const allowed = mount(ImportPreview, { props: { batches: [previewBatch], canManageOwnerMapping: true } })
    const link = allowed.findAll('a').find((item) => item.text().includes('下一步：Owner 映射'))
    expect(link?.attributes('href')).toBe('/kol/owner-mapping?preview_batch_id=3')

    const denied = mount(ImportPreview, { props: { batches: [previewBatch], canManageOwnerMapping: false } })
    expect(denied.text()).not.toContain('下一步：Owner 映射')
  })

  it('generates and renders deterministic preview candidates', async () => {
    vi.mocked(axios.post).mockResolvedValueOnce({ data: { batch: {
      id: 1, reconciliation_batch_id: 4, source_filename: '0902-网红订单.xlsx', total_source_rows: 1077,
      candidate_count: 961, unresolved_source_rows: 12,
      summary: { ready_new_kol: 0, needs_review: 961, existing_kol: 0, conflict: 0 },
      candidates: [{ id: 1, source_row_count: 2, identity: { emails: ['creator@example.com'], profile_urls: ['https://instagram.com/Creator'], issues: ['owner_unresolved'] },
        proposed_kol: { display_name: 'Creator', country_code: 'US', shipping_city: 'Austin' }, social_accounts: [{ platform: 'instagram', handle: 'Creator' }],
        owner_evidence: { source_values: ['Emma'] }, owner_resolution_status: 'unresolved', matched_owner_id: null, history_summary: { source_order_count: 2, published_url_count: 1, sku_count: 2 },
        affiliate_account_ids: [123], existing_kol: null, status: 'needs_review', message: 'owner_unresolved' }],
    } } })
    const wrapper = mount(ImportPreview, { props: { batches: [batch] } })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(axios.post).toHaveBeenCalledWith('/kol/import-preview/generate', { reconciliation_batch_id: 4 })
    expect(wrapper.text()).toContain('P1 识别出 970 个精确身份 → P2 聚合为 961 个 KOL 身份候选')
    expect(wrapper.text()).toContain('12 条来源记录因缺少有效身份依据未参与聚合')
    expect(wrapper.text()).toContain('Creator')
    expect(wrapper.text()).toContain('可新建')
    expect(wrapper.text()).toContain('负责人未映射')
    expect(wrapper.text()).toContain('ERP 中无精确身份匹配')
    expect(wrapper.text()).toContain('每页')
    await wrapper.get('.details-button').trigger('click')
    expect(wrapper.text()).toContain('Owner 原始值')
    expect(wrapper.text()).toContain('Emma')
    expect(wrapper.text()).toContain('ERP Owner 映射')
    expect(wrapper.text()).toContain('未解析')
    expect(wrapper.text()).toContain('Affiliate Account #123')
    expect(wrapper.text()).toContain('负责人 “Emma” 尚未匹配到 ERP 用户，需要人工确认。')
    expect(wrapper.text()).not.toContain('owner_unresolved')
  })
})
