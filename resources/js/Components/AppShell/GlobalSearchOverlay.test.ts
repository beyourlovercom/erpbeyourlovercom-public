import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import GlobalSearchOverlay from './GlobalSearchOverlay.vue'

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({ router: { visit } }))

const wrappers: Array<ReturnType<typeof mount>> = []
const mountSearch = () => {
  const wrapper = mount(GlobalSearchOverlay, { attachTo: document.body })
  wrappers.push(wrapper)
  return wrapper
}

describe('GlobalSearchOverlay', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    visit.mockReset()
    vi.stubGlobal('fetch', vi.fn())
  })

  afterEach(() => {
    wrappers.splice(0).forEach(wrapper => wrapper.unmount())
    vi.useRealTimers()
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('opens from the header trigger and waits for two characters', async () => {
    const wrapper = mountSearch()
    await wrapper.get('[aria-label="全局搜索"]').trigger('click')
    expect(document.body.querySelector('[role="dialog"]')).not.toBeNull()

    const input = document.body.querySelector('[aria-label="搜索关键词"]') as HTMLInputElement
    input.value = 'V'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await vi.advanceTimersByTimeAsync(220)

    expect(fetch).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('再输入 1 个字符')
  })

  it('renders grouped results and opens the active result', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({
        query: 'VA617A',
        min_query_length: 2,
        groups: [{
          key: 'products',
          label: '产品',
          view_all_href: '/products/list?search=VA617A',
          items: [{ id: 'product:1', label: 'VA617A', subtitle: 'Main Product', href: '/products/1', meta: {} }],
        }],
      }),
    } as Response)

    const wrapper = mountSearch()
    await wrapper.get('[aria-label="全局搜索"]').trigger('click')
    const input = document.body.querySelector('[aria-label="搜索关键词"]') as HTMLInputElement
    input.value = 'VA617A'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await vi.advanceTimersByTimeAsync(220)
    await Promise.resolve()
    await Promise.resolve()

    expect(fetch).toHaveBeenCalledWith('/search/global?q=VA617A', expect.objectContaining({ headers: { Accept: 'application/json' } }))
    expect(document.body.textContent).toContain('Main Product')

    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    expect(visit).toHaveBeenCalledWith('/products/1')
  })

  it('opens with command-k and closes with escape', async () => {
    mountSearch()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true }))
    await Promise.resolve()
    expect(document.body.querySelector('[role="dialog"]')).not.toBeNull()

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await Promise.resolve()
    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
  })
})