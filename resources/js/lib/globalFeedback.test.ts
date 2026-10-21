import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  canonicalFeedbackCopyText,
  copyFailureFeedback,
  copySuccessFeedback,
  feedbackFromFlash,
  globalFeedback,
  publishGlobalFeedback,
  scheduleGlobalFeedbackRestore,
} from './globalFeedback'

describe('global feedback authority', () => {
  afterEach(() => {
    vi.useRealTimers()
    publishGlobalFeedback({ state: 'IDLE', title: '', message: '' })
  })

  it('copies canonical state rather than rendered or truncated text', () => {
    const long = 'full-canonical-content-'.repeat(30)
    expect(canonicalFeedbackCopyText({ state: 'ERROR', title: '同步失败', message: long }))
      .toBe(`同步失败\n${long}`)
  })

  it('maps all server flash variants into one canonical feedback model', () => {
    expect(feedbackFromFlash({ success: 'WooCommerce 连接成功' })).toEqual({ state: 'SUCCESS', title: 'WooCommerce 连接成功', message: '' })
    expect(feedbackFromFlash({ error: '连接失败' })?.state).toBe('ERROR')
    expect(feedbackFromFlash({ warning: '需要确认' })?.state).toBe('BLOCKED')
    expect(feedbackFromFlash({ info: '已刷新' })?.state).toBe('INFO')
  })

  it('uses a non-copyable copy-success overlay and restores the prior feedback', async () => {
    vi.useFakeTimers()
    const original = { state: 'RUNNING' as const, title: '正在同步', message: 'WooCommerce' }
    const overlay = copySuccessFeedback()
    publishGlobalFeedback(original)
    publishGlobalFeedback(overlay)
    scheduleGlobalFeedbackRestore(overlay, original)
    expect(globalFeedback.value).toBe(overlay)
    expect(overlay.copyable).toBe(false)
    await vi.advanceTimersByTimeAsync(1500)
    expect(globalFeedback.value).toBe(original)
  })

  it('normalizes clipboard failures into copyable global error feedback', () => {
    expect(copyFailureFeedback(new Error('clipboard denied'))).toEqual({
      state: 'ERROR', title: '复制失败', message: 'clipboard denied', copyable: true,
    })
  })
})
