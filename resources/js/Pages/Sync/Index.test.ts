import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { routerPost } = vi.hoisted(() => ({ routerPost: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: routerPost },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const domains = [
  { key: 'orders', title: '同步订单', subtitle: 'WooCommerce / Shopify / ERP', href: '/sync/orders', last_sync_at: '2026-08-28T08:00:00Z', status: 'completed', succeeded_items: 3, failed_items: 0, conflict_items: 0 },
  { key: 'warehouses', title: '同步仓库', subtitle: '仓库', href: '/sync/warehouses', last_sync_at: null, status: 'not_started', succeeded_items: 0, failed_items: 0, conflict_items: 0 },
  { key: 'kol', title: '同步网红', subtitle: 'IG / X', href: '/sync/kol', last_sync_at: null, status: 'not_started', succeeded_items: 0, failed_items: 0, conflict_items: 0 },
  { key: 'exports', title: '导出平台订单', subtitle: 'Export', href: '/sync/exports', last_sync_at: null, status: 'not_started', succeeded_items: 0, failed_items: 0, conflict_items: 0 },
]
const queueSummary = { retry_due: 1, retry_waiting: 2, processing: 3, failed: 4, conflicts: 5 }
const wooOrderRun = { id: 20, domain: 'orders', run_type: 'woocommerce_order_incremental', provider_key: 'woocommerce', status: 'completed', initiator: null, started_at: '2026-09-06T03:00:00Z', completed_at: '2026-09-06T03:00:02Z', requested_items: 2, succeeded_items: 2, failed_items: 0, conflict_items: 0 }
const wooOrderSync = { enabled: true, frequency: '每 1 小时', can_operate: true, manual_action: '/sync/woocommerce/incremental', advanced_href: '/sync/woocommerce', latest_run: wooOrderRun }
describe('Sync overview', () => {
  it('renders the four frozen second-level workspaces', () => {
    const wrapper = mount(Index, { props: { domains, queueSummary, manualRuns: [], automaticRuns: [], wooOrderSync } })
    for (const domain of domains) {
      expect(wrapper.text()).toContain(domain.title)
      expect(wrapper.find(`a[href="${domain.href}"]`).exists()).toBe(true)
    }
    expect(wrapper.text()).toContain('最后同步')
    expect(wrapper.text()).toContain('订单同步队列')
  })

  it('separates manual and automatic run history without inventing records', () => {
    const run = { id: 11, domain: 'orders', run_type: 'tracking_selected', provider_key: 'woocommerce', status: 'completed', initiator: 'Andy', started_at: '2026-08-28T08:00:00Z', completed_at: '2026-08-28T08:01:00Z', requested_items: 1, succeeded_items: 1, failed_items: 0, conflict_items: 0 }
    const systemRun = { ...run, id: 12, initiator: null, run_type: 'scheduled_incremental' }
    const wrapper = mount(Index, { props: { domains, queueSummary, manualRuns: [run], automaticRuns: [systemRun], wooOrderSync } })
    expect(wrapper.text()).toContain('手动同步记录')
    expect(wrapper.text()).toContain('自动同步记录')
    expect(wrapper.text()).toContain('Andy')
    expect(wrapper.find('a[href="/sync/runs/11"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/sync/runs/12"]').exists()).toBe(true)
  })

  it('shows Woo order automatic status and runs today-only manual sync from the overview', async () => {
    routerPost.mockClear()
    const wrapper = mount(Index, { props: { domains, queueSummary, manualRuns: [], automaticRuns: [wooOrderRun], wooOrderSync } })
    expect(wrapper.text()).toContain('WooCommerce 订单同步')
    expect(wrapper.text()).toContain('每 1 小时')
    expect(wrapper.text()).toContain('2 成功 / 0 失败')
    expect(wrapper.find('a[href="/sync/woocommerce"]').exists()).toBe(true)
    await wrapper.get('button').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/sync/woocommerce/incremental', {}, { preserveScroll: true })
  })
})
