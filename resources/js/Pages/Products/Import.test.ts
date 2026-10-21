import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Import from './Import.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: vi.fn() },
  usePage: () => ({ url: '/products/import', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

describe('Products/Import', () => {
  it('renders CSV upload form', () => {
    const wrapper = mount(Import)
    expect(wrapper.text()).toContain('导入 WooCommerce CSV')
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    expect(wrapper.find('button[type="submit"]').text()).toBe('预览 CSV')
  })

  it('shows link back to product list', () => {
    const wrapper = mount(Import)
    expect(wrapper.find('a[href="/products/list"]').exists()).toBe(true)
  })

  it('links to the server-backed fixed-name CSV template', () => {
    const wrapper = mount(Import)
    const link = wrapper.find('a[href="/products/import/template"]')
    expect(link.exists()).toBe(true)
    expect(link.text()).toBe('下载 CSV 模板')
    expect(link.attributes('download')).toBeUndefined()
  })

  it('does not show result section when no result prop', () => {
    const wrapper = mount(Import)
    expect(wrapper.text()).not.toContain('导入结果')
  })

  it('shows import result counts in Chinese when result provided', () => {
    const result = { totalRows: 6, candidates: 4, newCandidates: 3, updates: 1, unchanged: 0, active: 3, inactive: 1, skippedNoSkuVariations: 1, noSkuNonVariationReconciliation: 1, invalid: 0, duplicateSkuConflicts: 0, identityConflicts: 0, canConfirm: true, errors: [] }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('导入预览')
    expect(wrapper.text()).toContain('源文件行数：6 条')
    expect(wrapper.text()).toContain('候选产品：4 条')
    expect(wrapper.text()).toContain('新增：3 条')
    expect(wrapper.text()).toContain('更新：1 条')
    expect(wrapper.text()).toContain('无 SKU 变体（合计）：1 条')
    expect(wrapper.text()).toContain('无效：0 条')
  })

  it('shows error messages when import has errors', () => {
    const result = { invalid: 1, canConfirm: false, errors: ['Row 2: invalid or missing ID'] }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('Row 2: invalid or missing ID')
  })
})

describe('Products/Import — Parent column and variation mapping stats', () => {
  it('helper text mentions 10 columns', () => {
    const wrapper = mount(Import)
    expect(wrapper.text()).toContain('10 列')
  })

  it('helper text mentions Parent column', () => {
    const wrapper = mount(Import)
    expect(wrapper.text()).toContain('Parent')
  })

  it('helper text explains Parent accepts exact SKU or id: form', () => {
    const wrapper = mount(Import)
    expect(wrapper.text()).toContain('id:')
    expect(wrapper.text()).toContain('SKU')
  })

  it('shows resolved variation mappings count separately', () => {
    const result = {
      totalRows: 3, candidates: 1, newCandidates: 1, updates: 0, unchanged: 0,
      active: 1, inactive: 0, invalid: 0, duplicateSkuConflicts: 0, identityConflicts: 0,
      skippedNoSkuVariations: 2, resolvedVariationMappings: 1, unresolvedVariationMappings: 1,
      noSkuNonVariationReconciliation: 0, canConfirm: true, errors: [],
    }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('无 SKU 变体（合计）：2 条')
    expect(wrapper.text()).toContain('已映射父产品：1 条')
    expect(wrapper.text()).toContain('未解析父产品：1 条')
  })

  it('shows zero unresolved when all variations are mapped', () => {
    const result = {
      totalRows: 2, candidates: 1, newCandidates: 1, updates: 0, unchanged: 0,
      active: 1, inactive: 0, invalid: 0, duplicateSkuConflicts: 0, identityConflicts: 0,
      skippedNoSkuVariations: 1, resolvedVariationMappings: 1, unresolvedVariationMappings: 0,
      noSkuNonVariationReconciliation: 0, canConfirm: true, errors: [],
    }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('已映射父产品：1 条')
    expect(wrapper.text()).toContain('未解析父产品：0 条')
  })

  it('shows zero mapped when all variations are unresolved', () => {
    const result = {
      totalRows: 2, candidates: 0, newCandidates: 0, updates: 0, unchanged: 0,
      active: 0, inactive: 0, invalid: 0, duplicateSkuConflicts: 0, identityConflicts: 0,
      skippedNoSkuVariations: 1, resolvedVariationMappings: 0, unresolvedVariationMappings: 1,
      noSkuNonVariationReconciliation: 0, canConfirm: false, errors: [],
    }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('已映射父产品：0 条')
    expect(wrapper.text()).toContain('未解析父产品：1 条')
  })

  it('resolvedVariationMappings and unresolvedVariationMappings default to 0 when absent', () => {
    const result = {
      totalRows: 1, candidates: 1, newCandidates: 1, updates: 0, unchanged: 0,
      active: 1, inactive: 0, invalid: 0, duplicateSkuConflicts: 0, identityConflicts: 0,
      skippedNoSkuVariations: 0, noSkuNonVariationReconciliation: 0, canConfirm: true, errors: [],
    }
    const wrapper = mount(Import, { props: { result } })
    expect(wrapper.text()).toContain('已映射父产品：0 条')
    expect(wrapper.text()).toContain('未解析父产品：0 条')
  })
})
