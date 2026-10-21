import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import WarehouseShow from './WarehouseShow.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn(), patch: vi.fn(), get: vi.fn() },
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
  id: 7, slug: 'us-3sm', public_slug: 'us-3sm', code: 'US-SM', name: '美国3世迈仓',
  name_en: 'US SM', role_label: '海外履约仓', bill_import: null, bill_import_onboarding: null,
  is_fulfillment_warehouse: true, is_inventory_only_warehouse: false, country_code: 'US',
  address_line1: null, address_line2: null, city: null, state_region: null, postal_code: null, timezone: null,
  type: 'warehouse', ownership_type: 'external' as const, is_domestic: false, is_active: true, priority: 10,
  fulfillment_available_on: null, fulfillment_state: 'ready' as const, fulfillment_state_label: '可履约',
  replenishment_lead_time_days: null, replenishment_safety_stock_days: null, replenishment_target_cover_days: null,
  billing_markdown: null, service_regions: ['US'], last_inventory_sync_at: null, inventories: [],
}

const restockProjection = {
  rule: { history_days: 120, formula: 'MAX(过去4个月真实出货件数 - 当前可用库存, 0)', final_formula: '公式应备货数 + 人工补充备货数量' },
  summary: { products: 1, formula_units: 10, manual_units: 0, final_units: 10, products_to_restock: 1 },
  rows: [{
    product_id: 1456, inventory_id: 99, sku: 'VC549A', label_sku: 'C549A', label_sku_is_override: true,
    label_description: 'C549A Magnetic Wearable APP', name: 'VC549A 磁吸穿戴',
    final_restock_qty: 10, formula_restock_qty: 10, manual_restock_qty: 0, shipped_120d_qty: 12,
    quantity_available: 2, quantity_on_hand: 2, quantity_reserved: 0, units_per_carton: 5,
    logistics_weight_kg: '0.2500', weight_source: '物流重量', has_inventory_record: true,
  }],
}

const latestRestockPlan = {
  id: 1, reference: 'RP-USSM-20260920-000001', status: 'confirmed', snapshot_checksum: 'a'.repeat(64),
  summary: { final_units: 10, products_to_restock: 1, snapshot_products: 1, cartons_known: 2, products_missing_units_per_carton: 0, label_sku_overrides: 1 },
  confirmed_at: '2026-09-20T12:00:00+08:00',
}

describe('Warehouse Restock Plan Snapshot', () => {
  it('shows effective blue-label identity and confirmed snapshot in the restock workspace', async () => {
    const wrapper = mount(WarehouseShow, {
      props: {
        warehouse,
        clearanceProducts: [],
        clearanceCandidates: [],
        costCenter: { months: [] },
        billImportPreview: null,
        restockProjection,
        latestRestockPlan,
        canOperate: true,
        canManage: false,
      },
    })

    const inventoryTab = wrapper.findAll('button').find((button) => button.text().trim().startsWith('库存'))
    expect(inventoryTab).toBeTruthy()
    await inventoryTab!.trigger('click')

    const restockTab = wrapper.findAll('button').find((button) => button.text().trim().startsWith('备货'))
    expect(restockTab).toBeTruthy()
    await restockTab!.trigger('click')

    expect(wrapper.text()).toContain('确认备货计划')
    expect(wrapper.text()).toContain('蓝标 SKU')
    expect(wrapper.text()).toContain('C549A')
    expect(wrapper.text()).toContain('独立蓝标 SKU')
    expect(wrapper.text()).toContain('RP-USSM-20260920-000001')
    expect(wrapper.text()).toContain('装箱数据完整')
    expect(wrapper.text()).toContain('一键生成蓝标')

    const blueLabelButton = wrapper.findAll('button').find((button) => button.text().includes('一键生成蓝标'))
    expect(blueLabelButton).toBeTruthy()
    await blueLabelButton!.trigger('click')
    expect(router.post).toHaveBeenCalledWith(
      '/inventory/us-3sm/restock-plans/1/blue-label-jobs',
      {},
      expect.objectContaining({ preserveScroll: true }),
    )
  })

  it('shows the existing queued job instead of another generation button', async () => {
    const wrapper = mount(WarehouseShow, {
      props: {
        warehouse,
        clearanceProducts: [],
        clearanceCandidates: [],
        costCenter: { months: [] },
        billImportPreview: null,
        restockProjection,
        latestRestockPlan,
        latestRestockLabelJob: {
          id: 9,
          reference: 'BLJ-USSM-20260920-ABC1234567',
          document_kind: 'blue_labels',
          status: 'queued',
          status_label: '已排队',
          status_detail: '等待公司 Windows 执行节点领取',
          progress_percent: 10,
          received_artifact_files: 0,
          expected_artifact_files: 4,
          last_heartbeat_at: null,
          attempt_count: 0,
          next_attempt_at: null,
          is_retry_scheduled: false,
          requested_at: '2026-09-20T15:00:00+08:00',
          started_at: null,
          completed_at: null,
          failed_at: null,
          last_error_message: null,
          is_terminal: false,
          artifact_count: 0, has_pi: false, has_package: false,
        },
        canOperate: true,
        canManage: false,
      },
    })

    const inventoryTab = wrapper.findAll('button').find((button) => button.text().trim().startsWith('库存'))
    await inventoryTab!.trigger('click')
    const restockTab = wrapper.findAll('button').find((button) => button.text().trim().startsWith('备货'))
    await restockTab!.trigger('click')

    expect(wrapper.text()).toContain('蓝标生成 · 已排队')
    expect(wrapper.text()).toContain('BLJ-USSM-20260920-ABC1234567')
    expect(wrapper.text()).toContain('等待公司 Windows 执行节点领取')
    expect(wrapper.findAll('button').some((button) => button.text().includes('一键生成蓝标'))).toBe(false)
  })
})
