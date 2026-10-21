<script setup lang="ts">
import { computed, useAttrs } from 'vue'

defineOptions({ inheritAttrs: false })

type UiButtonTone = 'primary' | 'secondary' | 'quiet'
type UiButtonSize = 'sm' | 'md'

const props = withDefaults(defineProps<{
    type?: 'button' | 'submit' | 'reset'
    tone?: UiButtonTone
    size?: UiButtonSize
    loading?: boolean
    disabled?: boolean
}>(), {
    type: 'button',
    tone: 'secondary',
    size: 'md',
    loading: false,
    disabled: false,
})

const emit = defineEmits<{ click: [event: MouseEvent] }>()
const attrs = useAttrs()
const unavailable = computed(() => props.disabled || props.loading)
const allowedAttributeNames = new Set(['id', 'name', 'value', 'form', 'autofocus', 'class', 'style'])
const filteredAttrs = computed(() => Object.fromEntries(
    Object.entries(attrs).filter(([name]) => allowedAttributeNames.has(name) || name.startsWith('aria-') || name.startsWith('data-')),
))

const activate = (event: MouseEvent) => {
    if (unavailable.value) {
        event.preventDefault()
        event.stopImmediatePropagation()
        return
    }

    emit('click', event)
}
</script>

<template>
    <button
        v-bind="filteredAttrs"
        class="ui-button"
        :class="[`ui-button--${tone}`, `ui-button--${size}`]"
        :type="type"
        :disabled="unavailable"
        :aria-disabled="unavailable ? 'true' : undefined"
        :aria-busy="loading ? 'true' : undefined"
        @click="activate"
    >
        <slot v-if="!loading" />
        <slot v-else name="loading">处理中…</slot>
    </button>
</template>

<style scoped>
.ui-button {
    display: inline-flex;
    min-height: var(--byl-control-min-height);
    align-items: center;
    justify-content: center;
    border: var(--byl-border-width) solid transparent;
    border-radius: var(--byl-radius-lg);
    font-family: var(--byl-font-sans);
    font-weight: var(--byl-font-weight-semibold);
    transition: background-color var(--byl-motion-fast) var(--byl-motion-ease), border-color var(--byl-motion-fast) var(--byl-motion-ease), color var(--byl-motion-fast) var(--byl-motion-ease), opacity var(--byl-motion-fast) var(--byl-motion-ease);
}

.ui-button:focus-visible {
    outline: var(--byl-focus-width) solid var(--byl-focus-ring);
    outline-offset: var(--byl-focus-offset);
}

.ui-button--sm {
    padding: 0 var(--byl-space-10);
    font-size: var(--byl-font-size-12);
}

.ui-button--md {
    padding: var(--byl-space-10) var(--byl-space-16);
    font-size: var(--byl-font-size-14);
}

.ui-button--primary {
    background: var(--byl-accent);
    color: var(--byl-accent-ink);
}

.ui-button--primary:hover:not(:disabled) {
    background: var(--byl-accent-hover);
}

.ui-button--secondary {
    border-color: var(--byl-border-control);
    background: var(--byl-surface);
    color: var(--byl-text);
}

.ui-button--secondary:hover:not(:disabled),
.ui-button--quiet:hover:not(:disabled) {
    color: var(--byl-accent);
}

.ui-button--quiet {
    background: transparent;
    color: var(--byl-text-muted);
}

.ui-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
