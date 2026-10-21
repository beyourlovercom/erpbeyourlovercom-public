<script setup lang="ts">
import { computed, useAttrs } from 'vue'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
    title: string
    description?: string
    headingLevel?: 2 | 3
    eyebrow?: string
}>(), {
    description: undefined,
    headingLevel: 2,
    eyebrow: undefined,
})

const attrs = useAttrs()
const filteredAttrs = computed(() => Object.fromEntries(
    Object.entries(attrs).filter(([name]) => name === 'id' || name === 'aria-labelledby' || name === 'class' || name === 'style' || name.startsWith('data-')),
))
const headingTag = computed(() => `h${props.headingLevel}` as 'h2' | 'h3')
const headingId = computed(() => typeof attrs['aria-labelledby'] === 'string' ? attrs['aria-labelledby'] : undefined)
</script>

<template>
    <section v-bind="filteredAttrs" class="empty-state">
        <div v-if="$slots.icon" class="empty-state__icon"><slot name="icon" /></div>
        <p v-if="eyebrow" class="empty-state__eyebrow">{{ eyebrow }}</p>
        <component :is="headingTag" :id="headingId" class="empty-state__title">{{ title }}</component>
        <div v-if="description || $slots.default" class="empty-state__description"><slot>{{ description }}</slot></div>
        <div v-if="$slots.actions" class="empty-state__actions"><slot name="actions" /></div>
    </section>
</template>

<style scoped>
.empty-state {
    max-width: var(--byl-content-reading-width);
    border: var(--byl-border-width) solid var(--byl-border-subtle);
    border-radius: var(--byl-radius-xl);
    background: var(--byl-surface);
    padding: var(--byl-space-30);
    box-shadow: var(--byl-shadow-card);
    color: var(--byl-text);
}

.empty-state__eyebrow {
    color: var(--byl-accent);
    font-size: var(--byl-font-size-10);
    font-weight: var(--byl-font-weight-bold);
    letter-spacing: 0.16em;
}

.empty-state__title {
    margin: var(--byl-space-10) 0;
    font-size: var(--byl-font-size-24);
}

.empty-state__description {
    color: var(--byl-text-secondary);
    line-height: var(--byl-line-copy);
}

.empty-state__actions {
    margin-top: var(--byl-space-20);
}
</style>
