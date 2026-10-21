import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Packaging from './Packaging.vue'

vi.mock('@inertiajs/vue3', async () => {
  const { reactive } = await import('vue')
  return {
    Head: { props: ['title'], template: '<title>{{ title }}</title>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (fields: Record<string, unknown>) => reactive({ ...fields, processing: false, errors: {}, put: vi.fn() }),
    router: { post: vi.fn(), delete: vi.fn() },
  }
})
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))
const presets = [{ key: 'boxed', label: '标准纸箱' }, { key: 'compressed', label: '压缩包装' }]
function render(presetKey: string | null = 'boxed', method = '标准纸箱') {
  return mount(Packaging, { props: {
    product: { id: 999999, sku: 'DEMO-BOX-01', name: '测试商品' },
    presets,
    packagingProfile: {
      id: 101, preset_key: presetKey, method, scenario: null, version: null, units_per_carton: 12,
      inherits_global_template: true, rendered_image: null, default_image: null, examples: [],
    },
  } })
}
describe('Products/Packaging (C6 visual parity)', () => {
  it('clears the inherited template name when entering custom mode', async () => {
    const view = render()
    const method = view.find('.meta-grid input')
    expect((method.element as HTMLInputElement).value).toBe('标准纸箱')
    expect(method.attributes('disabled')).toBeDefined()
    const button = view.findAll('button').find(b => b.text().includes('定制包装方案'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect((method.element as HTMLInputElement).value).toBe('')
    expect(method.attributes('disabled')).toBeUndefined()
    expect(button!.classes()).toContain('active')
  })
  it('preserves an existing custom method when custom is already selected', async () => {
    const view = render(null, '自定义气泡包装')
    const method = view.find('.meta-grid input')
    const button = view.findAll('button').find(b => b.text().includes('定制包装方案'))!
    await button.trigger('click')
    expect((method.element as HTMLInputElement).value).toBe('自定义气泡包装')
  })
  it('switches preset then custom without retaining a stale preset title', async () => {
    const view = render()
    const second = view.findAll('button').find(b => b.text().includes('压缩包装'))!
    await second.trigger('click')
    const method = view.find('.meta-grid input')
    expect((method.element as HTMLInputElement).value).toBe('压缩包装')
    await view.findAll('button').find(b => b.text().includes('定制包装方案'))!.trigger('click')
    expect((method.element as HTMLInputElement).value).toBe('')
  })
})
