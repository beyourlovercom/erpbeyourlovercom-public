import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Create from './Create.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: vi.fn() },
  usePage: () => ({ url: '/products/create', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { name: 'AuthenticatedLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

describe('Products/Create V2', () => {
  it('renders page title and subtitle', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('新增产品')
    expect(wrapper.text()).toContain('管理产品资料、物流属性与会计参考成本')
  })

  it('renders breadcrumb with correct labels', () => {
    const wrapper = mount(Create)
    const layout = wrapper.findComponent({ name: 'AuthenticatedLayout' })
    const crumbs = layout.props('breadcrumbs') as Array<string | { label: string; href?: string }>
    const labels = crumbs.map(c => (typeof c === 'string' ? c : c.label))
    const hrefs = crumbs.map(c => (typeof c === 'string' ? undefined : c.href))
    expect(labels).toContain('BYL ERP')
    expect(labels).toContain('产品')
    expect(labels).toContain('产品列表')
    expect(hrefs).toContain('/products/list')
    expect(labels).toContain('新增产品')
  })

  it('shows 创建产品 primary submit button', () => {
    const wrapper = mount(Create)
    expect(wrapper.find('button[type="submit"]').text()).toBe('创建产品')
  })

  it('shows 取消 secondary link back to product list', () => {
    const wrapper = mount(Create)
    const cancelLinks = wrapper.findAll('a[href="/products/list"]')
    expect(cancelLinks.length).toBeGreaterThan(0)
    expect(cancelLinks.some(l => l.text().includes('取消') || l.text().includes('返回列表'))).toBe(true)
  })

  it('shows 基本信息 card section heading', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('基本信息')
  })

  it('shows 销售信息 card section heading', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('销售信息')
  })

  it('shows 物流属性 card section heading', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('物流属性')
  })

  it('shows 海关与产地 card section heading', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('海关与产地')
  })

  it('shows 成本参考 card section heading with accounting helper copy', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('成本参考')
    expect(wrapper.text()).toContain('Accounting')
  })

  it('shows 内部备注 card section heading', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('内部备注')
  })

  it('renders status segmented control (not a native checkbox)', () => {
    const wrapper = mount(Create)
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    const statusButtons = wrapper.findAll('.erp-segmented-option')
    expect(statusButtons.length).toBeGreaterThanOrEqual(2)
    expect(wrapper.text()).toContain('启用')
    expect(wrapper.text()).toContain('停用')
  })

  it('active segmented option is selected by default', () => {
    const wrapper = mount(Create)
    const selectedBtn = wrapper.find('.erp-segmented-option.selected')
    expect(selectedBtn.exists()).toBe(true)
    expect(selectedBtn.text()).toContain('启用')
  })

  it('shows unit, shipping weight, and all cost fields', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('单位')
    expect(wrapper.text()).toContain('发货重量')
    expect(wrapper.text()).toContain('标准生产成本')
    expect(wrapper.text()).toContain('标准包装成本')
    expect(wrapper.text()).toContain('标准采购成本')
    expect(wrapper.text()).toContain('成本币种')
    expect(wrapper.text()).toContain('原产国')
    expect(wrapper.text()).toContain('HS Code')
  })

  it('shows 体积重量参考 read-only field labeled as reference value', () => {
    const wrapper = mount(Create)
    expect(wrapper.text()).toContain('体积重量参考')
    expect(wrapper.text()).toContain('参考/计算值')
  })

  it('submit button is disabled while submitting', async () => {
    const wrapper = mount(Create)
    const btn = wrapper.find('button[type="submit"]')
    expect(btn.attributes('disabled')).toBeUndefined()
  })

  it('does not render a danger zone (create has no delete)', () => {
    const wrapper = mount(Create)
    expect(wrapper.find('.erp-danger-zone').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('删除产品')
  })

  it('uses erp-form-card class for card sections', () => {
    const wrapper = mount(Create)
    expect(wrapper.findAll('.erp-form-card').length).toBeGreaterThanOrEqual(6)
  })
})
