import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

const { get, patch } = vi.hoisted(() => ({ get: vi.fn(), patch: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get, patch },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('../../../Components/Logistics/StatusBar.vue', () => ({ default: { template: '<div />' } }))

const summary = {
  shipment_count: 51, chargeable_weight_kg: 4.125, chargeable_weight_coverage: 1, costs_by_currency: [{ currency: 'USD', total: 33.75, average: 33.75, shipments: 1 }],
  this_month_count: 1, delivery_status_coverage: 0, in_transit_count: 0, delivered_count: 0, exception_count: 0, last_shipped_at: '2026-09-15T00:00:00Z',
}
const carrierMaster = {
  id: 2, name: 'DHL', name_en: 'DHL', name_zh: '敦豪', display_name: 'DHL / 敦豪', is_active: true, default_service_id: 11,
  services: [
    { id: 10, service_key: 'paket_domestic', service_value: 'DHL PAKET DOMESTIC', name_en: 'DHL Paket Domestic', name_zh: '德国境内包裹', display_name: 'DHL Paket Domestic / 德国境内包裹', is_active: true, sort_order: 10 },
    { id: 11, service_key: 'paket_international', service_value: 'DHL PAKET International', name_en: 'DHL Paket International', name_zh: '国际包裹', display_name: 'DHL Paket International / 国际包裹', is_active: true, sort_order: 20 },
  ],
}
const row = {
  id: 1, shipped_at: '2026-09-15T00:00:00Z', erp_order_id: '007-0915-c41001', order_kind: 'customer', order_url: '/customers/orders/1', warehouse_name: '美东纽约', destination_country: 'US', carrier: 'DHL', service: 'Express', tracking_number: 'DHL-1',
  items: [{ sku: 'SKU-1', product_summary: 'Product', product_url: '/products/1', quantity: 2 }], chargeable_weight_kg: 4.125, actual_cost: 33.75, currency: 'USD', shipment_status: 'shipped', delivery_status: null, handler_name: 'Dede', recipient_name: 'Receiver', billing_document_number: 'BILL-1', provider_reference: 'REF-1', data_source: 'carrier_bill_import:tianyun', billed_at: null, raw_evidence: { source: 'bill' },
}

describe('Carrier detail ledger', () => {
  beforeEach(() => { get.mockReset(); patch.mockReset() })

  it('renders only the canonical ERP order identity and does not infer delivery state', () => {
    const wrapper = mount(Show, { props: { carrier: 'DHL', carrierDisplayName: 'DHL / 敦豪', carrierMaster, canEditCarrier: true, summary, shipments: { data: [row], current_page: 1, last_page: 2, total: 51 } } })
    expect(wrapper.text()).toContain('007-0915-c41001')
    expect(wrapper.text()).toContain('暂无真实签收状态证据')
    expect(wrapper.text()).toContain('USD 33.75')
    expect(wrapper.text()).toContain('BILL-1')
    expect(wrapper.text()).not.toContain('ORDER-DHL-1')
    expect(wrapper.text()).not.toContain('ERP-DHL-1')
  })

  it('opens the approved carrier master editor with bilingual service catalog', async () => {
    const wrapper = mount(Show, { props: { carrier: 'DHL', carrierDisplayName: 'DHL / 敦豪', carrierMaster, canEditCarrier: true, summary, shipments: { data: [row], current_page: 1, last_page: 2, total: 51 } } })
    await wrapper.findAll('button').find(button => button.text() === '编辑承运商')!.trigger('click')
    expect(wrapper.get('[role="dialog"][aria-label="编辑承运商"]').text()).toContain('默认规则说明')
    expect(wrapper.text()).toContain('运输 / 服务类型')
    const rows = wrapper.findAll('.service-editor-table tbody tr')
    const internationalInputs = rows[1].findAll('input')
    expect((internationalInputs[1].element as HTMLInputElement).value).toBe('国际包裹')
    expect((internationalInputs[2].element as HTMLInputElement).value).toBe('DHL Paket International')
    expect(wrapper.text()).toContain('历史 Shipment 原始 service 文本保留不改写')
  })

  it('paginates without mutating business data', async () => {
    const wrapper = mount(Show, { props: { carrier: 'DHL', carrierDisplayName: 'DHL / 敦豪', carrierMaster, canEditCarrier: true, summary, shipments: { data: [row], current_page: 1, last_page: 2, total: 51 } } })
    await wrapper.findAll('button').find((button) => button.text() === '下一页')?.trigger('click')
    expect(get).toHaveBeenCalledWith('/logistics/carriers/DHL', { page: 2 }, { preserveState: true, replace: true })
  })
})
