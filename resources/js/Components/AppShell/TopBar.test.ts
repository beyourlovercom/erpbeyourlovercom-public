import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TopBar from './TopBar.vue'

vi.mock('../UI/UiButton.vue', () => ({
  default: { inheritAttrs: false, props: ['tone', 'size'], template: '<button v-bind="$attrs"><slot /></button>' },
}))

describe('TopBar breadcrumbs', () => {
  it('renders plain string breadcrumbs as spans', () => {
    const wrapper = mount(TopBar, { props: { title: 'Test', breadcrumbs: ['BYL ERP', '产品'], mobileExpanded: false } })
    const spans = wrapper.findAll('.shell-breadcrumbs span')
    expect(spans).toHaveLength(2)
    expect(spans[0].text()).toBe('BYL ERP')
    expect(spans[1].text()).toBe('产品')
  })

  it('renders breadcrumb objects with href as links', () => {
    const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, '列表']
    const wrapper = mount(TopBar, { props: { title: '列表', breadcrumbs, mobileExpanded: false } })
    const links = wrapper.findAll('.shell-breadcrumbs a')
    expect(links).toHaveLength(2)
    expect(links[0].attributes('href')).toBe('/dashboard')
    expect(links[0].text()).toBe('BYL ERP')
    expect(links[1].attributes('href')).toBe('/products')
  })

  it('renders last breadcrumb as non-navigable span', () => {
    const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, 'Order 123']
    const wrapper = mount(TopBar, { props: { title: 'Order', breadcrumbs, mobileExpanded: false } })
    const spans = wrapper.findAll('.shell-breadcrumbs span')
    expect(spans).toHaveLength(1)
    expect(spans[0].text()).toBe('Order 123')
  })
})

describe('TopBar back control', () => {
  it('shows back control linking to nearest parent breadcrumb href', () => {
    const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, '详情']
    const wrapper = mount(TopBar, { props: { title: '详情', breadcrumbs, mobileExpanded: false } })
    const back = wrapper.find('.shell-back-control')
    expect(back.exists()).toBe(true)
    expect(back.attributes('href')).toBe('/products')
    expect(back.attributes('aria-label')).toBe('返回上一层')
  })

  it('back control points to the last breadcrumb with href', () => {
    const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '产品']
    const wrapper = mount(TopBar, { props: { title: '产品', breadcrumbs, mobileExpanded: false } })
    const back = wrapper.find('.shell-back-control')
    expect(back.exists()).toBe(true)
    expect(back.attributes('href')).toBe('/dashboard')
  })

  it('does not render back control on root pages with no parent href', () => {
    const wrapper = mount(TopBar, { props: { title: '首页', breadcrumbs: ['BYL ERP'], mobileExpanded: false } })
    expect(wrapper.find('.shell-back-control').exists()).toBe(false)
  })

  it('does not render back control when all breadcrumbs lack href', () => {
    const wrapper = mount(TopBar, { props: { title: '首页', breadcrumbs: ['BYL ERP', '产品'], mobileExpanded: false } })
    expect(wrapper.find('.shell-back-control').exists()).toBe(false)
  })

  it('back control is an anchor tag, not a button (no history.back)', () => {
    const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '产品']
    const wrapper = mount(TopBar, { props: { title: '产品', breadcrumbs, mobileExpanded: false } })
    const back = wrapper.find('.shell-back-control')
    expect(back.element.tagName).toBe('A')
  })
})

describe('TopBar mobile toggle', () => {
  it('hamburger is in shell-topbar-trailing, not leading', () => {
    const wrapper = mount(TopBar, { props: { title: '首页', breadcrumbs: ['BYL ERP'], mobileExpanded: false } })
    const trailing = wrapper.find('.shell-topbar-trailing')
    expect(trailing.find('.shell-mobile-toggle').exists()).toBe(true)
    const leading = wrapper.find('.shell-topbar-leading')
    expect(leading.find('.shell-mobile-toggle').exists()).toBe(false)
  })

  it('emits toggleMobile when hamburger clicked', async () => {
    const wrapper = mount(TopBar, { props: { title: '首页', breadcrumbs: ['BYL ERP'], mobileExpanded: false } })
    await wrapper.find('.shell-mobile-toggle').trigger('click')
    expect(wrapper.emitted('toggleMobile')).toHaveLength(1)
  })

  it('reflects mobileExpanded in aria-expanded', () => {
    const wrapper = mount(TopBar, { props: { title: '首页', breadcrumbs: ['BYL ERP'], mobileExpanded: true } })
    expect(wrapper.find('.shell-mobile-toggle').attributes('aria-expanded')).toBe('true')
  })
})


describe('TopBar notification center', () => {
  it('renders the global bell before the mobile navigation control', () => {
    const wrapper = mount(TopBar, { props: { title: '首页', breadcrumbs: ['BYL ERP'], mobileExpanded: false, notificationCenter: { unread_count: 3, items: [] } } })
    expect(wrapper.find('[aria-label="打开通知中心"]').exists()).toBe(true)
    expect(wrapper.find('.notification-badge').text()).toBe('3')
  })
})


describe('TopBar global search', () => {
  it('renders global search in the trailing controls', () => {
    const wrapper = mount(TopBar, { props: { title: '首页', breadcrumbs: ['BYL ERP'], mobileExpanded: false } })
    expect(wrapper.find('[aria-label="全局搜索"]').exists()).toBe(true)
    expect(wrapper.find('.shell-topbar-trailing [aria-label="全局搜索"]').exists()).toBe(true)
  })
})
