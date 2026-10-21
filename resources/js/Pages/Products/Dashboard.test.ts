import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Dashboard.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  usePage: () => ({ url: '/products', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
  router: { post: vi.fn() },
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

describe('Products/Dashboard', () => {
  it('shows real total products count', () => {
    const wrapper = mount(Dashboard, { props: { totalProducts: 116 } })
    expect(wrapper.text()).toContain('116')
  })

  it('shows unavailable state for 在售产品 (not misleading zero)', () => {
    const wrapper = mount(Dashboard, { props: { totalProducts: 0 } })
    expect(wrapper.text()).toContain('在售产品')
    expect(wrapper.text()).toContain('暂无数据')
    expect(wrapper.text()).not.toMatch(/在售产品.*\b0\b/)
  })

  it('shows unavailable state for 缺库存产品', () => {
    const wrapper = mount(Dashboard, { props: { totalProducts: 5 } })
    expect(wrapper.text()).toContain('缺库存产品')
    expect(wrapper.text()).toContain('暂无数据')
  })

  it('shows unavailable state for product sales chart', () => {
    const wrapper = mount(Dashboard, { props: { totalProducts: 5 } })
    expect(wrapper.text()).toContain('产品销售图表')
    expect(wrapper.text()).toContain('暂无数据')
  })
})
