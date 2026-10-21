import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from './AuthenticatedLayout.vue'
const shellCss = readFileSync(resolve(process.cwd(), 'resources/css/shell.css'), 'utf8')
let currentUrl = '/sync'
const items = [{ key: 'dashboard', label: 'Dashboard', emoji: '📊', path: '/dashboard', route: 'dashboard', placeholder: false, children: [] }, { key: 'sync', label: '同步', emoji: '🔄', path: '/sync', route: 'shell.sync', placeholder: true, children: [{ key: 'pending', label: '待处理', emoji: '⏳', path: '/sync/pending', route: 'sync.pending', placeholder: true, children: [] }] }]
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { post: vi.fn() }, usePage: () => ({ url: currentUrl, props: { auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } }, shellNavigation: items, notificationCenter: { unread_count: 0, items: [] } } }) }))

// Node's experimental global localStorage is unavailable under the project's jsdom/vitest
// combination (window.localStorage is undefined), unlike a real browser. This deterministic
// in-memory stand-in gives tests a controlled storage contract without weakening the
// production try/catch fallback in AuthenticatedLayout.vue, which must keep working against
// any Storage-shaped object, an absent one, or one that throws.
class TestLocalStorage {
  private store = new Map<string, string>()
  getItem(key: string) { return this.store.has(key) ? this.store.get(key)! : null }
  setItem(key: string, value: string) { this.store.set(key, String(value)) }
  removeItem(key: string) { this.store.delete(key) }
  clear() { this.store.clear() }
}

describe('authenticated layout', () => {
  let testLocalStorage: TestLocalStorage

  beforeEach(() => {
    currentUrl = '/sync'
    vi.clearAllMocks()
    testLocalStorage = new TestLocalStorage()
    Object.defineProperty(window, 'localStorage', { configurable: true, value: testLocalStorage })
  })

  afterEach(() => {
    Reflect.deleteProperty(window, 'localStorage')
  })

  it('renders navigation, account identity, slot and active routes', async () => { currentUrl = '/sync/pending'; const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['BYL ERP', '同步'] }, slots: { default: '<p>页面内容</p>' } }); expect(wrapper.find('[aria-label="ERP主导航"]').exists()).toBe(true); expect(wrapper.find('.shell-topbar').text()).not.toContain('Owner'); await wrapper.find('.shell-account-trigger').trigger('click'); expect(wrapper.find('.shell-account-menu').text()).toContain('Owner'); expect(wrapper.find('.shell-account-menu').text()).toContain('owner@example.test'); expect(wrapper.text()).toContain('页面内容'); expect(wrapper.find('a[href="/sync"]').attributes('aria-current')).toBe('page'); expect(wrapper.find('a[href="/sync/pending"]').attributes('aria-current')).toBe('page') })

  it('posts logout and toggles the single sidebar accessibly', async () => {
    const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } })
    expect(wrapper.find('[aria-label="收起导航"]').attributes('aria-expanded')).toBe('true')
    await wrapper.find('[aria-label="收起导航"]').trigger('click')
    expect(wrapper.find('[aria-label="展开导航"]').attributes('aria-expanded')).toBe('false')
    expect(wrapper.classes()).toContain('sidebar-collapsed')
    expect(wrapper.find('.shell-primary-nav').classes()).toContain('is-collapsed')
    await wrapper.find('.shell-account-trigger').trigger('click')
    await wrapper.find('.shell-account-logout').trigger('click')
    expect(router.post).toHaveBeenCalledWith('/logout')
  })

  it('restores the collapsed state from a persisted local preference', async () => {
    testLocalStorage.setItem('module_sidebar_collapsed', 'true')
    const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } })
    await wrapper.vm.$nextTick()
    expect(wrapper.classes()).toContain('sidebar-collapsed')
    expect(wrapper.find('.shell-primary-nav').classes()).toContain('is-collapsed')
  })

  it('fails safe and keeps toggling usable when local storage denies access', async () => {
    const throwingStorage = { getItem: () => { throw new Error('denied') }, setItem: () => { throw new Error('denied') }, removeItem: () => {}, clear: () => {}, key: () => null, length: 0 }
    Object.defineProperty(window, 'localStorage', { configurable: true, value: throwingStorage })
    const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } })
    await wrapper.vm.$nextTick()
    expect(wrapper.classes()).not.toContain('sidebar-collapsed')
    await wrapper.find('[aria-label="收起导航"]').trigger('click')
    expect(wrapper.classes()).toContain('sidebar-collapsed')
  })

  it('keeps the page header free of account controls', () => { const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } }); const topbar = wrapper.find('.shell-topbar'); expect(topbar.findAll('button')).toHaveLength(3); expect(topbar.find('[aria-label="全局搜索"]').exists()).toBe(true); expect(topbar.find('[aria-label="打开通知中心"]').exists()).toBe(true); expect(topbar.text()).not.toContain('Owner'); expect(topbar.text()).not.toContain('退出登录') })

  it('does not fabricate unauthorized navigation', () => { const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } }); expect(wrapper.text()).toContain('同步'); expect(wrapper.text()).not.toContain('设置') })

  it('removes child navigation from interaction while collapsed and restores it', async () => {
    currentUrl = '/sync/pending'
    const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } })
    expect(wrapper.find('a[href="/sync/pending"]').exists()).toBe(true)
    await wrapper.find('[aria-label="收起导航"]').trigger('click')
    expect(wrapper.find('a[href="/sync/pending"]').exists()).toBe(false)
    expect(wrapper.find('.shell-child-links').exists()).toBe(false)
    expect(wrapper.findAll('.shell-primary-label').length).toBeGreaterThan(0)
    expect(wrapper.get('a[href="/sync"] .shell-primary-label').text()).toBe('同步')
    await wrapper.find('[aria-label="展开导航"]').trigger('click')
    expect(wrapper.find('a[href="/sync/pending"]').exists()).toBe(true)
  })

  it('makes closed narrow-screen navigation inert and restores it when opened', async () => {
    const originalMatchMedia = window.matchMedia
    window.matchMedia = vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() })
    const wrapper = mount(AuthenticatedLayout, { props: { title: '同步', breadcrumbs: ['同步'] } })
    await wrapper.vm.$nextTick()
    const navigation = wrapper.find('[aria-label="ERP主导航"]')
    expect(navigation.attributes('inert')).toBeDefined()
    expect(navigation.attributes('aria-hidden')).toBe('true')
    expect(wrapper.find('[aria-label="打开导航"]').attributes('aria-expanded')).toBe('false')
    await wrapper.find('[aria-label="打开导航"]').trigger('click')
    expect(wrapper.find('[aria-label="ERP主导航"]').attributes('inert')).toBeUndefined()
    expect(wrapper.find('[aria-label="ERP主导航"]').attributes('aria-hidden')).toBe('false')
    expect(wrapper.find('[aria-label="关闭导航"]').attributes('aria-expanded')).toBe('true')
    await wrapper.find('.shell-account-trigger').trigger('click')
    expect(wrapper.find('.shell-account-menu').exists()).toBe(true)
    await wrapper.find('.shell-mobile-backdrop').trigger('click')
    expect(wrapper.find('[aria-label="ERP主导航"]').attributes('inert')).toBeDefined()
    expect(wrapper.find('.shell-account-menu').exists()).toBe(false)
    wrapper.unmount()
    window.matchMedia = originalMatchMedia
  })

  it('keeps the 800px shell breakpoint on one workspace grid column', () => {
    expect(shellCss).toContain('@media (max-width: 800px)')
    expect(shellCss).toMatch(/\.authenticated-shell,\s*\.authenticated-shell\.sidebar-collapsed\s*\{\s*grid-template-columns:\s*minmax\(0, 1fr\);\s*\}/)
    expect(shellCss).not.toMatch(/@media \(max-width: 800px\)[\s\S]*grid-template-columns:\s*0\s+0\s+minmax\(0, 1fr\)/)
    expect(shellCss).toContain('grid-template-columns: var(--byl-shell-sidebar-width) minmax(0, 1fr);')
  })
})
