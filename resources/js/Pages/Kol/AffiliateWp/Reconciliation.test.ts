import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Reconciliation from './Reconciliation.vue'

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }))

vi.mock('axios', () => ({ default: { post: postMock } }))
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' } }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const batch = {
  id: 4,
  source_type: 'csv',
  original_filename: 'tencent.csv',
  checksum: 'abc123',
  total_rows: 1,
  distinct_source_identities: 1,
  summary: { matched_kol_only: 0, ready_for_binding: 1, already_bound: 0, no_affiliate: 0, unresolved: 0, conflict: 0 },
  rows: [{ source_row_number: 2, identity: { email: 'creator@example.com' }, evidence: { contact_email: [9] }, kol: { id: 9, display_name: 'Creator' }, affiliate_account_id: 12, status: 'ready_for_binding', message: 'review' }],
}

describe('AffiliateWP reconciliation workspace', () => {
  beforeEach(() => postMock.mockReset())

  it('renders evidence summary and review rows', () => {
    const wrapper = mount(Reconciliation, { props: { connectionKey: 'test.example.com', canOperate: true, batches: [batch] } })
    expect(wrapper.text()).toContain('真实数据核对')
    expect(wrapper.text()).toContain('Batch #4 · tencent.csv')
    expect(wrapper.text()).toContain('1 行')
  })

  it('posts the selected file through the canonical preview action', async () => {
    postMock.mockResolvedValue({ data: { batch } })
    const wrapper = mount(Reconciliation, { props: { connectionKey: 'test.example.com', canOperate: true, batches: [] } })
    const input = wrapper.find('input[type="file"]')
    const file = new File(['email\ncreator@example.com'], 'rows.csv', { type: 'text/csv' })
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    const button = wrapper.findAll('button').find((item) => item.text() === '预览文件')!
    await button.trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/affiliatewp/reconciliation/preview', expect.any(FormData))
    expect(wrapper.text()).toContain('Creator (#9)')
  })
})
