<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { isCopyableFeedback, type GlobalFeedback } from '../../lib/globalFeedback'

const props = defineProps<{ feedback: GlobalFeedback }>()
const emit = defineEmits<{ copy: [] }>()

type IslandState = 'IDLE' | 'COMPACT' | 'EXPANDED'
const presentation = ref<IslandState>('IDLE')
let retractTimer: ReturnType<typeof setTimeout> | undefined

const copyable = computed(() => isCopyableFeedback(props.feedback))
const feedbackKey = computed(() => [
    props.feedback.state,
    props.feedback.title,
    props.feedback.message,
    props.feedback.next ?? '',
].join('\u0000'))

const clearRetractTimer = () => {
    if (retractTimer) clearTimeout(retractTimer)
    retractTimer = undefined
}

const expand = () => {
    clearRetractTimer()
    presentation.value = 'EXPANDED'
}

const handleClick = () => {
    if (presentation.value !== 'EXPANDED') {
        expand()
        return
    }
    if (copyable.value) emit('copy')
}

watch(feedbackKey, () => {
    clearRetractTimer()
    if (props.feedback.state === 'IDLE') {
        presentation.value = 'IDLE'
        return
    }
    presentation.value = 'EXPANDED'
    retractTimer = setTimeout(() => {
        presentation.value = 'COMPACT'
        retractTimer = undefined
    }, 3000)
}, { immediate: true })

onBeforeUnmount(clearRetractTimer)
</script>

<template>
  <div
    class="global-feedback-island-shell"
    :class="`state-${presentation.toLowerCase()}`"
    data-testid="global-feedback-island"
    :data-island-state="presentation.toLowerCase()"
    :data-feedback-state="feedback.state.toLowerCase()"
    role="status"
  >
    <button
      v-if="presentation === 'EXPANDED'"
      type="button"
      :class="['feedback-expanded', feedback.state.toLowerCase(), { copyable }]"
      :aria-live="feedback.state === 'ERROR' || feedback.state === 'BLOCKED' ? 'assertive' : 'polite'"
      :aria-label="copyable ? '复制完整提醒内容' : undefined"
      :disabled="!copyable"
      @click="handleClick"
    >
      <span class="feedback-icon" aria-hidden="true">{{ feedback.state === 'ERROR' ? '!' : feedback.state === 'SUCCESS' ? '✓' : feedback.state === 'BLOCKED' ? '⚠' : '•' }}</span>
      <span class="feedback-copy">
        <strong>{{ feedback.title }}</strong>
        <span v-if="feedback.message">{{ feedback.message }}</span>
        <span v-if="feedback.next" class="feedback-next">下一步：{{ feedback.next }}</span>
      </span>
    </button>
    <button v-else type="button" class="feedback-compact" aria-label="展开全局提醒" @click="expand">
      <span class="feedback-icon" aria-hidden="true">{{ feedback.state === 'IDLE' ? '•' : feedback.state === 'ERROR' ? '!' : feedback.state === 'SUCCESS' ? '✓' : '•' }}</span>
      <span>{{ feedback.state === 'IDLE' ? '全局提醒' : feedback.title || '全局提醒' }}</span>
    </button>
  </div>
</template>

<style scoped>
.global-feedback-island-shell {
  position: fixed;
  top: 8px;
  left: 50%;
  z-index: 60;
  transform: translateX(-50%);
  pointer-events: none;
  font-family: var(--byl-font-sans);
  color: var(--byl-text);
  background: rgba(248, 249, 251, 0.30);
  backdrop-filter: blur(5px) saturate(115%);
  -webkit-backdrop-filter: blur(5px) saturate(115%);
  border: 1px solid rgba(255, 255, 255, 0.45);
  box-shadow: 0 4px 18px rgba(15, 23, 42, 0.07), inset 0 1px 0 rgba(255, 255, 255, 0.28);
  opacity: 1;
}
.global-feedback-island-shell button {
  pointer-events: auto;
  font: inherit;
  color: inherit;
  background: transparent;
  border: 0;
  box-shadow: none;
}
.global-feedback-island-shell button.copyable { cursor: pointer; }
.global-feedback-island-shell button:focus-visible {
  outline: 2px solid var(--byl-focus-ring);
  outline-offset: 2px;
}
.feedback-compact {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 92px;
  max-width: min(300px, calc(100vw - 24px));
  height: 24px;
  padding: 0 10px;
  border-radius: 999px;
  cursor: pointer;
  font-size: 10px;
  white-space: nowrap;
  overflow: hidden;
}
.feedback-compact > span:last-child {
  overflow: hidden;
  text-overflow: ellipsis;
}
.state-idle .feedback-compact { color: var(--byl-text-muted); }
.state-idle, .state-compact { border-radius: 999px; }
.state-expanded { border-radius: var(--byl-radius-lg); }
.feedback-expanded {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  width: min(440px, calc(100vw - 24px));
  padding: 11px 14px;
  border-radius: var(--byl-radius-lg);
  text-align: left;
}
.feedback-icon {
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #73808b;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
}
.feedback-compact .feedback-icon { width: 10px; height: 10px; font-size: 8px; }
.feedback-expanded.running .feedback-icon,
.feedback-expanded.submitting .feedback-icon { background: #3976b9; }
.feedback-expanded.success .feedback-icon { background: #3e9b6b; }
.feedback-expanded.error .feedback-icon { background: #c45d42; }
.feedback-expanded.blocked .feedback-icon { background: #b78a31; }
.feedback-copy {
  min-width: 0;
  flex: 1 1 auto;
  background: transparent;
}
.feedback-copy strong,
.feedback-copy span {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.feedback-copy strong {
  font-size: 13px;
  font-weight: var(--byl-font-weight-semibold);
}
.feedback-copy span {
  margin-top: 3px;
  color: var(--byl-text-muted);
  font-size: 11px;
  line-height: 1.45;
}
</style>
