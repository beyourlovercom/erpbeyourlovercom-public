import { shallowReadonly, shallowRef } from 'vue'

export type GlobalFeedbackState =
    | 'IDLE'
    | 'INFO'
    | 'RUNNING'
    | 'SUBMITTING'
    | 'SUCCESS'
    | 'BLOCKED'
    | 'ERROR'

export type GlobalFeedback = {
    state: GlobalFeedbackState
    title: string
    message: string
    next?: string
    copyable?: boolean
}

const feedback = shallowRef<GlobalFeedback>({ state: 'IDLE', title: '', message: '' })
let restoreTimer: ReturnType<typeof setTimeout> | undefined

export const globalFeedback = shallowReadonly(feedback)

export function isCopyableFeedback(value: GlobalFeedback) {
    return value.state !== 'IDLE' && value.copyable !== false
}

export function canonicalFeedbackCopyText(value: GlobalFeedback) {
    return [value.title, value.message, value.next ? `下一步：${value.next}` : '']
        .filter(Boolean)
        .join('\n')
}

export function publishGlobalFeedback(value: GlobalFeedback) {
    if (restoreTimer) clearTimeout(restoreTimer)
    restoreTimer = undefined
    feedback.value = value
}

export function scheduleGlobalFeedbackRestore(
    overlay: GlobalFeedback,
    fallback: GlobalFeedback,
    delay = 1500,
) {
    if (restoreTimer) clearTimeout(restoreTimer)
    restoreTimer = setTimeout(() => {
        if (feedback.value === overlay) feedback.value = fallback
        restoreTimer = undefined
    }, delay)
}

export function copySuccessFeedback(): GlobalFeedback {
    return {
        state: 'SUCCESS',
        title: '复制成功',
        message: '完整提醒内容已复制到剪贴板',
        copyable: false,
    }
}

export function copyFailureFeedback(reason: unknown): GlobalFeedback {
    const raw = reason instanceof Error ? reason.message : typeof reason === 'string' ? reason : 'CLIPBOARD_WRITE_FAILED'
    const message = raw.replace(/[\r\n]+/g, ' ').trim().slice(0, 160) || 'CLIPBOARD_WRITE_FAILED'
    return { state: 'ERROR', title: '复制失败', message, copyable: true }
}

export type GlobalFeedbackFlash = {
    feedback_id?: string | null
    success?: string | null
    error?: string | null
    warning?: string | null
    info?: string | null
}

export function feedbackFromFlash(flash?: GlobalFeedbackFlash | null): GlobalFeedback | null {
    if (!flash) return null
    if (flash.error) return { state: 'ERROR', title: flash.error, message: '' }
    if (flash.warning) return { state: 'BLOCKED', title: flash.warning, message: '' }
    if (flash.success) return { state: 'SUCCESS', title: flash.success, message: '' }
    if (flash.info) return { state: 'INFO', title: flash.info, message: '' }
    return null
}
