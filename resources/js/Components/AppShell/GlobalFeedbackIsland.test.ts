import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import GlobalFeedbackIsland from './GlobalFeedbackIsland.vue'

const source = readFileSync(resolve(process.cwd(), 'resources/js/Components/AppShell/GlobalFeedbackIsland.vue'), 'utf8')

describe('global feedback island', () => {
  afterEach(() => vi.useRealTimers())

  it('uses exactly idle compact expanded presentation states and the frozen AICC VI', () => {
    expect(source).toContain("type IslandState = 'IDLE' | 'COMPACT' | 'EXPANDED'")
    expect(source).toContain('top: 8px;')
    expect(source).toContain('left: 50%;')
    expect(source).toContain('z-index: 60;')
    expect(source).toContain('transform: translateX(-50%);')
    expect(source).toContain('background: rgba(248, 249, 251, 0.30);')
    expect(source).toContain('backdrop-filter: blur(5px) saturate(115%);')
    expect(source).toContain('border: 1px solid rgba(255, 255, 255, 0.45);')
    expect(source).not.toContain('opacity: 0.30')
  })

  it('auto expands new feedback, retracts after three seconds, and re-expands on click', async () => {
    vi.useFakeTimers()
    const wrapper = mount(GlobalFeedbackIsland, {
      props: { feedback: { state: 'SUCCESS', title: '订单已更新', message: '' } },
    })
    expect(wrapper.attributes('data-island-state')).toBe('expanded')
    await vi.advanceTimersByTimeAsync(3000)
    expect(wrapper.attributes('data-island-state')).toBe('compact')
    await wrapper.get('.feedback-compact').trigger('click')
    expect(wrapper.attributes('data-island-state')).toBe('expanded')
  })


  it('is mounted exactly once by the authenticated shell', () => {
    const layout = readFileSync(resolve(process.cwd(), 'resources/js/Layouts/AuthenticatedLayout.vue'), 'utf8')
    expect((layout.match(/<GlobalFeedbackIsland/g) ?? []).length).toBe(1)
    expect(layout).not.toMatch(/toast|snackbar/i)
  })

  it('renders the optional next action in expanded feedback', () => {
    const wrapper = mount(GlobalFeedbackIsland, {
      props: { feedback: { state: 'BLOCKED', title: '订单无法创建', message: '请先选择订单商品', next: '选择 SKU / 产品后重新提交' } },
    })
    expect(wrapper.get('.feedback-expanded').text()).toContain('下一步：选择 SKU / 产品后重新提交')
  })

  it('emits copy only from expanded copyable feedback', async () => {
    const wrapper = mount(GlobalFeedbackIsland, {
      props: { feedback: { state: 'ERROR', title: '保存失败', message: 'network error' } },
    })
    await wrapper.get('.feedback-expanded').trigger('click')
    expect(wrapper.emitted('copy')).toHaveLength(1)
    await wrapper.setProps({ feedback: { state: 'SUCCESS', title: '复制成功', message: '已复制', copyable: false } })
    expect(wrapper.get('.feedback-expanded').attributes('disabled')).toBeDefined()
  })
})
