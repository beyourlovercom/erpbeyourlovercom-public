import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'
import type { GridPreferenceValue } from '../../Composables/useAccountGridPreferences'

const { getMock, postMock, putMock } = vi.hoisted(() => ({ getMock: vi.fn(), postMock: vi.fn(), putMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: getMock, post: postMock },
}))
vi.mock('axios', () => ({ default: { put: putMock } }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const row = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  display_name: 'Bound Creator',
  country_code: 'US',
  shipping_city: 'Austin',
  status: 'active',
  relationship_stage: 'cooperating',
  owner: { id: 10, name: 'Owner' },
  primary_platform: 'instagram',
  latest_followers: 12345,
  is_100k_plus: false,
  joined_at: '2026-01-10',
  level: '腰部',
  categories: ['Lifestyle'],
  products: ['SKU-1'],
  content_format: '短视频',
  product_quantity: 1,
  internal_contact: 'Andy',
  mcn_agency: null,
  is_mcn_managed: false,
  historical_order_count: 2,
  has_published_content: true,
  affiliate_account: {
    affiliate_id: 1430,
    wordpress_user_id: 8811,
    wordpress_user_login: 'provider-login',
  },
  ...overrides,
})

type KolGridColumnKey = 'account' | 'level' | 'city' | 'country_region' | 'categories' | 'products' | 'content_format' | 'product_quantity' | 'relationship_stage' | 'status' | 'mcn_agency' | 'owner' | 'followers' | 'published' | 'historical_order_count' | 'joined_at' | 'internal_contact'

const mountPage = (rows: ReturnType<typeof row>[], gridPreference: GridPreferenceValue<KolGridColumnKey> | null = null) => mount(Index, {
  props: {
    kols: { data: rows, current_page: 1, last_page: 1, per_page: 30, total: rows.length },
    filters: {},
    savedViews: [],
    ownerOptions: [],
    canOperate: true,
    canManageViews: true,
    isAllScope: true,
    gridPreferenceNamespace: 'grid.kol.library.v1',
    gridPreference,
  },
})

beforeEach(() => {
  getMock.mockReset()
  postMock.mockReset()
  putMock.mockReset().mockResolvedValue({ data: {} })
  const store = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => store.set(key, value),
    removeItem: (key: string) => store.delete(key),
  })
})

describe('KOL library Affiliate account visibility and grid preferences', () => {
  it('shows provider-backed WordPress login and exact AffiliateWP id for a bound KOL', () => {
    const wrapper = mountPage([row()])
    expect(wrapper.text()).toContain('WordPress: provider-login')
    expect(wrapper.text()).toContain('AffiliateWP: #1430')
    expect(wrapper.text()).not.toContain('provider@example.test')
  })

  it('falls back to WordPress user id only when provider login is absent', () => {
    const wrapper = mountPage([row({ affiliate_account: {
      affiliate_id: 1238,
      wordpress_user_id: 7712,
      wordpress_user_login: null,
    } })])
    expect(wrapper.text()).toContain('WordPress: WP User #7712')
    expect(wrapper.text()).toContain('AffiliateWP: #1238')
  })

  it('shows an explicit muted unbound state without inventing an account', () => {
    const wrapper = mountPage([row({ id: 2, display_name: 'Historical No Affiliate', affiliate_account: null })])
    expect(wrapper.text()).toContain('未绑定 Affiliate')
    expect(wrapper.text()).not.toContain('AffiliateWP: #')
  })

  it('reuses the canonical three-dot column settings VI with pinned identity and account sync copy', async () => {
    const wrapper = mountPage([row()])
    expect(wrapper.find('.column-settings-panel').exists()).toBe(false)
    await wrapper.get('.column-settings-trigger').trigger('click')
    expect(wrapper.get('.column-settings-panel').text()).toContain('列设置')
    expect(wrapper.get('.column-settings-panel').text()).toContain('设置绑定当前账号并跨设备同步')
    expect(wrapper.get('.column-setting-pinned').text()).toContain('红人名称')
    expect(wrapper.get('.column-setting-pinned').text()).toContain('固定')
  })

  it('hides and restores an optional column through account preferences', async () => {
    const wrapper = mountPage([row()])
    await wrapper.get('.column-settings-trigger').trigger('click')
    expect(wrapper.findAll('th').some(th => th.text() === '城市')).toBe(true)
    await wrapper.get('input[aria-label="显示城市"]').setValue(false)
    expect(wrapper.findAll('th').some(th => th.text() === '城市')).toBe(false)
    expect(putMock).toHaveBeenCalledWith('/ui/preferences/grid.kol.library.v1', expect.objectContaining({ value: expect.any(Object) }))
    expect(localStorage.getItem('ui-pref-cache:grid.kol.library.v1')).toContain('city')
  })

  it('uses saved account column order and supports drag reorder', async () => {
    const wrapper = mountPage([row()], { columns: [
      { key: 'followers', visible: true },
      { key: 'account', visible: true },
      { key: 'level', visible: true },
    ] })
    const headers = wrapper.findAll('th').map(th => th.text()).filter(Boolean)
    expect(headers.slice(0, 4)).toEqual(['红人名称', '粉丝数', '账号', '等级'])
    await wrapper.get('.column-settings-trigger').trigger('click')
    await wrapper.get('[data-column-key="account"]').trigger('dragstart')
    await wrapper.get('[data-column-key="followers"]').trigger('drop')
    const reordered = wrapper.findAll('th').map(th => th.text()).filter(Boolean)
    expect(reordered.slice(0, 4)).toEqual(['红人名称', '账号', '粉丝数', '等级'])
    expect(putMock).toHaveBeenCalledWith('/ui/preferences/grid.kol.library.v1', expect.any(Object))
  })
})
