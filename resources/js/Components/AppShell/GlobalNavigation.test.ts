import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import GlobalNavigation from './GlobalNavigation.vue'

vi.mock('@inertiajs/vue3', () => ({
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn() },
}))

const user = { id: 1, name: 'Test User', email: 'test@example.com' }
const defaults = [
  ['dashboard', 'Dashboard', '📊'], ['logistics', '物流', '🚚'], ['sync', '同步', '🔄'],
  ['customers', '客户', '👥'], ['kol', '网红', '⭐'], ['inventory', '库存', '📦'],
  ['procurement', '采购', '🛒'], ['products', '产品', '🏷️'], ['guides', '指南', '📖'],
  ['accounting', '会计', '🧾'], ['settings', '设置', '⚙️'],
].map(([key, label, emoji]) => ({ key, label, emoji, path: key === 'dashboard' ? '/dashboard' : `/${key}`, route: key, placeholder: key !== 'dashboard', children: [] }))

describe('GlobalNavigation', () => {
  it('renders every default emoji beside its label without exposing emoji to accessibility APIs', () => {
    const wrapper = mount(GlobalNavigation, { props: { items: defaults, currentPath: '/inventory', mobileHidden: false, collapsed: false, user } })
    const links = wrapper.findAll('.shell-primary-link')
    expect(links).toHaveLength(11)
    links.forEach((link, index) => {
      expect(link.get('.shell-primary-emoji').text()).toBe(defaults[index].emoji)
      expect(link.get('.shell-primary-emoji').attributes('aria-hidden')).toBe('true')
      expect(link.text()).toContain(defaults[index].label)
    })
    expect(wrapper.get('a[href="/inventory"]').classes()).toContain('is-active')
    expect(wrapper.get('a[href="/inventory"]').attributes('aria-current')).toBe('page')
  })

  it('keeps a fixed empty emoji slot and visible label when emoji is absent', () => {
    const item = { ...defaults[0], emoji: null }
    const wrapper = mount(GlobalNavigation, { props: { items: [item], currentPath: '/dashboard', mobileHidden: false, collapsed: false, user } })
    expect(wrapper.get('.shell-primary-emoji').text()).toBe('')
    expect(wrapper.get('.shell-primary-emoji').attributes('aria-hidden')).toBe('true')
    expect(wrapper.get('.shell-primary-link').text()).toContain('Dashboard')
  })

  it('accepts optional emoji throughout recursive child navigation data', () => {
    const nested = {
      ...defaults[2],
      children: [{
        key: 'pending', label: '待处理', emoji: '⏳', path: '/sync/pending', route: 'sync.pending', placeholder: false,
        children: [{ key: 'retry', label: '重试', emoji: null, path: '/sync/pending/retry', route: 'sync.retry', placeholder: false, children: [] }],
      }],
    }
    const wrapper = mount(GlobalNavigation, { props: { items: [nested], currentPath: '/sync', mobileHidden: false, collapsed: false, user } })
    expect(wrapper.props('items')[0].children[0].emoji).toBe('⏳')
    expect(wrapper.props('items')[0].children[0].children[0].emoji).toBeNull()
    expect(wrapper.get('.shell-primary-link').text()).toContain('同步')
  })

  it('places the account dock after the independently scrollable primary links', () => {
    const wrapper = mount(GlobalNavigation, { props: { items: defaults, currentPath: '/dashboard', mobileHidden: false, collapsed: false, user } })
    const navigation = wrapper.get('.shell-primary-nav').element
    expect(navigation.children[0].classList).toContain('shell-brand-row')
    expect(navigation.children[0].querySelector('.shell-brand')?.classList).toContain('shell-brand')
    expect(navigation.children[1].classList).toContain('shell-primary-links')
    expect(navigation.children[2].classList).toContain('shell-account-dock')
    expect(wrapper.get('.shell-account-trigger .shell-account-avatar').text()).toBe('TU')
  })

  it('makes the full navigation including account entry inert when mobile-hidden', () => {
    const wrapper = mount(GlobalNavigation, { props: { items: defaults, currentPath: '/dashboard', mobileHidden: true, collapsed: false, user } })
    expect(wrapper.get('nav').attributes('inert')).toBeDefined()
    expect(wrapper.get('nav').attributes('aria-hidden')).toBe('true')
    expect(wrapper.find('.shell-account-trigger').exists()).toBe(true)
  })

  it('renders child routes in the same sidebar and hides labels when collapsed', async () => {
    const wrapper = mount(GlobalNavigation, { props: { items: [{ ...defaults[2], children: [{ key: 'pending', label: '待处理', emoji: null, path: '/sync/pending', route: 'sync.pending', placeholder: false, children: [] }] }], currentPath: '/sync/pending', mobileHidden: false, collapsed: false, user } })
    expect(wrapper.get('a[href="/sync/pending"]').attributes('aria-current')).toBe('page')
    await wrapper.setProps({ collapsed: true })
    expect(wrapper.find('a[href="/sync/pending"]').exists()).toBe(false)
    expect(wrapper.get('.shell-primary-link').attributes('title')).toBe('同步')
  })

  it('keeps Level-1 shell-primary-label elements in the DOM when collapsed', async () => {
    const wrapper = mount(GlobalNavigation, { props: { items: defaults, currentPath: '/dashboard', mobileHidden: false, collapsed: false, user } })
    expect(wrapper.findAll('.shell-primary-label')).toHaveLength(defaults.length)
    await wrapper.setProps({ collapsed: true })
    expect(wrapper.findAll('.shell-primary-label')).toHaveLength(defaults.length)
    defaults.forEach((item, index) => expect(wrapper.findAll('.shell-primary-label')[index].text()).toBe(item.label))
  })

  it('removes shell-child-links from the DOM and interaction entirely when collapsed', async () => {
    const nested = { ...defaults[2], children: [{ key: 'pending', label: '待处理', emoji: null, path: '/sync/pending', route: 'sync.pending', placeholder: false, children: [] }] }
    const wrapper = mount(GlobalNavigation, { props: { items: [nested], currentPath: '/sync/pending', mobileHidden: false, collapsed: false, user } })
    expect(wrapper.find('.shell-child-links').exists()).toBe(true)
    await wrapper.setProps({ collapsed: true })
    expect(wrapper.find('.shell-child-links').exists()).toBe(false)
    expect(wrapper.find('a[href="/sync/pending"]').exists()).toBe(false)
  })

  it('renders a non-link group label whose real children render recursively and remain active-detectable', () => {
    const group = {
      key: 'expense-analysis', label: '费用分析表', emoji: null, path: '/accounting/expense-analysis', route: null, placeholder: false, group: true,
      children: [{ key: 'actual-2025', label: '2025实际', emoji: null, path: '/accounting/expense-analysis/2025-actual', route: 'accounting.actual-2025', placeholder: false, children: [] }],
    }
    const accounting = { ...defaults[9], children: [group] }
    const wrapper = mount(GlobalNavigation, { props: { items: [accounting], currentPath: '/accounting/expense-analysis/2025-actual', mobileHidden: false, collapsed: false, user } })
    expect(wrapper.find('a[href="/accounting/expense-analysis"]').exists()).toBe(false)
    expect(wrapper.find('.shell-primary-link-group').exists()).toBe(true)
    expect(wrapper.get('.shell-primary-link-group').text()).toContain('费用分析表')
    const childLink = wrapper.get('a[href="/accounting/expense-analysis/2025-actual"]')
    expect(childLink.classes()).toContain('is-active')
    expect(childLink.attributes('aria-current')).toBe('page')
  })
})
