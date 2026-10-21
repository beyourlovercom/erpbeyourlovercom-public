<script setup lang="ts">
import { computed, useAttrs } from 'vue'

defineOptions({ inheritAttrs: false })

type TextInputType = 'text' | 'email' | 'password'

withDefaults(defineProps<{
    id: string
    modelValue: string
    type?: TextInputType
    invalid?: boolean
    disabled?: boolean
    readonly?: boolean
}>(), {
    type: 'text',
    invalid: false,
    disabled: false,
    readonly: false,
})

const emit = defineEmits<{
    'update:modelValue': [value: string]
    input: [event: Event]
    change: [event: Event]
    focus: [event: FocusEvent]
    blur: [event: FocusEvent]
}>()
const attrs = useAttrs()
const allowedAttributeNames = new Set([
    'name',
    'autocomplete',
    'required',
    'placeholder',
    'minlength',
    'maxlength',
    'pattern',
    'inputmode',
    'autocapitalize',
    'spellcheck',
    'class',
    'style',
])
const filteredAttrs = computed(() => Object.fromEntries(
    Object.entries(attrs).filter(([name]) => allowedAttributeNames.has(name) || name.startsWith('aria-') || name.startsWith('data-')),
))

const onInput = (event: Event) => {
    emit('update:modelValue', (event.target as HTMLInputElement).value)
    emit('input', event)
}
</script>

<template>
    <div class="text-input">
        <input
            v-bind="filteredAttrs"
            class="text-input__control"
            :id="id"
            :value="modelValue"
            :type="type"
            :disabled="disabled"
            :readonly="readonly"
            :aria-invalid="invalid ? 'true' : undefined"
            @input="onInput"
            @change="emit('change', $event)"
            @focus="emit('focus', $event)"
            @blur="emit('blur', $event)"
        >
        <slot name="trailing" />
    </div>
</template>

<style scoped>
.text-input {
    display: flex;
    align-items: stretch;
    overflow: hidden;
    border: var(--byl-border-width) solid var(--byl-border-control);
    border-radius: var(--byl-radius-lg);
    background: var(--byl-surface);
    transition: border-color var(--byl-motion-fast) var(--byl-motion-ease), outline-color var(--byl-motion-fast) var(--byl-motion-ease);
}

.text-input:focus-within {
    border-color: var(--byl-focus-ring);
    outline: var(--byl-focus-width) solid var(--byl-focus-ring);
    outline-offset: var(--byl-focus-offset);
}

.text-input__control {
    min-width: 0;
    flex: 1;
    border: 0;
    background: transparent;
    padding: var(--byl-space-12);
    color: var(--byl-text);
    font-family: var(--byl-font-sans);
    font-size: var(--byl-font-size-14);
    outline: none;
}

.text-input__control:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
