import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Edit from './Edit.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { patch: vi.fn(), delete: vi.fn() },
  usePage: () => ({ url: '/products/1/edit', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { name: 'AuthenticatedLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const wooProduct = {
  id: 1, woocommerce_product_id: 9001, source_type: 'woocommerce', product_type: 'simple',
  sku: 'TEST-SKU', gtin: null, name: 'Test Product', unit: null, is_active: true,
  weight_kg: null, weight_evidence_id: null, weight_evidence_source_type: null,
  sale_price: null, regular_price: '89.00',
  shipping_weight_kg: null, shipping_weight_evidence_id: null, shipping_weight_evidence_source_type: null,
  length_cm: null, width_cm: null, height_cm: null,
  country_of_origin: null, hs_code: null,
  standard_production_cost: null, standard_packaging_cost: null, standard_purchase_cost: null,
  cost_currency: null, cost_note: null, notes: null,
  external_references: [
    { provider: 'woocommerce', connection_key: 'beyourlover.com', reference: '9001', reference_type: 'product' },
  ],
}

const manualProduct = {
  id: 2, woocommerce_product_id: null, source_type: 'manual', product_type: 'simple',
  sku: 'MAN-001', gtin: null, name: 'Manual Product', unit: 'pcs', is_active: true,
  weight_kg: null, weight_evidence_id: null, weight_evidence_source_type: null,
  sale_price: null, regular_price: null,
  shipping_weight_kg: null, shipping_weight_evidence_id: null, shipping_weight_evidence_source_type: null,
  length_cm: null, width_cm: null, height_cm: null,
  country_of_origin: 'CN', hs_code: null,
  standard_production_cost: '12.5000', standard_packaging_cost: null, standard_purchase_cost: null,
  cost_currency: 'CNY', cost_note: null, notes: null,
  external_references: [],
}

const inactiveProduct = {
  ...manualProduct, id: 3, sku: 'INA-001', name: 'Inactive Product', is_active: false,
}

describe('Products/Edit V2', () => {
  it('renders page title and subtitle', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('编辑产品')
    expect(wrapper.text()).toContain('管理产品资料、物流属性与会计参考成本')
  })

  it('renders breadcrumb with 产品列表 label', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const layout = wrapper.findComponent({ name: 'AuthenticatedLayout' })
    const crumbs = layout.props('breadcrumbs') as Array<string | { label: string; href?: string }>
    const labels = crumbs.map(c => (typeof c === 'string' ? c : c.label))
    const hrefs = crumbs.map(c => (typeof c === 'string' ? undefined : c.href))
    expect(labels).toContain('BYL ERP')
    expect(labels).toContain('产品')
    expect(labels).toContain('产品列表')
    expect(hrefs).toContain('/products/list')
  })

  it('shows identity strip with SKU, source, compact external ref and status', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const strip = wrapper.find('.erp-identity-strip')
    expect(strip.exists()).toBe(true)
    expect(strip.text()).toContain('TEST-SKU')
    expect(strip.text()).toContain('WooCommerce')
    expect(strip.text()).toContain('beyourlover.com')
    expect(strip.text()).toContain('产品 9001')
    expect(strip.text()).toContain('启用')
  })

  it('shows 手动 source in identity strip for manual products', () => {
    const wrapper = mount(Edit, { props: { product: manualProduct } })
    const strip = wrapper.find('.erp-identity-strip')
    expect(strip.text()).toContain('手动')
    expect(strip.text()).toContain('MAN-001')
    expect(strip.text()).toContain('—')
  })

  it('shows inactive status chip for inactive product', () => {
    const wrapper = mount(Edit, { props: { product: inactiveProduct } })
    const chip = wrapper.find('.erp-status-chip.inactive')
    expect(chip.exists()).toBe(true)
    expect(chip.text()).toContain('停用')
  })

  it('identity strip source fields are not editable inputs', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const strip = wrapper.find('.erp-identity-strip')
    // No text inputs inside identity strip
    expect(strip.find('input').exists()).toBe(false)
  })

  it('shows 保存产品 primary submit button', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.find('button[type="submit"]').text()).toBe('保存产品')
  })

  it('shows 取消 secondary link back to product list', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const cancelLinks = wrapper.findAll('a[href="/products/list"]')
    expect(cancelLinks.length).toBeGreaterThan(0)
  })

  it('renders edit form pre-filled with product name', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const nameInput = wrapper.find('#edit-name')
    expect((nameInput.element as HTMLInputElement).value).toBe('Test Product')
  })

  it('pre-fills unit and country_of_origin for manual product', () => {
    const wrapper = mount(Edit, { props: { product: manualProduct } })
    const unitInput = wrapper.find('#edit-unit')
    const countryInput = wrapper.find('#edit-country')
    expect((unitInput.element as HTMLInputElement).value).toBe('pcs')
    expect((countryInput.element as HTMLInputElement).value).toBe('CN')
  })

  it('shows all card section headings', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('基本信息')
    expect(wrapper.text()).toContain('销售信息')
    expect(wrapper.text()).toContain('物流属性')
    expect(wrapper.text()).toContain('海关与产地')
    expect(wrapper.text()).toContain('成本参考')
    expect(wrapper.text()).toContain('内部备注')
  })

  it('shows accounting helper copy in 成本参考 card', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('Accounting')
    expect(wrapper.text()).toContain('历史会计成本')
  })

  it('renders status segmented control (not a native checkbox)', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    const statusButtons = wrapper.findAll('.erp-segmented-option')
    expect(statusButtons.length).toBeGreaterThanOrEqual(2)
  })

  it('active segmented option reflects product.is_active', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const selectedBtn = wrapper.find('.erp-segmented-option.selected')
    expect(selectedBtn.exists()).toBe(true)
    expect(selectedBtn.text()).toContain('启用')
  })

  it('shows 体积重量参考 read-only field labeled as reference value', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('体积重量参考')
    expect(wrapper.text()).toContain('参考/计算值')
  })

  it('keeps delete in the standardized page header and hides confirmation until requested', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const headerActions = wrapper.find('.page-header-actions')
    expect(headerActions.exists()).toBe(true)
    expect(headerActions.find('.erp-btn-danger').text()).toBe('删除产品')
    expect(headerActions.find('button[type="submit"]').text()).toBe('保存产品')
    expect(wrapper.find('.erp-danger-zone').exists()).toBe(false)
  })

  it('danger zone shows confirmation dialog on delete click', async () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const deleteBtn = wrapper.find('.erp-btn-danger')
    await deleteBtn.trigger('click')
    expect(wrapper.text()).toContain('确认删除')
    expect(wrapper.text()).toContain('取消')
  })

  it('cancel in confirmation hides the confirm dialog', async () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    await wrapper.find('.erp-btn-danger').trigger('click')
    expect(wrapper.text()).toContain('确认删除')
    const cancelBtn = wrapper.findAll('button').find(b => b.text().includes('取消') && b.attributes('type') !== 'submit')
    expect(cancelBtn).toBeDefined()
    await cancelBtn!.trigger('click')
    expect(wrapper.text()).toContain('删除产品')
    expect(wrapper.find('.erp-danger-zone').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('确认删除「')
  })

  it('uses erp-form-card class for card sections', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.findAll('.erp-form-card').length).toBeGreaterThanOrEqual(6)
  })
})

describe('Products/Edit — 外部引用 compact identity', () => {
  const base = {
    id: 10, woocommerce_product_id: 5000, source_type: 'woocommerce', product_type: 'variable',
    sku: 'EXT-001', gtin: null, name: 'External Ref Product', unit: null, is_active: true,
    weight_kg: null, weight_evidence_id: null, weight_evidence_source_type: null,
    sale_price: null, regular_price: null,
    shipping_weight_kg: null, shipping_weight_evidence_id: null, shipping_weight_evidence_source_type: null,
    length_cm: null, width_cm: null, height_cm: null, country_of_origin: null, hs_code: null,
    standard_production_cost: null, standard_packaging_cost: null, standard_purchase_cost: null,
    cost_currency: null, cost_note: null, notes: null,
  }

  const withRefs = {
    ...base,
    external_references: [
      { provider: 'woocommerce', connection_key: 'site-a.example.com', reference: '5000', reference_type: 'product' },
      { provider: 'woocommerce', connection_key: 'site-a.example.com', reference: '5001', reference_type: 'variation' },
      { provider: 'woocommerce', connection_key: 'site-b.example.com', reference: '5000', reference_type: 'product' },
    ],
  }
  const withNoRefs = { ...base, external_references: [] }

  it('merges external references into the identity strip', () => {
    const wrapper = mount(Edit, { props: { product: withRefs } })
    const strip = wrapper.find('.erp-identity-strip')
    const compact = strip.find('[data-testid="external-references-compact"]')
    expect(compact.exists()).toBe(true)
    expect(compact.text()).toContain('外部引用')
  })

  it('removes the standalone external-reference card and table', () => {
    const wrapper = mount(Edit, { props: { product: withRefs } })
    expect(wrapper.find('[data-testid="external-references-card"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="external-references-table"]').exists()).toBe(false)
  })

  it('groups same-connection product and variation refs into one compact line', () => {
    const wrapper = mount(Edit, { props: { product: withRefs } })
    const summary = wrapper.find('[data-testid="external-reference-summary"]')
    expect(summary.text()).toContain('site-a.example.com')
    expect(summary.text()).toContain('产品 5000')
    expect(summary.text()).toContain('变体 5001')
  })

  it('keeps separate connection keys visible in the compact summary', () => {
    const wrapper = mount(Edit, { props: { product: withRefs } })
    const summary = wrapper.find('[data-testid="external-reference-summary"]')
    expect(summary.text()).toContain('site-a.example.com')
    expect(summary.text()).toContain('site-b.example.com')
  })

  it('keeps full provider-scoped detail in the summary title', () => {
    const wrapper = mount(Edit, { props: { product: withRefs } })
    const summary = wrapper.find('[data-testid="external-reference-summary"]')
    const title = summary.attributes('title')
    expect(title).toContain('WooCommerce · site-a.example.com · 产品 5000')
    expect(title).toContain('WooCommerce · site-a.example.com · 变体 5001')
    expect(title).toContain('WooCommerce · site-b.example.com · 产品 5000')
  })

  it('shows a minimal dash when there are no external references', () => {
    const wrapper = mount(Edit, { props: { product: withNoRefs } })
    expect(wrapper.find('[data-testid="external-reference-summary"]').text()).toBe('—')
  })

  it('compact external reference field has no mutation controls', () => {
    const wrapper = mount(Edit, { props: { product: withRefs } })
    const compact = wrapper.find('[data-testid="external-references-compact"]')
    expect(compact.find('input').exists()).toBe(false)
    expect(compact.find('select').exists()).toBe(false)
    expect(compact.find('button').exists()).toBe(false)
  })

  it('keeps the compact external-reference field outside the edit form', () => {
    const wrapper = mount(Edit, { props: { product: withRefs } })
    expect(wrapper.find('form#product-edit-form').find('[data-testid="external-references-compact"]').exists()).toBe(false)
  })

  it('does not expose a separate legacy Woo ID identity cell', () => {
    const wrapper = mount(Edit, { props: { product: withRefs } })
    const strip = wrapper.find('.erp-identity-strip')
    expect(strip.text()).not.toContain('Woo ID')
  })
})
