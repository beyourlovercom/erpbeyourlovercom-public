import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }))

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { template: '<a><slot /></a>' },
  useForm: (initial: Record<string, unknown>) => ({ ...initial, processing: false, errors: {}, post: postMock }),
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const affiliate = {
  id: 1,
  affiliate_id: 101,
  wordpress_user_id: 5001,
  wordpress_user_email: 'alice@example.com',
  display_name: 'Alice Creator',
  email: 'alice@example.com',
  provider_status: 'active',
  kol: null,
  origin: 'external_sync',
  sync_status: 'synced',
  reconciliation_status: 'present',
  provisioning_status: 'not_requested',
  last_error_code: null,
  last_error_message: null,
  last_successful_sync_at: '2026-08-29T00:00:00+00:00',
}

const candidate = {
  kol_id: 9,
  display_name: 'Approved KOL',
  contact_email: 'approved@example.test',
  approval_status: 'approved',
  affiliate_id: null,
  wordpress_user_id: null,
  provider_status: null,
  provisioning_status: 'not_requested',
  last_error_code: null,
  last_error_message: null,
  can_provision: true,
  disabled_reason: null,
}

function props(overrides: Record<string, unknown> = {}) {
  return {
    canManageApprovals: false,
    canManageAffiliates: false,
    affiliates: [],
    coupons: [],
    provisioningCandidates: [],
    couponCandidates: [],
    kolOptions: [],
    ownerOptions: [],
    ...overrides,
  }
}

describe('AffiliateWP workspace', () => {
  beforeEach(() => postMock.mockReset())

  it('renders Influencer mirror rows and exposes the M1.4C Coupon mode', async () => {
    const wrapper = mount(Index, { props: props({ affiliates: [affiliate] }) })

    expect(wrapper.text()).toContain('Alice Creator')
    expect(wrapper.text()).toContain('101')
    expect(wrapper.text()).toContain('5001')
    expect(wrapper.text()).toContain('未绑定')
    expect(wrapper.text()).toContain('not_requested')

    const couponTab = wrapper.findAll('button').find((button) => button.text() === 'Coupon')!
    expect(couponTab.attributes('disabled')).toBeUndefined()
    await couponTab.trigger('click')
    expect(wrapper.text()).toContain('暂无 Coupon mirror')
  })

  it('shows the authorized real-data reconciliation header action', () => {
    const wrapper = mount(Index, { props: props({ canManageAffiliates: true }) })
    const link = wrapper.find('a')
    expect(link.text()).toContain('真实数据核对')
  })


  it('manager can sync Coupon mirror from Coupon mode', async () => {
    const wrapper = mount(Index, { props: props({ canManageAffiliates: true }) })
    await wrapper.findAll('button').find((button) => button.text() === 'Coupon')!.trigger('click')
    const button = wrapper.findAll('button').find((item) => item.text() === '同步 Coupon')!
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/affiliatewp/coupons/sync', { preserveScroll: true })
  })

  it('keeps manual sync visible but disabled without affiliate management permission', () => {
    const wrapper = mount(Index, { props: props() })
    const button = wrapper.find('button[title="需要 Affiliate 管理权限"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('暂无 AffiliateWP Influencer 同步数据')
    expect(wrapper.text()).toContain('当前作用域没有已批准且未归档的 KOL')
  })

  it('allows manager to trigger canonical manual sync action', async () => {
    const wrapper = mount(Index, { props: props({ canManageApprovals: true, canManageAffiliates: true }) })
    await wrapper.find('button[title="从 WordPress / AffiliateWP 读取完整 Influencer 快照"]').trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/affiliatewp/sync', { preserveScroll: true })
  })

  it('keeps binding actions visible but disabled for specialists', () => {
    const wrapper = mount(Index, { props: props({ affiliates: [affiliate] }) })
    const bindButton = wrapper.findAll('button').find((button) => button.text() === '绑定现有 KOL')
    const convertButton = wrapper.findAll('button').find((button) => button.text() === '转为新 KOL')
    expect(bindButton?.attributes('disabled')).toBeDefined()
    expect(convertButton?.attributes('disabled')).toBeDefined()
    expect(bindButton?.attributes('title')).toBe('需要 Affiliate 管理权限')
    expect(convertButton?.attributes('title')).toBe('需要 Affiliate 管理权限')
  })

  it('manager can open explicit bind and convert panels', async () => {
    const wrapper = mount(Index, {
      props: props({
        canManageApprovals: true,
        canManageAffiliates: true,
        affiliates: [affiliate],
        kolOptions: [{ value: 9, label: 'Existing KOL' }],
        ownerOptions: [{ value: 7, label: 'Manager Owner' }],
      }),
    })

    await wrapper.findAll('button').find((button) => button.text() === '绑定现有 KOL')!.trigger('click')
    expect(wrapper.find('[data-testid="bind-panel"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Existing KOL')

    await wrapper.findAll('button').find((button) => button.text() === '转为新 KOL')!.trigger('click')
    expect(wrapper.find('[data-testid="convert-panel"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('不会改变 AffiliateWP 当前 Provider 状态')
    expect(wrapper.text()).toContain('Manager Owner')
  })

  it('shows approved KOL provisioning entry and posts to the existing Core D route', async () => {
    const wrapper = mount(Index, {
      props: props({ canManageAffiliates: true, provisioningCandidates: [candidate] }),
    })

    expect(wrapper.find('[data-testid="provisioning-panel"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Approved KOL')
    const button = wrapper.findAll('button').find((item) => item.text() === 'Provision')!
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/affiliatewp/provision', { preserveScroll: true })
  })

  it('keeps provisioning capability visible but disabled for specialist', () => {
    const specialistCandidate = { ...candidate, can_provision: false, disabled_reason: '需要 Affiliate 管理权限' }
    const wrapper = mount(Index, { props: props({ provisioningCandidates: [specialistCandidate] }) })
    const button = wrapper.findAll('button').find((item) => item.text() === 'Provision')!
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('title')).toBe('需要 Affiliate 管理权限')
  })

  it('surfaces retryable provisioning failure state and safe retry action', () => {
    const failedCandidate = {
      ...candidate,
      provisioning_status: 'readback_mismatch',
      last_error_code: 'READBACK_MISMATCH',
      last_error_message: 'Provider response did not confirm active identity.',
    }
    const wrapper = mount(Index, {
      props: props({ canManageAffiliates: true, provisioningCandidates: [failedCandidate] }),
    })
    expect(wrapper.text()).toContain('READBACK_MISMATCH')
    expect(wrapper.text()).toContain('Provider response did not confirm active identity.')
    const button = wrapper.findAll('button').find((item) => item.text() === '安全重试')!
    expect(button.attributes('title')).toBe('使用原 idempotency key 安全重试')
    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('disables provisioning when state is already active or in progress', () => {
    const active = { ...candidate, provisioning_status: 'active', can_provision: false, disabled_reason: '该 KOL 已完成 Provision' }
    const inProgress = { ...candidate, kol_id: 10, display_name: 'In Progress KOL', provisioning_status: 'in_progress', can_provision: false, disabled_reason: 'Provision 处理中，请刷新状态后再操作' }
    const wrapper = mount(Index, {
      props: props({ canManageAffiliates: true, provisioningCandidates: [active, inProgress] }),
    })
    const activeButton = wrapper.findAll('button').find((item) => item.text() === '已激活')!
    const progressButton = wrapper.findAll('button').find((item) => item.text() === '处理中…')!
    expect(activeButton.attributes('disabled')).toBeDefined()
    expect(progressButton.attributes('disabled')).toBeDefined()
  })
})
