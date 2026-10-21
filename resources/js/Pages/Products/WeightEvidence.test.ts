import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import WeightEvidence from './WeightEvidence.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  usePage: () => ({ url: '/products/weight-evidence', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
  router: { get: vi.fn(), post: vi.fn() },
  useForm: (initial: object) => ({
    ...initial,
    processing: false,
    errors: {},
    reset: vi.fn(),
    post: vi.fn(),
  }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /><slot name="header" /></div>' },
}))

vi.mock('../../Components/Products/SkuCombobox.vue', () => ({
  default: { props: ['search'], emits: ['update:search', 'select'], template: '<input />' },
}))

const defaultSummary = {
  total_products: 50,
  weight_kg_filled: 20,
  weight_kg_missing: 30,
  shipping_weight_kg_filled: 10,
  shipping_weight_kg_missing: 40,
  pending_evidence_count: 3,
}

const defaultEvidences = {
  data: [],
  current_page: 1,
  last_page: 1,
  total: 0,
  links: [],
}

describe('Products/WeightEvidence', () => {
  it('shows coverage summary stats', () => {
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: defaultSummary,
        canOperate: true,
        sourceTypes: ['manual_measurement', 'supplier_document'],
        weightKinds: ['product_weight', 'shipping_reference_weight'],
        evidences: defaultEvidences,
      },
    })
    expect(wrapper.text()).toContain('50')
    expect(wrapper.text()).toContain('20')
    expect(wrapper.text()).toContain('3')
  })

  it('shows pending evidence count badge', () => {
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: { ...defaultSummary, pending_evidence_count: 7 },
        canOperate: true,
        sourceTypes: [],
        weightKinds: [],
        evidences: defaultEvidences,
      },
    })
    expect(wrapper.text()).toContain('7')
  })

  it('shows evidence list tab by default', () => {
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: defaultSummary,
        canOperate: true,
        sourceTypes: [],
        weightKinds: [],
        evidences: defaultEvidences,
      },
    })
    expect(wrapper.text()).toContain('证据列表')
  })

  it('shows empty state when no evidences', () => {
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: defaultSummary,
        canOperate: false,
        sourceTypes: [],
        weightKinds: [],
        evidences: defaultEvidences,
      },
    })
    expect(wrapper.text()).toContain('暂无证据')
  })

  it('renders evidence rows including provenance marker', () => {
    const evidence = {
      id: 1,
      product_id: 10,
      product_sku: 'SKU-001',
      product_name: 'Test Product',
      weight_kind: 'product_weight',
      observed_weight_kg: '1.2500',
      raw_sku: 'SKU-001',
      source_type: 'manual_measurement',
      source_reference: null,
      source_note: null,
      observed_at: null,
      status: 'adopted',
      rejection_note: null,
      created_at: null,
      reviewed_at: null,
      is_current_provenance: true,
    }
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: defaultSummary,
        canOperate: true,
        sourceTypes: ['manual_measurement'],
        weightKinds: ['product_weight'],
        evidences: { ...defaultEvidences, data: [evidence], total: 1 },
      },
    })
    expect(wrapper.text()).toContain('SKU-001')
    expect(wrapper.text()).toContain('1.2500')
    expect(wrapper.text()).toContain('当前溯源')
  })

  it('shows adopt and reject buttons for pending evidence', () => {
    const evidence = {
      id: 2,
      product_id: 11,
      product_sku: 'SKU-002',
      product_name: 'Test',
      weight_kind: 'product_weight',
      observed_weight_kg: '0.5000',
      raw_sku: 'SKU-002',
      source_type: 'supplier_document',
      source_reference: null,
      source_note: null,
      observed_at: null,
      status: 'pending',
      rejection_note: null,
      created_at: null,
      reviewed_at: null,
      is_current_provenance: false,
    }
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: defaultSummary,
        canOperate: true,
        sourceTypes: ['supplier_document'],
        weightKinds: ['product_weight'],
        evidences: { ...defaultEvidences, data: [evidence], total: 1 },
      },
    })
    expect(wrapper.text()).toContain('采纳')
    expect(wrapper.text()).toContain('拒绝')
  })

  it('hides mutation buttons for view-only users', () => {
    const evidence = {
      id: 3,
      product_id: 12,
      product_sku: 'SKU-003',
      product_name: 'Test',
      weight_kind: 'shipping_reference_weight',
      observed_weight_kg: '2.0000',
      raw_sku: 'SKU-003',
      source_type: 'warehouse_measurement',
      source_reference: null,
      source_note: null,
      observed_at: null,
      status: 'pending',
      rejection_note: null,
      created_at: null,
      reviewed_at: null,
      is_current_provenance: false,
    }
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: defaultSummary,
        canOperate: false,
        sourceTypes: [],
        weightKinds: [],
        evidences: { ...defaultEvidences, data: [evidence], total: 1 },
      },
    })
    const buttonTexts = wrapper.findAll('button').map((b) => b.text().trim())
    expect(buttonTexts).not.toContain('采纳')
    expect(buttonTexts).not.toContain('拒绝')
    expect(buttonTexts).not.toContain('添加证据')
  })

  it('shows CSV import tab when canOperate is true', () => {
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: defaultSummary,
        canOperate: true,
        sourceTypes: [],
        weightKinds: [],
      },
    })
    expect(wrapper.text()).toContain('CSV 导入')
  })

  it('shows preview statistics when preview prop is provided', () => {
    const wrapper = mount(WeightEvidence, {
      props: {
        summary: defaultSummary,
        canOperate: true,
        sourceTypes: [],
        weightKinds: [],
        importMode: true,
        preview: {
          total_rows: 10,
          valid_rows: 7,
          unmatched_sku_rows: 2,
          invalid_weight_rows: 1,
          duplicate_rows: 0,
          would_replace_non_null: 3,
          would_fill_null: 4,
          evidence_only_rows: 7,
          errors: ['Row 5: SKU not found'],
        },
        previewToken: 'test-token-123',
      },
    })
    expect(wrapper.text()).toContain('10')
    expect(wrapper.text()).toContain('7')
    expect(wrapper.text()).toContain('SKU not found')
  })
})
