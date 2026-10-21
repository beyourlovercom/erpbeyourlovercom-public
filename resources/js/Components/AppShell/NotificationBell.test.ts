import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import NotificationBell from './NotificationBell.vue'

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn(), delete: vi.fn() } }))

const center = {
  unread_count: 2,
  items: [
    { id: 'n1', category: 'procurement', title: '订单生成采购需求', message: '订单 #1001 包含缺货产品（2 个 SKU），已生成采购需求。', action_url: '/procurement', entity_type: 'procurement_requirement', entity_id: 1, actor_name: 'System', related_links: [{ label: '相关订单', value: '#1001', url: '/customers/orders' }, { label: '相关采购需求', value: 'PR#PR-2026-001', url: '/procurement' }], read: false, created_at: '2026-09-04T10:00:00Z' },
    { id: 'n2', category: 'inventory', title: '库存低于安全库存', message: 'PKG-UAT-001 当前库存 5。', action_url: '/inventory', entity_type: 'product', entity_id: 1, actor_name: null, related_links: [], read: true, created_at: '2026-09-04T09:00:00Z' },
  ],
}

describe('NotificationBell', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders the approved list design with unread badge', async () => {
    const wrapper = mount(NotificationBell, { props: { center } })
    expect(wrapper.find('.notification-badge').text()).toBe('2')
    await wrapper.find('.notification-bell-trigger').trigger('click')
    expect(wrapper.findAll('.notification-list-row')).toHaveLength(2)
    expect(wrapper.text()).toContain('订单生成采购需求')
  })

  it('switches from list to the approved notification detail design', async () => {
    const wrapper = mount(NotificationBell, { props: { center } })
    await wrapper.find('.notification-bell-trigger').trigger('click')
    await wrapper.find('.notification-list-row').trigger('click')
    expect(wrapper.find('.notification-detail-header').exists()).toBe(true)
    expect(wrapper.find('.notification-design-detail').exists()).toBe(true)
    expect(wrapper.text()).toContain('相关订单')
    expect(wrapper.find('a[href="/customers/orders"]').text()).toContain('#1001')
    expect(router.post).toHaveBeenCalledWith('/notifications/n1/read', {}, { preserveScroll: true, preserveState: true })
    expect(wrapper.find('.notification-badge').text()).toBe('1')
    expect(wrapper.find('.notification-read-state').text()).toBe('已读')
  })

  it('returns to list without navigating', async () => {
    const wrapper = mount(NotificationBell, { props: { center } })
    await wrapper.find('.notification-bell-trigger').trigger('click')
    await wrapper.find('.notification-list-row').trigger('click')
    await wrapper.find('.notification-back-button').trigger('click')
    expect(wrapper.find('.notification-list-row').exists()).toBe(true)
  })

  it('treats opening notification detail as read and removes explicit read action', async () => {
    const wrapper = mount(NotificationBell, { props: { center } })
    await wrapper.find('.notification-bell-trigger').trigger('click')
    await wrapper.find('.notification-list-row').trigger('click')
    expect(router.post).toHaveBeenCalledWith('/notifications/n1/read', {}, { preserveScroll: true, preserveState: true })
    expect(wrapper.find('.notification-read-button').exists()).toBe(false)
    expect(wrapper.find('.notification-read-state').text()).toBe('已读')
  })

  it('marks all read from list header', async () => {
    const wrapper = mount(NotificationBell, { props: { center } })
    await wrapper.find('.notification-bell-trigger').trigger('click')
    await wrapper.find('.notification-header-actions button').trigger('click')
    expect(router.post).toHaveBeenCalledWith('/notifications/read-all', {}, { preserveScroll: true, preserveState: true })
  })
})
