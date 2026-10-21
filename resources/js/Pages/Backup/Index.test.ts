import { mount } from '@vue/test-utils'
import { router } from '@inertiajs/vue3'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' }, router: { put: vi.fn() } }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const props = {
  actions: [
    { key: 'create', label: '立即备份', available: false },
    { key: 'download', label: '下载备份', available: false },
    { key: 'encrypted_download', label: '加密并下载', available: false },
    { key: 'publish_github', label: '加密并发布到 GitHub', available: false },
  ],
  history: [], storageUsage: null,
  policy: { format: 'backup-format-v1', includedRoots: ['storage/app/private', 'storage/app/public'], workspace: 'storage/app/backups', status: '备份引擎已接入；手动操作入口暂未启用' },
  automaticBackup: {
    enabled: false, cadence: 'daily' as const, canManage: false, retentionCount: 5,
    options: [
      { value: 'daily' as const, label: '每天', detail: '每天 02:00 UTC' },
      { value: 'weekly' as const, label: '每周', detail: '每周一 02:00 UTC' },
      { value: 'monthly' as const, label: '每月', detail: '每月 1 日 02:00 UTC' },
    ],
  },
  restore: { local: { available: false, status: '恢复基础已就绪，操作入口尚未启用' }, github: { available: false, status: 'GitHub 恢复基础已就绪，操作入口尚未启用' } },
}

describe('Backup automatic policy', () => {
  it('shows the five-backup policy and keeps controls read-only without manage permission', () => {
    const wrapper = mount(Index, { props })
    for (const label of ['自动备份', '立即备份', '最近备份', '存储使用情况', '备份策略', '本地恢复', 'GitHub 恢复']) expect(wrapper.text()).toContain(label)
    expect(wrapper.text()).toContain('最多保留 5 份')
    expect(wrapper.text()).toContain('每天 02:00 UTC')
    expect(wrapper.text()).toContain('开启自动备份')
    expect(wrapper.find('.toggle-button').attributes('disabled')).toBeDefined()
    expect(wrapper.find('select').attributes('disabled')).toBeDefined()
  })

  it('saves one-click enable and cadence changes for a manager', async () => {
    vi.mocked(router.put).mockClear()
    const toggleWrapper = mount(Index, { props: { ...props, automaticBackup: { ...props.automaticBackup, canManage: true } } })
    await toggleWrapper.find('.toggle-button').trigger('click')
    expect(router.put).toHaveBeenCalledWith('/settings/backup/automatic', { enabled: true, cadence: 'daily' }, expect.any(Object))

    vi.mocked(router.put).mockClear()
    const cadenceWrapper = mount(Index, { props: { ...props, automaticBackup: { ...props.automaticBackup, canManage: true } } })
    await cadenceWrapper.find('select').setValue('weekly')
    expect(router.put).toHaveBeenCalledWith('/settings/backup/automatic', { enabled: false, cadence: 'weekly' }, expect.any(Object))
  })
})
