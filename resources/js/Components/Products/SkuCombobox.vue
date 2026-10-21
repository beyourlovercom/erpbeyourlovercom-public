<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'

type SkuOption = { id: number; sku: string; name: string }

const props = defineProps<{ modelValue: string; productId?: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [string]; 'update:productId': [number | null] }>()

const inputValue = ref(props.modelValue)
const options = ref<SkuOption[]>([])
const open = ref(false)
const loading = ref(false)
const errorMessage = ref('')
const activeIndex = ref(-1)
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let blurTimer: ReturnType<typeof setTimeout> | undefined
let requestSequence = 0
let mounted = true

onUnmounted(() => {
  mounted = false
  requestSequence += 1
  clearTimeout(debounceTimer)
  clearTimeout(blurTimer)
})

watch(() => props.modelValue, (value) => { inputValue.value = value })

const fetchOptions = async (query: string) => {
  const sequence = ++requestSequence
  loading.value = true
  errorMessage.value = ''
  open.value = true
  try {
    const response = await fetch(`/products/sku-search?q=${encodeURIComponent(query.trim())}`, {
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) {
      throw new Error(response.status === 403
        ? '没有 SKU 查询权限，请联系管理员'
        : response.status === 401 ? '登录已失效，请重新登录' : 'SKU 查询暂时失败，请重试')
    }
    const payload: unknown = await response.json()
    if (!Array.isArray(payload)) throw new Error('SKU 查询返回了无效数据')
    if (!mounted || sequence !== requestSequence) return
    options.value = payload
    activeIndex.value = options.value.length > 0 ? 0 : -1
  } catch (error) {
    if (!mounted || sequence !== requestSequence) return
    options.value = []
    activeIndex.value = -1
    const message = error instanceof Error ? error.message : ''
    errorMessage.value = ['SKU 查询', '没有 SKU', '登录已'].some(prefix => message.startsWith(prefix))
      ? message : 'SKU 查询暂时失败，请重试'
  } finally {
    if (mounted && sequence === requestSequence) loading.value = false
  }
}

const scheduleFetch = (query: string) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => fetchOptions(query), 180)
}

const onFocus = () => {
  clearTimeout(blurTimer)
  open.value = true
  void fetchOptions(inputValue.value)
}

const onInput = (event: Event) => {
  const value = (event.target as HTMLInputElement).value
  inputValue.value = value
  emit('update:modelValue', value)
  emit('update:productId', null)
  open.value = true
  activeIndex.value = -1
  scheduleFetch(value)
}

const select = (option: SkuOption) => {
  inputValue.value = option.sku
  emit('update:modelValue', option.sku)
  emit('update:productId', option.id)
  open.value = false
  options.value = []
  activeIndex.value = -1
}

const onBlur = () => {
  blurTimer = setTimeout(() => { open.value = false }, 150)
}

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    open.value = false
    return
  }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    if (!open.value) {
      open.value = true
      void fetchOptions(inputValue.value)
      return
    }
    if (options.value.length === 0) return
    const delta = event.key === 'ArrowDown' ? 1 : -1
    activeIndex.value = (activeIndex.value + delta + options.value.length) % options.value.length
    return
  }
  if (event.key === 'Enter' && open.value && activeIndex.value >= 0) {
    const option = options.value[activeIndex.value]
    if (option) {
      event.preventDefault()
      select(option)
    }
  }
}
</script>

<template>
  <div class="sku-combobox">
    <div class="sku-combobox-control">
      <input
        :value="inputValue"
        class="erp-input sku-combobox-input"
        type="text"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="open"
        placeholder="搜索 SKU 或产品名称…"
        autocomplete="off"
        @focus="onFocus"
        @input="onInput"
        @keydown="onKeydown"
        @blur="onBlur"
      >
      <span class="sku-combobox-chevron" aria-hidden="true">⌄</span>
    </div>
    <ul v-if="open" class="sku-combobox-dropdown" role="listbox">
      <li v-if="loading" class="sku-combobox-state" aria-live="polite">正在加载产品…</li>
      <li v-else-if="errorMessage" class="sku-combobox-state" role="alert">{{ errorMessage }}</li>
      <li v-else-if="options.length === 0" class="sku-combobox-state">暂无可选产品</li>
      <template v-else>
        <li
          v-for="(option, index) in options"
          :key="option.id"
          class="sku-combobox-option"
          :class="{ active: index === activeIndex }"
          role="option"
          :aria-selected="index === activeIndex"
          @mouseenter="activeIndex = index"
          @mousedown.prevent="select(option)"
        >
          <strong>{{ option.sku }}</strong>
          <span>{{ option.name }}</span>
        </li>
      </template>
    </ul>
  </div>
</template>

<style scoped>
.sku-combobox {
  position: relative;
  width: 100%;
}

.sku-combobox-control {
  position: relative;
}

.sku-combobox-input {
  width: 100%;
  padding-right: 36px;
}

.sku-combobox-chevron {
  position: absolute;
  top: 50%;
  right: 12px;
  transform: translateY(-54%);
  color: var(--byl-text-muted);
  font-size: 16px;
  pointer-events: none;
}

.sku-combobox-dropdown {
  position: absolute;
  z-index: 80;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  max-height: 320px;
  margin: 0;
  padding: 6px;
  overflow-y: auto;
  list-style: none;
  border: var(--byl-border-width) solid var(--byl-border-subtle);
  border-radius: var(--byl-radius-lg);
  background: var(--byl-surface);
  box-shadow: 0 14px 36px rgba(15, 23, 42, 0.14);
}

.sku-combobox-option {
  display: grid;
  gap: 2px;
  min-height: 54px;
  padding: 9px 11px;
  border-radius: var(--byl-radius-md);
  cursor: pointer;
}

.sku-combobox-option strong {
  color: var(--byl-text);
  font-size: 13px;
  line-height: 1.3;
}

.sku-combobox-option span,
.sku-combobox-state {
  color: var(--byl-text-muted);
  font-size: 12px;
  line-height: 1.4;
}

.sku-combobox-option.active {
  background: var(--byl-readonly-bg);
}

.sku-combobox-state {
  padding: 13px 11px;
}
</style>
