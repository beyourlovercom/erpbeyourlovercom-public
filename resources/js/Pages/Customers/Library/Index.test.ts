import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { get, put, axiosGet } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(() => Promise.resolve({ data: {} })), axiosGet: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get },
}))
vi.mock('axios', () => ({ default: { put, get: axiosGet } }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

beforeEach(() => {
  get.mockReset()
  axiosGet.mockReset()
  put.mockClear()
  const store = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => store.set(key, value),
    removeItem: (key: string) => store.delete(key),
  })
})

const baseStats = {
  total_customers: 5,
  new_this_month: 1,
  orders_this_month: 2,
  revenue_this_month: [{ currency: 'USD', revenue: 100 }],
  rmb_estimate: {
    available: true as const,
    amount_cny: 710,
    source: 'SETTINGS_FX',
    reference_date: '2026-08-28',
    stale: false,
    conversions: [{ original_currency: 'USD', original_amount: 100, rate_to_cny: 7.1, cny_amount: 710, source: 'ecb' }],
  },
  subscriber_count_available: true,
  subscriber_identity_links: 3,
  open_support_tickets_available: false,
}

const baseProps = {
  customers: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null, links: [] },
  filters: {},
  sources: {},
  stats: baseStats,
  canOperate: false,
  canImport: false,
  canExport: false,
  workspace: 'library' as const,
  gridPreferenceNamespace: 'grid.customers.library.v1',
  gridPreference: null,
}

const groupProps = {
  ...baseProps,
  workspace: 'groups' as const,
  gridPreferenceNamespace: 'grid.customers.groups.v1',
}

describe('Customer Library KPI Strip — compact', () => {
  it('kpi-strip renders exactly six cards in non-compact mode', () => {
    const wrapper = mount(Index, { props: baseProps })
    const strip = wrapper.find('[data-testid="kpi-strip"]')
    expect(strip.exists()).toBe(true)
    expect(strip.findAll('article').length).toBe(6)
  })

  it('each KPI card has at most two visible text elements (label + value)', () => {
    const wrapper = mount(Index, { props: baseProps })
    const cards = wrapper.findAll('[data-testid="kpi-strip"] article')
    for (const card of cards) {
      // Only p elements are visible text rows per spec; there must be ≤2
      const textRows = card.findAll('p')
      expect(textRows.length).toBeLessThanOrEqual(2)
    }
  })

  it('does not render visible kpi-currency-tag elements', () => {
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.findAll('.kpi-currency-tag').length).toBe(0)
  })

  it('does not render visible kpi-note elements', () => {
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.findAll('.kpi-note').length).toBe(0)
  })

  it('monthly sales card shows CNY value when rmb_estimate is available', () => {
    const wrapper = mount(Index, { props: baseProps })
    const strip = wrapper.find('[data-testid="kpi-strip"]')
    // The 4th card (index 3) is 本月销售额
    const salesCard = strip.findAll('article')[3]
    expect(salesCard.text()).toContain('本月销售额')
    // Should show a CNY formatted number, not USD breakdown
    expect(salesCard.text()).not.toContain('USD')
    expect(salesCard.text()).not.toContain('$')
  })

  it('monthly sales tooltip identifies ERP Settings FX as the current source', () => {
    const wrapper = mount(Index, { props: baseProps })
    const salesCard = wrapper.findAll('[data-testid="kpi-strip"] article')[3]
    expect(salesCard.attributes('title')).toContain('ERP 汇率设置')
    expect(salesCard.attributes('title')).toContain('ECB 2026-08-28')
  })

  it('monthly sales card shows em-dash when rmb_estimate is unavailable', () => {
    const props = {
      ...baseProps,
      stats: {
        ...baseStats,
        rmb_estimate: { available: false as const, reason: 'fx_unavailable' },
      },
    }
    const wrapper = mount(Index, { props })
    const salesCard = wrapper.findAll('[data-testid="kpi-strip"] article')[3]
    expect(salesCard.text()).toContain('—')
  })

  it('subscriber and support cards show real aggregate counts', () => {
    const wrapper = mount(Index, { props: baseProps })
    const cards = wrapper.findAll('[data-testid="kpi-strip"] article')
    const subscriberCard = cards[4]
    const ticketCard = cards[5]

    expect(subscriberCard.text()).toContain('Subscriber 匹配')
    expect(subscriberCard.text()).toContain('3')
    expect(subscriberCard.text()).not.toContain('M1.3')

    expect(ticketCard.text()).toContain('待处理工单')
    expect(ticketCard.text()).toContain('0')
    expect(ticketCard.text()).not.toContain('M1.4')
  })

  it('subscriber and ticket cards carry accurate explanation in title attribute', () => {
    const wrapper = mount(Index, { props: baseProps })
    const cards = wrapper.findAll('[data-testid="kpi-strip"] article')
    expect(cards[4].attributes('title')).toContain('精确匹配')
    expect(cards[5].attributes('title')).toContain('待处理客户支持工单')
  })

  it('customer groups workspace consolidates system groups into the navigator', async () => {
    const wrapper = mount(Index, { props: { ...groupProps, filters: { group: 'repeat_customer' }, systemGroupCounts: { '': 12, repeat_customer: 4 } } })
    expect(wrapper.find('.customer-group-shortcut-rail').exists()).toBe(false)
    expect(wrapper.findAll('.groups-row').map(link => link.text()).some(text => text.includes('复购客户') && text.includes('4'))).toBe(true)
    expect(wrapper.find('.groups-row-selected').text()).toContain('复购客户')
    expect(wrapper.find('.groups-row-selected').attributes('aria-current')).toBe('page')
    expect(wrapper.find('#filter-group').exists()).toBe(false)
    expect(wrapper.find('a[href*="group=repeat_customer"]').attributes('href')).toContain('/customers/groups?')
    await wrapper.get('.column-settings-trigger').trigger('click')
    expect(wrapper.get('.column-settings-panel').text()).toContain('设置绑定当前账号并跨设备同步')
  })

  it('independently collapses sections and the whole navigator', async () => {
    const wrapper = mount(Index, { props: { ...groupProps, manualGroups: [{ id: 7, name: 'VIP 手动组', description: null, member_count: 2 }] } })
    const toggles = wrapper.findAll('.groups-section-toggle')
    await toggles[0].trigger('click')
    expect(wrapper.find('[aria-label="系统分组"].groups-row-list').exists()).toBe(false)
    expect(wrapper.find('[aria-label="手动分组"].groups-row-list').exists()).toBe(true)
    await toggles[1].trigger('click')
    expect(wrapper.find('[aria-label="手动分组"].groups-row-list').exists()).toBe(false)
    await wrapper.get('.groups-icon-button').trigger('click')
    expect(wrapper.find('.groups-navigator-collapsed').exists()).toBe(true)
    expect(wrapper.find('.groups-rail-mark').exists()).toBe(true)
    await wrapper.get('.groups-icon-button').trigger('click')
    expect(wrapper.find('.groups-navigator-collapsed').exists()).toBe(false)
  })

  it('keeps system actions absent and gates manual actions by customers.operate', () => {
    const system = mount(Index, { props: { ...groupProps, canOperate: true, filters: { group: 'repeat_customer' }, customers: { ...groupProps.customers, total: 4 } } })
    expect(system.find('.groups-current-header').text()).not.toContain('添加客户')
    expect(system.find('.groups-current-header').text()).not.toContain('删除')

    const manual = { id: 7, name: 'VIP 手动组', description: null, member_count: 2 }
    const reader = mount(Index, { props: { ...groupProps, selectedManualGroup: manual, manualGroups: [manual], canOperate: false } })
    expect(reader.find('.groups-current-header').text()).not.toContain('重命名')
    expect(reader.find('.groups-current-header').text()).not.toContain('添加客户')
    expect(reader.find('.groups-current-header').text()).not.toContain('删除')
    expect(reader.find('input[aria-label="搜索可加入客户"]').exists()).toBe(false)
  })

  it('customer library keeps the group filter and exposes account column settings', () => {
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.findAll('.customer-group-shortcut').length).toBe(0)
    expect(wrapper.find('#filter-group').exists()).toBe(true)
    expect(wrapper.find('#filter-group').findAll('option').map(option => option.text())).toContain('活跃客户')
    expect(wrapper.find('#filter-group').findAll('option').map(option => option.text())).toContain('流失风险客户')
    expect(wrapper.find('#filter-group').findAll('option').map(option => option.text())).toContain('沉睡客户')
    expect(wrapper.find('#filter-group').findAll('option').map(option => option.text())).toContain('高频客户')
    expect(wrapper.find('#filter-group').findAll('option').map(option => option.text())).toContain('高价值客户')
    expect(wrapper.find('#filter-group').findAll('option').map(option => option.text())).toContain('VIP客户')
    expect(wrapper.find('.column-settings-trigger').exists()).toBe(true)
  })

  it('renders manual groups separately and exposes manual options in the library filter', async () => {
    const props = {
      ...baseProps,
      manualGroups: [{ id: 7, name: 'VIP 手动组', description: 'Selected', member_count: 2 }],
    }
    const library = mount(Index, { props })
    expect(library.find('#filter-group').findAll('option').map(option => option.text())).toContain('VIP 手动组')

    const groups = mount(Index, { props: { ...props, ...groupProps, canOperate: true, selectedManualGroup: props.manualGroups[0] } })
    expect(groups.find('[aria-label="手动分组"]').text()).toContain('VIP 手动组')
    expect(groups.find('.groups-current-header').text()).toContain('手动分组')
    expect(groups.find('input[aria-label="新分组名称"]').exists()).toBe(false)
    await groups.get('button[aria-label="打开创建手动分组"]').trigger('click')
    expect(groups.find('input[aria-label="新分组名称"]').isVisible()).toBe(true)
    await groups.get('button[aria-label="取消创建手动分组"]').trigger('click')
    expect(groups.find('input[aria-label="新分组名称"]').exists()).toBe(false)
    expect(groups.findAll('button').some(button => button.text() === '重命名')).toBe(true)
    expect(groups.findAll('button').some(button => button.text() === '删除')).toBe(true)
    expect(groups.find('.groups-header-actions').findAll('button').map(button => button.text())).toEqual(['添加客户', '重命名', '删除', '…'])
  })

  it('shows the empty manual group state and bounded add flow', async () => {
    const wrapper = mount(Index, { props: {
      ...groupProps,
      canOperate: true,
      manualGroups: [{ id: 8, name: 'Empty Group', description: null, member_count: 0 }],
      selectedManualGroup: { id: 8, name: 'Empty Group', description: null, member_count: 0 },
    } })
    expect(wrapper.find('.manual-group-empty-state').text()).toContain('还没有客户')
    expect(wrapper.find('input[aria-label="搜索可加入客户"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === '添加客户')).toBe(true)
    await wrapper.get('.groups-header-actions button').trigger('click')
    expect(wrapper.find('input[aria-label="搜索可加入客户"]').exists()).toBe(true)
    await wrapper.get('button[aria-label="关闭添加客户"]').trigger('click')
    expect(wrapper.find('input[aria-label="搜索可加入客户"]').exists()).toBe(false)
  })

  it('keeps shipment count hidden by default and exposes shipment groups in the navigator', async () => {
    const wrapper = mount(Index, { props: groupProps })
    expect(wrapper.findAll('thead th').map(node => node.text())).not.toContain('Shipment count')
    expect(wrapper.findAll('.groups-row').map(link => link.text()).some(text => text.includes('已发货客户'))).toBe(true)
    expect(wrapper.findAll('.groups-row').map(link => link.text()).some(text => text.includes('尚未发货客户'))).toBe(true)
    await wrapper.get('.column-settings-trigger').trigger('click')
    expect(wrapper.findAll('input[aria-label="显示Shipment count"]').length).toBe(1)
  })

  it('optional subscription column preserves conflicting provider statuses', async () => {
    const wrapper = mount(Index, { props: {
      ...baseProps,
      customers: { ...baseProps.customers, data: [{
        id: 1, customer_number: 'CUS-00000001', name: 'Alice', email: 'alice@example.test', phone: null,
        country_code: null, source_label: '手工录入', owner: null, orders_count: 0, latest_order_date: null,
        shipment_count: 0,
        lifetime_revenue: [], primary_address: null, archived_at: null, created_at: null,
        subscription: { matched: true, subscription_facts: [{ provider: 'wordpress', source_site: 'beyourlover.com', status: 'subscribed' }, { provider: 'mail-b', source_site: 'site-b', status: 'unsubscribed' }], subscription_statuses: ['subscribed', 'unsubscribed'] },
      }] },
    } })
    await wrapper.get('.column-settings-trigger').trigger('click')
    await wrapper.get('input[aria-label="显示订阅"]').trigger('change')
    expect(wrapper.get('tbody').text()).toContain('wordpress · beyourlover.com: subscribed | mail-b · site-b: unsubscribed')
  })

  it('server preference wins over conflicting local cache', () => {
    localStorage.setItem('ui-pref-cache:grid.customers.groups.v1', JSON.stringify({ columns: [
      { key: 'email', visible: true }, { key: 'phone', visible: false },
    ] }))
    const wrapper = mount(Index, { props: { ...groupProps, gridPreference: { columns: [
      { key: 'phone', visible: true }, { key: 'email', visible: false },
    ] } } })
    const headers = wrapper.findAll('thead th').map(node => node.text())
    expect(headers[1]).toBe('电话')
    expect(headers).not.toContain('Email')
  })

  it('column visibility change writes account preference and local cache', async () => {
    const wrapper = mount(Index, { props: groupProps })
    await wrapper.get('.column-settings-trigger').trigger('click')
    await wrapper.get('input[aria-label="显示城市"]').trigger('change')
    await flushPromises()
    expect(wrapper.get('thead').text()).not.toContain('城市')
    expect(put).toHaveBeenCalledWith('/ui/preferences/grid.customers.groups.v1', expect.objectContaining({ value: expect.any(Object) }))
    expect(localStorage.getItem('ui-pref-cache:grid.customers.groups.v1')).toContain('city')
  })

  it('column drag order is cached and synced to the account', async () => {
    const wrapper = mount(Index, { props: groupProps })
    await wrapper.get('.column-settings-trigger').trigger('click')
    await wrapper.get('[data-column-key="phone"]').trigger('dragstart')
    await wrapper.get('[data-column-key="email"]').trigger('drop')
    await flushPromises()
    expect(wrapper.findAll('thead th').slice(0, 3).map(node => node.text())).toEqual(['客户编号 / 姓名', '电话', 'Email'])
    expect(put).toHaveBeenCalled()
  })

  it('legacy browser preference migrates to the account when server preference is empty', async () => {
    localStorage.setItem('customer_groups_grid_columns_v1', JSON.stringify([
      { key: 'phone', visible: true }, { key: 'email', visible: false },
    ]))
    mount(Index, { props: groupProps })
    await flushPromises()
    expect(put).toHaveBeenCalledWith('/ui/preferences/grid.customers.groups.v1', expect.any(Object))
  })

  it('kpi-strip is not rendered in compact mode', () => {
    // Simulate compact mode via localStorage
    localStorage.setItem('customer_list_compact_mode', '1')
    const wrapper = mount(Index, { props: baseProps })
    expect(wrapper.find('[data-testid="kpi-strip"]').exists()).toBe(false)
    localStorage.removeItem('customer_list_compact_mode')
  })
})
