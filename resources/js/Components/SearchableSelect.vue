<script setup lang="ts">
import { computed, ref, watch } from 'vue'

type Option = { value: string; label: string }
const props = defineProps<{ modelValue: string; options: Option[]; placeholder?: string }>()
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()
const open = ref(false)
const query = ref('')
const selectedLabel = computed(() => props.options.find(o => o.value === props.modelValue)?.label ?? props.modelValue)
watch(() => props.modelValue, () => { if (!open.value) query.value = selectedLabel.value }, { immediate: true })
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q || q === selectedLabel.value.toLowerCase()) return props.options
  return props.options.filter(o => `${o.label} ${o.value}`.toLowerCase().includes(q))
})
const choose = (option: Option) => { emit('update:modelValue', option.value); query.value = option.label; open.value = false }
const onFocus = () => { query.value = ''; open.value = true }
const onBlur = () => setTimeout(() => { open.value = false; query.value = selectedLabel.value }, 120)
</script>

<template>
  <div class="searchable-select">
    <input v-model="query" type="text" class="erp-input" :placeholder="placeholder" autocomplete="off" @focus="onFocus" @input="open = true" @blur="onBlur">
    <div v-if="open" class="searchable-select-menu">
      <button v-for="option in filtered" :key="option.value" type="button" class="searchable-select-option" @mousedown.prevent="choose(option)">
        <span>{{ option.label }}</span><small>{{ option.value }}</small>
      </button>
      <div v-if="filtered.length === 0" class="searchable-select-empty">无匹配选项</div>
    </div>
  </div>
</template>

<style scoped>
.searchable-select{position:relative}.searchable-select-menu{position:absolute;z-index:40;top:calc(100% + 4px);left:0;right:0;max-height:260px;overflow:auto;background:#fff;border:1px solid #d8e0e4;border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.12);padding:6px}.searchable-select-option{width:100%;display:flex;justify-content:space-between;gap:12px;border:0;background:transparent;padding:9px 10px;border-radius:7px;text-align:left;cursor:pointer}.searchable-select-option:hover{background:#f1f5f9}.searchable-select-option small{color:#64748b}.searchable-select-empty{padding:10px;color:#64748b;font-size:13px}
</style>
