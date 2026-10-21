import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import WarehouseShow from './WarehouseShow.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (initial: Record<string, any>) => ({
    ...initial,
    processing: false,
    errors: {},
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    reset: vi.fn(),
    defaults: vi.fn(),
    transform(fn: (data: any) => any) { Object.assign(this, fn(this)); return this },
  }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))
vi.mock('./components/InventorySkuCombobox.vue', () => ({
  default: { template: '<input class="inventory-sku-stub" />' },
}))

const warehouse = {
  id: 1, slug: 'us-la', public_slug: 'us-la', code: 'US-LA', name: '美西 MorePlus 仓', name_en: 'US West · MorePlus', role_label: '海外履约仓', bill_import: { provider: 'moreplus', label: 'MorePlus', extension: 'xlsx', format_label: 'XLSX', accept: '.xlsx', max_kb: 16384 }, is_fulfillment_warehouse: true, is_inventory_only_warehouse: false, country_code: 'US',
  address_line1: null, address_line2: null, city: null, state_region: null, postal_code: null, timezone: null,
  type: 'warehouse', ownership_type: 'owned' as const, is_domestic: false, is_active: true, priority: 10,
  fulfillment_available_on: null, fulfillment_state: 'ready' as const, fulfillment_state_label: '可履约',
  replenishment_lead_time_days: null, replenishment_safety_stock_days: null, replenishment_target_cover_days: null,
  billing_markdown: null, service_regions: ['US'], last_inventory_sync_at: null,
  inventories: [{
    id: 9, product: { id: 31, sku: 'CLR-A', name: 'Clearance A' }, quantity_on_hand: 10, quantity_reserved: 3,
    quantity_available: 7, stock_status: 'in_stock' as const, stock_status_label: '有货', inbound_at: null, stay_days: null,
    location: null, standard_qoh: null, expected_restock_qty: 0,
    replenishment: { policy_status: 'unconfigured' as const, policy_label: '补货策略未配置', shipped_90d_qty: 0, days_of_cover: null, recommended_restock_qty: null },
  }],
}

const clearanceProducts = [
  { id: 501, product: { id: 31, sku: 'CLR-A', name: 'Clearance A' }, priority: 1, quantity_on_hand: 10, quantity_reserved: 3, quantity_available: 7, status: 'available' as const, status_label: '可用' },
  { id: 502, product: { id: 32, sku: 'CLR-B', name: 'Clearance B' }, priority: 2, quantity_on_hand: 2, quantity_reserved: 2, quantity_available: 0, status: 'out_of_stock' as const, status_label: '缺货' },
]
const emptyCostCenter = { months: [] }

const clearanceCandidates = [
  { product_id: 31, sku: 'CLR-A', name: 'Clearance A', quantity_available: 7, already_clearance: true },
  { product_id: 33, sku: 'CLR-C', name: 'Clearance C', quantity_available: 5, already_clearance: false },
]

describe('Warehouse Clearance / Gift Pool V1', () => {
  it('renders current inventory and the inline clearance pool with live stock, priority and local candidates', () => {
    const wrapper = mount(WarehouseShow, { props: { warehouse, clearanceProducts, clearanceCandidates, billImportPreview:null,costCenter: emptyCostCenter, canOperate: true, canManage: false } })
    expect(wrapper.text()).toContain('目前仓库产品库存统计')
    expect(wrapper.text()).toContain('清仓 / Gift 库')
    expect(wrapper.findAll('button').some(button => button.text().includes('清仓 / Gift 库'))).toBe(false)
    expect(wrapper.text()).toContain('已在清仓')
    expect(wrapper.text()).toContain('加入清仓后自动成为本仓 Gift 候选')
    expect(wrapper.text()).toContain('CLR-A')
    expect(wrapper.text()).toContain('Clearance B')
    expect(wrapper.text()).toContain('可用')
    expect(wrapper.text()).toContain('缺货')
    expect(wrapper.find('input[placeholder="搜索本仓库存 SKU / 商品"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('CLR-C')
    expect(wrapper.text()).not.toContain('没有可加入的本仓库存商品')
    expect(wrapper.findAll('input[type="number"]').some(input => input.attributes('value') === '1')).toBe(true)
  })

  it('keeps the inline clearance pool read-only for inventory viewers without operate permission', () => {
    const wrapper = mount(WarehouseShow, { props: { warehouse, clearanceProducts, clearanceCandidates, billImportPreview:null,costCenter: emptyCostCenter, canOperate: false, canManage: false } })
    expect(wrapper.text()).toContain('目前仓库产品库存统计')
    expect(wrapper.text()).toContain('清仓 / Gift 库')
    expect(wrapper.find('input[placeholder="搜索本仓库存 SKU / 商品"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === '移出')).toBe(false)
    expect(wrapper.text()).toContain('CLR-A')
    expect(wrapper.text()).toContain('缺货')
  })
})
