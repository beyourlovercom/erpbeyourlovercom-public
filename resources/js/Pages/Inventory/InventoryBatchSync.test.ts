import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import Sync from './Sync.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn() },
  usePage: () => ({ url: '/inventory/sync', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

// Mock axios at module level
vi.mock('axios', () => ({
  default: { post: vi.fn(), get: vi.fn() },
}))
import axios from 'axios'
const mockAxios = axios as unknown as { post: ReturnType<typeof vi.fn>; get: ReturnType<typeof vi.fn> }

const warehouses = [
  { id: 1, slug: 'de', name: '德国仓', country_code: 'DE' },
  { id: 2, slug: 'us-la', name: '美东洛杉矶仓', country_code: 'US' },
]

const defaultProps = {
  warehouses,
  apiAvailable: false,
  apiStatusLabel: '尚未配置',
  recentBatches: [],
}

function mkBatch(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    warehouse_id: 1,
    warehouse_name: '德国仓',
    source_type: 'paste',
    sync_mode: 'partial',
    original_filename: null,
    stock_count_at: null,
    status: 'preview',
    preview_total_rows: 2,
    preview_unique_skus: 2,
    preview_sellable_qty: 150,
    preview_defective_qty: 1,
    preview_unmatched_skus: 0,
    preview_duplicate_skus: 0,
    preview_warning_rows: 0,
    preview_zeroed_skus: 0,
    preview_error_rows: 0,
    has_blockers: false,
    confirmed_at: null,
    aggregated_rows: [],
    full_snapshot_zero_rows: [],
    rows: [
      { id: 1, source_row_no: 2, sku: 'ABC123', stock_status: 'sellable', putaway_date: '2026-07-01', quantity: 100, product_id: 10, raw_product_name: null, raw_warehouse_name: null, raw_stock_status: '正品', raw_dimension: null, raw_total_volume: null, validation_status: 'ok', validation_message: null },
      { id: 2, source_row_no: 3, sku: 'DEF456', stock_status: 'defective', putaway_date: '2026-07-01', quantity: 1, product_id: 11, raw_product_name: null, raw_warehouse_name: null, raw_stock_status: '次品', raw_dimension: null, raw_total_volume: null, validation_status: 'ok', validation_message: null },
    ],
    ...overrides,
  }
}

describe('Inventory Batch Sync workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders source selection tabs', () => {
    const wrapper = mount(Sync, { props: defaultProps })
    expect(wrapper.text()).toContain('复制粘贴')
    expect(wrapper.text()).toContain('XLSX / CSV')
    expect(wrapper.text()).toContain('API')
  })

  it('renders warehouse selector with all warehouses', () => {
    const wrapper = mount(Sync, { props: defaultProps })
    const options = wrapper.findAll('option')
    expect(options.some(o => o.text().includes('德国仓'))).toBe(true)
    expect(options.some(o => o.text().includes('美东洛杉矶仓'))).toBe(true)
  })

  it('shows API disabled placeholder when API tab selected', async () => {
    const wrapper = mount(Sync, { props: defaultProps })
    const apiTab = wrapper.findAll('button').find(b => b.text().includes('API'))
    await apiTab!.trigger('click')
    expect(wrapper.text()).toContain('尚未配置')
    expect(wrapper.text()).toContain('API 连接器尚未配置')
  })

  it('keeps both import steps visible and disabled until preview prerequisites are met', () => {
    const wrapper = mount(Sync, { props: defaultProps })
    const preview = wrapper.findAll('button').find(b => b.text() === '1. 预览数据')
    const confirm = wrapper.findAll('button').find(b => b.text() === '2. 确认导入库存')
    expect(preview?.attributes('disabled')).toBeDefined()
    expect(confirm).toBeDefined()
    expect(confirm?.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('请先预览数据，确认无错误后再导入。')
  })

  it('shows preview stats after successful preview call', async () => {
    mockAxios.post.mockResolvedValueOnce({ data: mkBatch() })
    const wrapper = mount(Sync, { props: defaultProps })

    // Select warehouse
    const select = wrapper.find('select')
    await select.setValue('1')

    // Type paste text
    const textarea = wrapper.find('textarea')
    await textarea.setValue('SKU\tQty/数量\nABC123\t100\nDEF456\t1')

    // Click preview
    const previewBtn = wrapper.findAll('button').find(b => b.text() === '1. 预览数据')
    await previewBtn!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('150')   // sellable qty
    expect(wrapper.text()).toContain('1')     // defective qty
    expect(wrapper.text()).toContain('ABC123')
    expect(wrapper.text()).toContain('DEF456')
  })

  it('disables confirm button when batch has blockers', async () => {
    mockAxios.post.mockResolvedValueOnce({ data: mkBatch({ has_blockers: true, preview_error_rows: 1 }) })
    const wrapper = mount(Sync, { props: defaultProps })

    const select = wrapper.find('select')
    await select.setValue('1')
    const textarea = wrapper.find('textarea')
    await textarea.setValue('SKU\tQty/数量\nBAD\t5')
    const previewBtn = wrapper.findAll('button').find(b => b.text() === '1. 预览数据')
    await previewBtn!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('有错误')
    const confirmBtn = wrapper.findAll('button').find(b => b.text() === '2. 确认导入库存')
    expect(confirmBtn?.attributes('disabled')).toBeDefined()
  })

  it('enables confirm button and calls confirm endpoint when no blockers', async () => {
    mockAxios.post
      .mockResolvedValueOnce({ data: mkBatch() })
      .mockResolvedValueOnce({ data: mkBatch({ status: 'confirmed', confirmed_at: '2026-08-25T10:00:00Z' }) })

    const wrapper = mount(Sync, { props: defaultProps })
    const select = wrapper.find('select')
    await select.setValue('1')
    const textarea = wrapper.find('textarea')
    await textarea.setValue('SKU\tQty/数量\nABC123\t100')
    const previewBtn = wrapper.findAll('button').find(b => b.text() === '1. 预览数据')
    await previewBtn!.trigger('click')
    await flushPromises()

    const confirmBtn = wrapper.findAll('button').find(b => b.text() === '2. 确认导入库存')
    expect(confirmBtn?.attributes('disabled')).toBeUndefined()
    await confirmBtn!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('已确认')
    expect(mockAxios.post).toHaveBeenCalledTimes(2)
  })

  it('shows full sync warning when sync mode is full', async () => {
    mockAxios.post.mockResolvedValueOnce({ data: mkBatch({ sync_mode: 'full' }) })
    const wrapper = mount(Sync, { props: defaultProps })

    const select = wrapper.find('select')
    await select.setValue('1')
    // Set sync mode to full
    const selects = wrapper.findAll('select')
    await selects[1].setValue('full')
    const textarea = wrapper.find('textarea')
    await textarea.setValue('SKU\tQty/数量\nABC123\t100')
    const previewBtn = wrapper.findAll('button').find(b => b.text() === '1. 预览数据')
    await previewBtn!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('全量同步模式')
  })

  it('shows recent batches history table when recentBatches provided', () => {
    const props = {
      ...defaultProps,
      recentBatches: [{
        id: 99,
        warehouse_name: '德国仓',
        source_type: 'xlsx',
        sync_mode: 'partial',
        status: 'confirmed',
        preview_unique_skus: 17,
        preview_sellable_qty: 1791,
        confirmed_at: '2026-08-24T10:00:00Z',
        created_at: '2026-08-24T09:00:00Z',
      }],
    }
    const wrapper = mount(Sync, { props })
    expect(wrapper.text()).toContain('最近同步记录')
    expect(wrapper.text()).toContain('1791')
    expect(wrapper.text()).toContain('已确认')
  })

  it('loads a history batch into the same detail workspace', async () => {
    mockAxios.get.mockResolvedValueOnce({ data: mkBatch({ id: 99, status: 'confirmed' }) })
    const props = {
      ...defaultProps,
      recentBatches: [{
        id: 99, warehouse_name: '德国仓', source_type: 'xlsx', sync_mode: 'partial',
        status: 'confirmed', preview_unique_skus: 17, preview_sellable_qty: 1791,
        confirmed_at: '2026-08-24T10:00:00Z', created_at: '2026-08-24T09:00:00Z',
      }],
    }
    const wrapper = mount(Sync, { props })
    const detailButton = wrapper.findAll('button').find(b => b.text() === '查看')
    await detailButton!.trigger('click')
    await flushPromises()

    expect(mockAxios.get).toHaveBeenCalledWith('/inventory/sync/99')
    expect(wrapper.text()).toContain('预览结果')
    expect(wrapper.text()).toContain('ABC123')
  })

})
