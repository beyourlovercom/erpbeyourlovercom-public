import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SkuCombobox from './SkuCombobox.vue'

const mockFetch = vi.fn()

describe('SkuCombobox', () => {
  beforeEach(() => {
    mockFetch.mockReset()
    vi.stubGlobal('fetch', mockFetch)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('opens and loads default options when focused with an empty query', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => [{ id: 1, sku: 'A001', name: 'Product A' }],
    })
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })

    await wrapper.get('input').trigger('focus')
    await flushPromises()

    expect(mockFetch).toHaveBeenCalledWith('/products/sku-search?q=', expect.any(Object))
    expect(wrapper.find('.sku-combobox-dropdown').exists()).toBe(true)
    expect(wrapper.text()).toContain('A001')
    expect(wrapper.text()).toContain('Product A')
  })

  it('debounces typed searches and clears a previous product id', async () => {
    vi.useFakeTimers()
    mockFetch.mockResolvedValue({ ok: true, json: async () => [] })
    const wrapper = mount(SkuCombobox, { props: { modelValue: 'OLD-1', productId: 42 } })

    await wrapper.get('input').setValue('AB')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['AB'])
    expect(wrapper.emitted('update:productId')?.at(-1)).toEqual([null])

    await vi.advanceTimersByTimeAsync(180)
    await flushPromises()
    expect(mockFetch).toHaveBeenCalledWith('/products/sku-search?q=AB', expect.any(Object))
  })

  it('selects an option and emits canonical sku and product id', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => [{ id: 42, sku: 'SEL-001', name: 'Selected Product' }],
    })
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })

    await wrapper.get('input').trigger('focus')
    await flushPromises()
    await wrapper.get('.sku-combobox-option').trigger('mousedown')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['SEL-001'])
    expect(wrapper.emitted('update:productId')?.at(-1)).toEqual([42])
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('SEL-001')
    expect(wrapper.find('.sku-combobox-dropdown').exists()).toBe(false)
  })

  it('supports keyboard navigation and Enter selection', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => [
        { id: 1, sku: 'A001', name: 'Product A' },
        { id: 2, sku: 'A002', name: 'Product B' },
      ],
    })
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })
    const input = wrapper.get('input')

    await input.trigger('focus')
    await flushPromises()
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['A002'])
    expect(wrapper.emitted('update:productId')?.at(-1)).toEqual([2])
  })

  it('reports a permission denial instead of claiming products do not exist', async () => {
    mockFetch.mockResolvedValue({ ok: false, status: 403 })
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })

    await wrapper.get('input').trigger('focus')
    await flushPromises()

    expect(wrapper.text()).toContain('没有 SKU 查询权限')
    expect(wrapper.text()).not.toContain('暂无可选产品')
    expect(wrapper.find('.sku-combobox-option').exists()).toBe(false)
  })

  it('reports a network error instead of an empty product list', async () => {
    mockFetch.mockRejectedValue(new Error('offline'))
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })

    await wrapper.get('input').trigger('focus')
    await flushPromises()

    expect(wrapper.text()).toContain('SKU 查询暂时失败')
    expect(wrapper.text()).not.toContain('暂无可选产品')
  })

  it('denies silently malformed SKU lookup responses', async () => {
    mockFetch.mockResolvedValue({ ok: true, json: async () => ({ error: 'unauthorized' }) })
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })

    await wrapper.get('input').trigger('focus')
    await flushPromises()

    expect(wrapper.text()).toContain('SKU 查询返回了无效数据')
    expect(wrapper.find('.sku-combobox-option').exists()).toBe(false)
  })

  it('keeps an empty dropdown open and renders no selectable fake option', async () => {
    mockFetch.mockResolvedValue({ ok: true, json: async () => [] })
    const wrapper = mount(SkuCombobox, { props: { modelValue: '', productId: null } })

    await wrapper.get('input').trigger('focus')
    await flushPromises()

    expect(wrapper.find('.sku-combobox-dropdown').exists()).toBe(true)
    expect(wrapper.text()).toContain('暂无可选产品')
    expect(wrapper.find('.sku-combobox-option').exists()).toBe(false)
  })
})
