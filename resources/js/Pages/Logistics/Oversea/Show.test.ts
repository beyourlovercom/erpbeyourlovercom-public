import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { globalFeedback } from '../../../lib/globalFeedback'
import Show from './Show.vue'

const { routerPost, routerPatch, routerVisit } = vi.hoisted(() => ({
  routerPost: vi.fn(), routerPatch: vi.fn(), routerVisit: vi.fn(),
}))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: routerPost, patch: routerPatch, visit: routerVisit },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const warehouseOptions = [
  { key: 'us-moreplus', id: 1, code: 'US-LA', label: '美西 MorePlus 仓', carrier: 'USPS', url: '/logistics/oversea/us-moreplus' },
  { key: 'us-3sm', id: 2, code: 'US-SM', label: '美国3世迈仓', carrier: 'USPS', url: '/logistics/oversea/us-3sm' },
]
const rows = { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null }
const usSmPendingRows = { data: [{
  line: 1, order_id: 99, erp_order_id: '007-OVERSEA-USSM-99', order_url: '/orders/99', recipient_name: 'Recipient', country: 'US',
  warehouse_id: 2, warehouse_name: '美国3世迈仓', carrier: 'USPS', service: null, carrier_selection_source: 'automatic',
  tracking_number: null, progress: 'sending_pending', note: null, review_status: 'approved' as const, shipment_status: 'pending', items: [{ sku: 'REVIEW-SKU', quantity: 1 }],
}], current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 }
const viewCounts = { review: 0, pending: 0, shipped: 0, exceptions: 0, all: 0 }
const preview = {
  token: 'a'.repeat(64), apply_count: 1, noop_count: 0, blocked_count: 0,
  rows: [{
    row: 2, order_id: 7, order_number: 'C40761-007', tracking_number: '92144904245461009999', carrier: 'USPS',
    shipped_at: '2026-09-20T09:40:00+00:00', shipped_time_source: 'apply' as const,
    provider_order_reference: 'MP-1', provider_service: 'USPS Ground Advantage', provider_status: 'Pending',
    status: 'apply' as const, message: '',
  }],
}

const mountPage = (overrides: Record<string, unknown> = {}) => mount(Show, { props: {
  warehouseKey: 'us-moreplus', warehouse: warehouseOptions[0], warehouseOptions, view: 'pending', rows, viewCounts,
  canReview: true, canOperate: true, carrierOptions: [], trackingImportPreview: preview, ...overrides,
} })

describe('Oversea Dede file exchange workbench', () => {
  beforeEach(() => { routerPost.mockClear(); routerPatch.mockClear(); routerVisit.mockClear() })
  afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks() })

  it('reports Germany order defects, stays on page and lets operator return to edit', async () => {
    const deWarehouse = { key:'de-tianyun', id:3, code:'DE', label:'德国天运仓', carrier:'DHL', url:'/logistics/oversea/de-tianyun' }
    const fetchMock = vi.fn().mockResolvedValue({
      ok:true, status:200,
      json:async () => ({
        total_count:2, valid_count:1, issue_count:1, token:'abc',
        issues:[{ order_id:88, order_number:'007erp-0920-c40888', reason:'订单 007erp-0920-c40888 缺少电话' }],
      }),
    })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mountPage({ warehouseKey:'de-tianyun', warehouse:deWarehouse, trackingImportPreview:null })
    await wrapper.get('a[href="/logistics/oversea/de-tianyun/export"]').trigger('click')
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledWith(
      '/logistics/oversea/de-tianyun/export/preview',
      expect.objectContaining({ credentials:'same-origin', headers:expect.objectContaining({ Accept:'application/json' }) }),
    )
    expect(globalFeedback.value.state).toBe('BLOCKED')
    expect(wrapper.get('.export-review-panel').text()).toContain('007erp-0920-c40888 缺少电话')
    expect(wrapper.get('.export-review-panel').text()).toContain('1 笔符合仓库模板')
    expect(wrapper.find('a[href="/logistics/oversea/de-tianyun/export"]').exists()).toBe(true)
    const cancel = wrapper.findAll('.export-review-actions button').find(b => b.text().includes('返回修改'))
    await cancel!.trigger('click')
    expect(wrapper.find('.export-review-panel').exists()).toBe(false)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('downloads only reviewed valid rows plus an exception sheet after explicit consent', async () => {
    const blob = new Blob(['xlsx bytes'], { type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok:true, status:200, json:async () => ({
        total_count:2, valid_count:1, issue_count:1, token:'confirmed-abc',
        issues:[{ order_id:88, order_number:'C88-007', reason:'缺少电话' }],
      }) })
      .mockResolvedValueOnce({
        ok:true, status:200,
        headers:new Headers({
          'content-type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'content-disposition':'attachment; filename="007-us-moreplus-orders-261026.xlsx"',
        }),
        blob:async () => blob,
      })
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('URL', Object.assign(URL, {
      createObjectURL:vi.fn(() => 'blob:test-warehouse'), revokeObjectURL:vi.fn(),
    }))
    let filename = ''
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function(this:HTMLAnchorElement) {
      filename = this.download
    })
    const wrapper = mountPage()
    await wrapper.get('a[href="/logistics/oversea/us-moreplus/export"]').trigger('click')
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(wrapper.get('.export-review-panel').text()).toContain('1 笔异常')
    const confirm = wrapper.findAll('.export-review-actions button').find(b => b.text().includes('仍然下载'))
    await confirm!.trigger('click')
    await flushPromises()
    expect(fetchMock).toHaveBeenLastCalledWith(
      '/logistics/oversea/us-moreplus/export?confirm=1&token=confirmed-abc',
      expect.objectContaining({ credentials:'same-origin' }),
    )
    expect(filename).toBe('007-us-moreplus-orders-261026.xlsx')
    expect(globalFeedback.value.state).toBe('SUCCESS')
    expect(globalFeedback.value.message).toContain('待修复 1 笔')
  })

  it('downloads clean WMS spreadsheet without a confirmation dialog', async () => {
    const blob = new Blob(['xlsx bytes'], { type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
    const fetchMock = vi.fn().mockResolvedValueOnce({
      ok:true, status:200, json:async () => ({ total_count:1, valid_count:1, issue_count:0, token:'clean', issues:[] }),
    }).mockResolvedValueOnce({
      ok:true, status:200,
      headers:new Headers({
        'content-type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'content-disposition':'attachment; filename="007-us-moreplus-orders-261026.xlsx"',
      }),
      blob:async () => blob,
    })
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('URL', Object.assign(URL, {
      createObjectURL:vi.fn(() => 'blob:test-warehouse'), revokeObjectURL:vi.fn(),
    }))
    let filename = ''
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function(this: HTMLAnchorElement) {
      filename = this.download
    })
    const wrapper = mountPage()
    await wrapper.get('a[href="/logistics/oversea/us-moreplus/export"]').trigger('click')
    await flushPromises()
    expect(filename).toBe('007-us-moreplus-orders-261026.xlsx')
    expect(wrapper.find('.export-review-panel').exists()).toBe(false)
    expect(globalFeedback.value.state).toBe('SUCCESS')
    expect(globalFeedback.value.message).toContain(filename)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('exposes the real XLSX round-trip flow and provider evidence', () => {
    const wrapper = mountPage()
    expect(wrapper.find('a[href="/logistics/oversea/us-moreplus/export"]').text()).toContain('下载仓库下单表')
    expect(wrapper.find('input[type="file"]').attributes('accept')).toBe('.xlsx,.csv,.txt')
    expect(wrapper.text()).toContain('上传物流回传表')
    expect(wrapper.text()).toContain('上传仓库 / WMS')
    expect(wrapper.text()).toContain('USPS Ground Advantage')
    expect(wrapper.text()).toContain('Pending')
    expect(wrapper.text()).toContain('ERP确认时间')
    expect(wrapper.text()).toContain('确认导入并标记已发货')
    expect(wrapper.text()).toContain('不会阻止发货')
  })

  it('keeps 3SM file return disabled and exposes manual confirm after tracking entry', async () => {
    const wrapper = mountPage({ warehouseKey: 'us-3sm', warehouse: warehouseOptions[1], trackingImportPreview: null, rows: usSmPendingRows })
    expect(wrapper.text()).not.toContain('上传物流回传表')
    expect(wrapper.text()).toContain('世迈没有 Tracking 回传模板')
    expect(wrapper.text()).toContain('确认发货')
    expect(wrapper.find('a[href="/logistics/oversea/us-3sm/export"]').exists()).toBe(true)

    const input = wrapper.find('input.tracking-input')
    await input.setValue('9400111899223856928599')
    const button = wrapper.findAll('button').find(button => button.text() === '确认发货')
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith(
      '/logistics/oversea/us-3sm/orders/99/manual-ship',
      { tracking_number: '9400111899223856928599' },
      { preserveScroll: true },
    )
  })

  it('blocks 3SM manual confirm in the browser until tracking exists', async () => {
    const wrapper = mountPage({ warehouseKey: 'us-3sm', warehouse: warehouseOptions[1], trackingImportPreview: null, rows: usSmPendingRows })
    const button = wrapper.findAll('button').find(button => button.text() === '确认发货')
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(routerPost).not.toHaveBeenCalledWith(
      '/logistics/oversea/us-3sm/orders/99/manual-ship',
      expect.anything(),
      expect.anything(),
    )
    expect(wrapper.text()).toContain('请先填写真实 Tracking')
  })

  it('allows unified review actions from all overseas warehouses', async () => {
    const reviewRows = { data: [{
      line: 1, order_id: 77, erp_order_id: '007-OVERSEA-ALL-77', order_url: '/orders/77', recipient_name: 'Recipient', country: 'US',
      warehouse_id: 1, warehouse_name: '美西 MorePlus 仓', carrier: 'USPS', service: null, carrier_selection_source: 'automatic',
      tracking_number: null, progress: 'pending_review', note: null, review_status: 'pending' as const, shipment_status: 'pending', items: [{ sku: 'SKU-77', quantity: 1 }],
    }], current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 }
    const wrapper = mountPage({ warehouseKey: 'all', warehouse: null, view: 'review', rows: reviewRows, trackingImportPreview: null, canReview: true, canOperate: false })
    expect(wrapper.text()).toContain('通过')
    expect(wrapper.text()).toContain('驳回')
    expect(wrapper.find('select.cell-select.carrier').attributes('disabled')).toBeDefined()
    expect(wrapper.find('select.cell-select.service').attributes('disabled')).toBeDefined()
    expect(wrapper.find('input.tracking-input').attributes('disabled')).toBeDefined()
    const approve = wrapper.findAll('button').find(button => button.text() === '通过')
    await approve!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/oversea/orders/77/approve', { note: null }, { preserveScroll: true, preserveState: true })
  })

  it('uses canonical compact pagination and per-page / quick-jump controls', async () => {
    const wrapper = mountPage({
      rows: { data: [], current_page: 18, last_page: 36, per_page: 50, total: 1800, from: 851, to: 900 },
      trackingImportPreview: null,
    })
    expect(wrapper.text()).toContain('共 1800 条')
    expect(wrapper.text()).toContain('第 18 / 36 页')
    expect(wrapper.text()).toContain('851–900 / 1800')
    expect(wrapper.text()).toContain('首页')
    expect(wrapper.text()).toContain('末页')
    expect(wrapper.findAll('.page-ellipsis').length).toBeGreaterThan(0)
    expect(wrapper.findAll('.pagination button').length).toBeLessThan(15)
    const pageSize = wrapper.find('.page-size select')
    expect(pageSize.findAll('option').map(option => option.text())).toEqual(['20','50','100'])
    await pageSize.setValue('100')
    expect(routerVisit).toHaveBeenCalledWith('/logistics/oversea/us-moreplus?view=pending&page=1&per_page=100', { preserveScroll: true, preserveState: true })

    const jump = wrapper.find('input[aria-label="跳转页码"]')
    await jump.setValue('30')
    await jump.trigger('keyup.enter')
    expect(routerVisit).toHaveBeenCalledWith('/logistics/oversea/us-moreplus?view=pending&page=30&per_page=50', { preserveScroll: true, preserveState: true })
  })

  it('keeps the review decision column sticky and exposes canonical column settings', async () => {
    const wrapper = mountPage({ trackingImportPreview: null })
    expect(wrapper.find('th.sticky-decision').text()).toBe('审核')
    const trigger = wrapper.find('button[aria-label="列设置"]')
    expect(trigger.exists()).toBe(true)
    await trigger.trigger('click')
    expect(wrapper.text()).toContain('列设置')
    expect(wrapper.text()).toContain('订单号')
    expect(wrapper.text()).toContain('Stock 仓库')
  })

  it('applies the frozen preview token through the selected warehouse route', async () => {
    const wrapper = mountPage()
    const button = wrapper.findAll('button').find(button => button.text().includes('确认导入并标记已发货'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/oversea/us-moreplus/tracking-import/apply', { token: 'a'.repeat(64) }, { preserveScroll: true })
  })
})
