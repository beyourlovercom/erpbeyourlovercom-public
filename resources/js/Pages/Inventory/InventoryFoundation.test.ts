import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Dashboard.vue'
import Products from './Products.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn() },
  usePage: () => ({ url: '/inventory', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

describe('Inventory foundation UI', () => {
  it('dashboard shows canonical inventory totals and warehouse links', () => {
    const wrapper = mount(Dashboard, { props: {
      canManage: true,
      summary: { warehouse_count: 7, warehouse_total_count: 7, fulfillment_warehouse_count: 6, inventory_only_warehouse_count: 1, warehouse_data_gap_count: 4, warehouse_confirmed_count: 2, sku_rows: 12, quantity_on_hand: 100, quantity_reserved: 15, quantity_available: 85, out_of_stock_rows: 2, low_stock_rows: 3, in_stock_rows: 7, stock_alert_rows: 5, warehouses: [
        { slug: 'de', public_slug: 'de', code: 'DE', name: '德国天运仓', name_en: 'Germany · Tianyun', country_code: 'DE', address: '', role_label: '海外履约仓', is_fulfillment_warehouse: true, is_inventory_only_warehouse: false, product_count: 3, quantity_on_hand: 20, is_active: true, inventory_state: 'confirmed', inventory_state_label: '已确认', fulfillment_state: 'ready', fulfillment_state_label: '可履约', last_inventory_at: '2026-09-01T08:00:00Z' },
      ] },
    } })
    expect(wrapper.text()).toContain('库存概览')
    expect(wrapper.text()).toContain('可用库存')
    expect(wrapper.text()).toContain('85')
    expect(wrapper.text()).toContain('库存紧张 SKU')
    expect(wrapper.text()).toContain('3')
    expect(wrapper.find('a[href="/inventory/de"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/inventory/add"]').exists()).toBe(true)
  })

  it('dashboard hides warehouse create action from view-only users', () => {
    const wrapper = mount(Dashboard, { props: {
      canManage: false,
      summary: { warehouse_count: 0, warehouse_total_count: 0, fulfillment_warehouse_count: 0, inventory_only_warehouse_count: 0, warehouse_data_gap_count: 0, warehouse_confirmed_count: 0, sku_rows: 0, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0, out_of_stock_rows: 0, low_stock_rows: 0, in_stock_rows: 0, stock_alert_rows: 0, warehouses: [] },
    } })
    expect(wrapper.find('a[href="/inventory/add"]').exists()).toBe(false)
  })

  it('products table renders cross-warehouse stock truth and empty search controls', () => {
    const wrapper = mount(Products, { props: {
      rows: { data: [{ id: 7, warehouse: { slug: 'us-moreplus', public_slug: 'us-la', name: '美西 MorePlus 仓' }, product: { id: 9, sku: 'SKU-007', name: 'Test Product' }, inbound_at: '2026-08-20', stay_days: 5, location: 'A-01', quantity_on_hand: 12, quantity_reserved: 2, quantity_available: 10, stock_status: 'in_stock', stock_status_label: '有货', standard_qoh: 8, expected_restock_qty: 4, replenishment: { policy_status: 'reorder', policy_label: '建议补货', shipped_90d_qty: 90, monthly_average: 30, daily_average: 1, days_of_cover: 10, safety_stock_qty: 3, reorder_point_qty: 8, recommended_restock_qty: 4 } }], links: [], current_page: 1, last_page: 1 },
      warehouses: [{ slug: 'us-moreplus', public_slug: 'us-la', name: '美西 MorePlus 仓' }],
      filters: {},
    } })
    expect(wrapper.text()).toContain('SKU-007')
    expect(wrapper.text()).toContain('10')
    expect(wrapper.text()).toContain('5 天')
    expect(wrapper.text()).toContain('有货')
    expect(wrapper.text()).toContain('90天发货')
    expect(wrapper.text()).toContain('建议补货 · 4')
    expect(wrapper.find('input[placeholder="搜索 SKU / 产品名称"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/inventory/us-la"]').exists()).toBe(true)
    expect((wrapper.find('select').element as HTMLSelectElement).options[1].value).toBe('us-la')
  })
})
