<script setup lang="ts">
import { ref } from 'vue'
import UiButton from '../../Components/UI/UiButton.vue'

const props = defineProps<{ title: string; status: string }>()
const acknowledged = ref(false)
</script>

<template>
    <main data-byl-theme="dark" class="foundation-page mx-auto flex min-h-screen max-w-3xl items-center px-6 py-16">
        <section class="foundation-card w-full rounded-2xl border p-8">
            <p class="foundation-status text-xs font-semibold uppercase tracking-[0.24em]">{{ props.status }}</p>
            <h1 class="mt-4 text-3xl font-semibold tracking-tight">{{ props.title }}</h1>
            <p class="foundation-copy mt-4 max-w-xl text-sm leading-7">Inertia.js、Vue 3、TypeScript 与 Tailwind CSS foundation validation page。</p>
            <UiButton class="foundation-action mt-8" tone="secondary" @click="acknowledged = !acknowledged">
                {{ acknowledged ? 'Foundation 已确认' : '确认基础可用' }}
            </UiButton>
        </section>
    </main>
</template>

<style scoped>
.foundation-page {
    background: var(--byl-canvas);
    color: var(--byl-text);
}

.foundation-card {
    border-color: var(--byl-color-foundation-border);
    background: var(--byl-color-foundation-surface);
    box-shadow: var(--byl-shadow-foundation);
}

.foundation-status,
.foundation-action {
    color: var(--byl-accent);
}

.foundation-copy {
    color: var(--byl-text-secondary);
}

.foundation-action {
    border-color: var(--byl-color-foundation-action-border);
    background: transparent;
}

.foundation-action:hover:not(:disabled) {
    background: var(--byl-color-foundation-action-hover);
}
</style>
