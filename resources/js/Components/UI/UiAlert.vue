<script setup lang="ts">
import { computed, useAttrs } from 'vue'

defineOptions({ inheritAttrs: false })

type UiAlertTone = 'info' | 'success' | 'warning' | 'error'
type UiAlertLive = 'off' | 'polite' | 'assertive'

const props = withDefaults(defineProps<{
    tone?: UiAlertTone
    title?: string
    dismissible?: boolean
    live?: UiAlertLive
}>(), {
    tone: 'info',
    title: undefined,
    dismissible: false,
    live: undefined,
})

const emit = defineEmits<{ dismiss: [] }>()
const attrs = useAttrs()
const effectiveLive = computed<UiAlertLive>(() => props.live ?? (props.tone === 'error' ? 'assertive' : 'polite'))
const callerRole = computed(() => typeof attrs.role === 'string' ? attrs.role : undefined)
const effectiveRole = computed(() => {
    if (effectiveLive.value === 'off') {
        return callerRole.value === 'region' ? 'region' : undefined
    }

    return effectiveLive.value === 'assertive' ? 'alert' : 'status'
})
const filteredAttrs = computed(() => Object.fromEntries(
    Object.entries(attrs).filter(([name]) => name !== 'aria-live' && (name === 'id' || name === 'class' || name === 'style' || name.startsWith('aria-') || name.startsWith('data-'))),
))
const toneLabel = computed(() => ({ info: '信息', success: '成功', warning: '警告', error: '错误' })[props.tone])
</script>

<template>
    <div
        v-bind="filteredAttrs"
        class="ui-alert"
        :class="`ui-alert--${tone}`"
        :role="effectiveRole"
        :aria-live="effectiveLive === 'off' ? undefined : effectiveLive"
    >
        <span class="ui-alert__indicator" aria-hidden="true"></span>
        <span class="ui-alert__sr-only">{{ toneLabel }}：</span>
        <div class="ui-alert__content">
            <strong v-if="title || $slots.title" class="ui-alert__title"><slot name="title">{{ title }}</slot></strong>
            <slot />
        </div>
        <button v-if="dismissible" type="button" class="ui-alert__dismiss" aria-label="关闭提示" @click="emit('dismiss')">×</button>
    </div>
</template>

<style scoped>
.ui-alert {
    display: flex;
    align-items: flex-start;
    gap: var(--byl-space-8);
    border: var(--byl-border-width) solid currentColor;
    border-radius: var(--byl-radius-lg);
    padding: var(--byl-space-12);
    color: var(--byl-accent);
    font-size: var(--byl-font-size-14);
}

.ui-alert--error {
    color: var(--byl-danger);
}

.ui-alert__indicator {
    width: var(--byl-space-8);
    height: var(--byl-space-8);
    flex: 0 0 auto;
    margin-top: var(--byl-space-6);
    border-radius: var(--byl-radius-round);
    background: currentColor;
}

.ui-alert__content {
    flex: 1;
}

.ui-alert__title {
    display: block;
    margin-bottom: var(--byl-space-4);
}

.ui-alert__dismiss {
    border: 0;
    background: transparent;
    color: currentColor;
}

.ui-alert__dismiss:focus-visible {
    outline: var(--byl-focus-width) solid var(--byl-focus-ring);
    outline-offset: var(--byl-focus-offset);
}

.ui-alert__sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
